<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EnviarEtapaJob;
use App\Jobs\VerificarCondicionJob;
use App\Models\Envio;
use App\Models\Flujo;
use App\Models\FlujoCondicion;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\CondicionEvaluatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Tests para VerificarCondicionJob con filtrado por prospecto individual.
 * 
 * El nuevo sistema evalúa CADA prospecto individualmente y los separa en ramas Sí/No.
 */
class VerificarCondicionJobTest extends TestCase
{
    use RefreshDatabase;

    private Flujo $flujo;
    private FlujoEjecucion $ejecucion;
    private FlujoEjecucionEtapa $etapaEjecucion;
    private FlujoCondicion $condicion;
    private array $prospectos = [];
    private array $prospectosEnFlujo = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create required related models
        $user = User::factory()->create();
        $tipoProspecto = TipoProspecto::factory()->create();

        // Create flujo first (needed for ProspectoEnFlujo)
        $this->flujo = Flujo::factory()->create([
            'user_id' => $user->id,
            'tipo_prospecto_id' => $tipoProspecto->id,
            'config_structure' => [
                'stages' => [
                    ['id' => 'stage-1', 'type' => 'email', 'label' => 'Email Inicial', 'tiempo_espera' => 0],
                    ['id' => 'stage-yes', 'type' => 'email', 'label' => 'Email Seguimiento', 'tiempo_espera' => 1],
                    ['id' => 'stage-no', 'type' => 'email', 'label' => 'Email Recordatorio', 'tiempo_espera' => 2],
                ],
                'conditions' => [
                    ['id' => 'conditional-1', 'type' => 'condition', 'label' => '¿Abrió email?'],
                ],
                'branches' => [
                    ['edge_id' => 'edge-1', 'source_node_id' => 'stage-1', 'target_node_id' => 'conditional-1', 'source_handle' => null],
                    ['edge_id' => 'edge-2', 'source_node_id' => 'conditional-1', 'target_node_id' => 'stage-yes', 'source_handle' => 'conditional-1-yes'],
                    ['edge_id' => 'edge-3', 'source_node_id' => 'conditional-1', 'target_node_id' => 'stage-no', 'source_handle' => 'conditional-1-no'],
                ],
            ],
        ]);

        // Create 5 test prospects with ProspectoEnFlujo
        for ($i = 1; $i <= 5; $i++) {
            $prospecto = Prospecto::factory()->create([
                'tipo_prospecto_id' => $tipoProspecto->id,
                'email' => "prospecto{$i}@test.com",
            ]);
            $this->prospectos[] = $prospecto;
            
            // Create ProspectoEnFlujo for each prospect
            $this->prospectosEnFlujo[] = ProspectoEnFlujo::create([
                'prospecto_id' => $prospecto->id,
                'flujo_id' => $this->flujo->id,
                'canal_asignado' => 'email',
                'estado' => 'en_proceso',
                'fecha_inicio' => now(),
            ]);
        }

        $prospectoIds = array_map(fn($p) => $p->id, $this->prospectos);

        $this->ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
            'prospectos_ids' => $prospectoIds,
        ]);

        $this->etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'completed',
            'ejecutado' => true,
            'message_id' => 12345,
        ]);

        // Create condition in database
        $this->condicion = FlujoCondicion::create([
            'id' => 'conditional-1',
            'flujo_id' => $this->flujo->id,
            'label' => '¿Abrió el email?',
            'description' => 'Verifica si el prospecto abrió el email',
            'condition_type' => 'email_opened',
            'condition_label' => 'Email abierto',
            'yes_label' => 'Sí abrió',
            'no_label' => 'No abrió',
            'check_param' => 'Views',
            'check_operator' => '>',
            'check_value' => '0',
        ]);
    }

    // ============================================
    // Tests de filtrado por prospecto individual
    // ============================================

    /**
     * @test
     */
    public function todos_los_prospectos_abrieron_van_a_rama_si(): void
    {
        Bus::fake([EnviarEtapaJob::class]);

        // Simular que TODOS abrieron el email
        foreach ($this->prospectos as $prospecto) {
            $this->crearEnvioParaProspecto($prospecto->id, abierto: true);
        }

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(CondicionEvaluatorService::class));

        // Verificar que TODOS van a rama Sí
        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'condition_node_id' => 'conditional-1',
            'total_evaluados' => 5,
            'total_rama_si' => 5,
            'total_rama_no' => 0,
        ]);

        // Verificar que se programó la rama YES con TODOS los prospectos
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-yes',
            'estado' => 'pending',
        ]);

        // Verificar que NO se programó rama NO (no hay prospectos)
        $this->assertDatabaseMissing('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-no',
        ]);

        Bus::assertDispatched(EnviarEtapaJob::class, 1);
    }

    /**
     * @test
     */
    public function ningun_prospecto_abrio_van_a_rama_no(): void
    {
        Bus::fake([EnviarEtapaJob::class]);

        // Simular que NADIE abrió el email
        foreach ($this->prospectos as $prospecto) {
            $this->crearEnvioParaProspecto($prospecto->id, abierto: false);
        }

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(CondicionEvaluatorService::class));

        // Verificar que TODOS van a rama No
        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'condition_node_id' => 'conditional-1',
            'total_evaluados' => 5,
            'total_rama_si' => 0,
            'total_rama_no' => 5,
        ]);

        // Verificar que se programó la rama NO con TODOS los prospectos
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-no',
            'estado' => 'pending',
        ]);

        // Verificar que NO se programó rama SI (no hay prospectos)
        $this->assertDatabaseMissing('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-yes',
        ]);

        Bus::assertDispatched(EnviarEtapaJob::class, 1);
    }

    /**
     * @test
     */
    public function prospectos_mixtos_se_separan_en_ambas_ramas(): void
    {
        Bus::fake([EnviarEtapaJob::class]);

        // Simular: 2 abrieron, 3 no abrieron
        foreach ($this->prospectos as $i => $prospecto) {
            $this->crearEnvioParaProspecto($prospecto->id, abierto: $i < 2);
        }

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(CondicionEvaluatorService::class));

        // Verificar la separación
        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'condition_node_id' => 'conditional-1',
            'total_evaluados' => 5,
            'total_rama_si' => 2,
            'total_rama_no' => 3,
        ]);

        // Verificar que se programaron AMBAS ramas
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-yes',
            'estado' => 'pending',
        ]);

        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-no',
            'estado' => 'pending',
        ]);

        // Verificar que se despacharon 2 jobs (uno por cada rama)
        Bus::assertDispatched(EnviarEtapaJob::class, 2);
    }

    /**
     * @test
     */
    public function prospectos_sin_envio_van_a_rama_no(): void
    {
        Bus::fake([EnviarEtapaJob::class]);

        // Solo crear envío para 2 prospectos (abrieron)
        // Los otros 3 no tienen envío registrado
        for ($i = 0; $i < 2; $i++) {
            $this->crearEnvioParaProspecto($this->prospectos[$i]->id, abierto: true);
        }

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(CondicionEvaluatorService::class));

        // 2 abrieron (rama sí), 3 sin envío (rama no - comportamiento conservador)
        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'total_rama_si' => 2,
            'total_rama_no' => 3,
        ]);
    }

    /**
     * @test
     */
    public function job_registra_flujo_job_completado(): void
    {
        Bus::fake([EnviarEtapaJob::class]);

        foreach ($this->prospectos as $prospecto) {
            $this->crearEnvioParaProspecto($prospecto->id, abierto: true);
        }

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(CondicionEvaluatorService::class));

        $this->assertDatabaseHas('flujo_jobs', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'job_type' => 'verificar_condicion',
            'estado' => 'completed',
        ]);
    }

    /**
     * @test
     */
    public function job_actualiza_estado_ejecucion(): void
    {
        Bus::fake([EnviarEtapaJob::class]);

        foreach ($this->prospectos as $prospecto) {
            $this->crearEnvioParaProspecto($prospecto->id, abierto: true);
        }

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(CondicionEvaluatorService::class));

        $this->ejecucion->refresh();
        $this->assertEquals('in_progress', $this->ejecucion->estado);
        $this->assertEquals('stage-yes', $this->ejecucion->proximo_nodo);
    }

    /**
     * @test
     */
    public function condicion_clicks_funciona_correctamente(): void
    {
        Bus::fake([EnviarEtapaJob::class]);

        // Cambiar condición a evaluar clicks
        $this->condicion->update([
            'check_param' => 'Clicks',
            'check_operator' => '>',
            'check_value' => '0',
        ]);

        // 2 clickearon, 3 no clickearon
        foreach ($this->prospectos as $i => $prospecto) {
            $this->crearEnvioParaProspecto($prospecto->id, abierto: false, clickeado: $i < 2);
        }

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(CondicionEvaluatorService::class));

        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'total_rama_si' => 2,
            'total_rama_no' => 3,
        ]);
    }

    /**
     * @test
     */
    public function job_recibe_prospectos_filtrados_en_constructor(): void
    {
        Bus::fake([EnviarEtapaJob::class]);

        // Solo pasar 2 prospectos al job (simulando filtrado previo)
        $prospectosFiltrados = [$this->prospectos[0]->id, $this->prospectos[1]->id];

        foreach ($prospectosFiltrados as $prospectoId) {
            $this->crearEnvioParaProspecto($prospectoId, abierto: true);
        }

        $job = new VerificarCondicionJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            condicion: [
                'target_node_id' => 'conditional-1',
                'source_node_id' => 'stage-1',
            ],
            messageId: 12345,
            prospectoIds: $prospectosFiltrados // Solo estos prospectos
        );

        $job->handle(app(CondicionEvaluatorService::class));

        // Solo deben evaluarse 2 prospectos
        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'total_evaluados' => 2,
            'total_rama_si' => 2,
            'total_rama_no' => 0,
        ]);
    }

    /**
     * @test
     */
    public function etapa_programada_contiene_prospectos_ids(): void
    {
        Bus::fake([EnviarEtapaJob::class]);

        // 2 abrieron, 3 no abrieron
        foreach ($this->prospectos as $i => $prospecto) {
            $this->crearEnvioParaProspecto($prospecto->id, abierto: $i < 2);
        }

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(CondicionEvaluatorService::class));

        // Verificar que la etapa YES tiene solo los 2 prospectos que abrieron
        $etapaYes = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->ejecucion->id)
            ->where('node_id', 'stage-yes')
            ->first();
        
        $this->assertNotNull($etapaYes);
        $this->assertCount(2, $etapaYes->prospectos_ids);

        // Verificar que la etapa NO tiene solo los 3 prospectos que NO abrieron
        $etapaNo = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->ejecucion->id)
            ->where('node_id', 'stage-no')
            ->first();
        
        $this->assertNotNull($etapaNo);
        $this->assertCount(3, $etapaNo->prospectos_ids);
    }

    // ============================================
    // Helpers
    // ============================================

    private function createVerificarCondicionJob(): VerificarCondicionJob
    {
        return new VerificarCondicionJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            condicion: [
                'target_node_id' => 'conditional-1',
                'source_node_id' => 'stage-1',
            ],
            messageId: 12345
        );
    }

    /**
     * Helper para crear un envío con todos los campos requeridos
     */
    private function crearEnvioParaProspecto(
        int $prospectoId,
        bool $abierto = false,
        bool $clickeado = false
    ): Envio {
        // Find the ProspectoEnFlujo for this prospect
        $prospectoEnFlujo = collect($this->prospectosEnFlujo)->first(
            fn($pef) => $pef->prospecto_id === $prospectoId
        );

        return Envio::create([
            'prospecto_id' => $prospectoId,
            'flujo_id' => $this->flujo->id,
            'flujo_ejecucion_etapa_id' => $this->etapaEjecucion->id,
            'prospecto_en_flujo_id' => $prospectoEnFlujo->id,
            'contenido_enviado' => 'Test content',
            'destinatario' => "test{$prospectoId}@example.com",
            'canal' => 'email',
            'estado' => 'enviado',  // Valores válidos: pendiente, enviado, fallido, abierto, clickeado
            'fecha_programada' => now()->subDay(),
            'fecha_abierto' => $abierto ? now() : null,
            'fecha_clickeado' => $clickeado ? now() : null,
        ]);
    }
}
