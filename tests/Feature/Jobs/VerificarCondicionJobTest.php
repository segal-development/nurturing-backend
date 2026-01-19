<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EnviarEtapaJob;
use App\Jobs\VerificarCondicionJob;
use App\Models\Flujo;
use App\Models\FlujoCondicion;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\AthenaCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class VerificarCondicionJobTest extends TestCase
{
    use RefreshDatabase;

    private Flujo $flujo;
    private FlujoEjecucion $ejecucion;
    private FlujoEjecucionEtapa $etapaEjecucion;
    private FlujoCondicion $condicion;

    protected function setUp(): void
    {
        parent::setUp();

        // Create required related models
        $user = User::factory()->create();
        $tipoProspecto = TipoProspecto::factory()->create();

        // Create flujo with conditional flow structure
        $this->flujo = Flujo::factory()->create([
            'user_id' => $user->id,
            'tipo_prospecto_id' => $tipoProspecto->id,
            'config_structure' => [
                'stages' => [
                    ['id' => 'stage-1', 'type' => 'email', 'label' => 'Email Inicial', 'tiempo_espera' => 0],
                    ['id' => 'stage-yes', 'type' => 'email', 'label' => 'Email Seguimiento', 'tiempo_espera' => 1],
                    ['id' => 'stage-no', 'type' => 'email', 'label' => 'Email Recordatorio', 'tiempo_espera' => 2],
                ],
                'branches' => [
                    ['edge_id' => 'edge-1', 'source_node_id' => 'stage-1', 'target_node_id' => 'conditional-1', 'source_handle' => null],
                    ['edge_id' => 'edge-2', 'source_node_id' => 'conditional-1', 'target_node_id' => 'stage-yes', 'source_handle' => 'conditional-1-yes'],
                    ['edge_id' => 'edge-3', 'source_node_id' => 'conditional-1', 'target_node_id' => 'stage-no', 'source_handle' => 'conditional-1-no'],
                ],
            ],
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
            'prospectos_ids' => [1, 2, 3],
        ]);

        $this->etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'completed',
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================
    // Tests de integración E2E
    // ============================================

    public function test_condicion_yes_programa_rama_correcta(): void
    {
        Bus::fake([EnviarEtapaJob::class]);
        $this->mockAthenaService(['Views' => 5, 'Clicks' => 1, 'Bounces' => 0]);

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(AthenaCampaignService::class));

        // Verificar que se registró la condición evaluada
        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'condition_node_id' => 'conditional-1',
            'check_param' => 'Views',
            'check_operator' => '>',
            'check_value' => '0',
            'resultado' => 'yes',
        ]);

        // Verificar que se programó la rama YES
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-yes',
            'estado' => 'pending',
        ]);

        Bus::assertDispatched(EnviarEtapaJob::class);
    }

    public function test_condicion_no_programa_rama_correcta(): void
    {
        Bus::fake([EnviarEtapaJob::class]);
        $this->mockAthenaService(['Views' => 0, 'Clicks' => 0, 'Bounces' => 0]);

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(AthenaCampaignService::class));

        // Verificar resultado NO
        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'condition_node_id' => 'conditional-1',
            'resultado' => 'no',
        ]);

        // Verificar que se programó la rama NO
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-no',
            'estado' => 'pending',
        ]);

        Bus::assertDispatched(EnviarEtapaJob::class);
    }

    public function test_operador_no_igual_funciona_correctamente(): void
    {
        // Configurar condición: Views != 0
        $this->condicion->update([
            'check_operator' => '!=',
            'check_value' => '0',
        ]);

        Bus::fake([EnviarEtapaJob::class]);
        $this->mockAthenaService(['Views' => 3, 'Clicks' => 0, 'Bounces' => 0]);

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(AthenaCampaignService::class));

        // 3 != 0 debería ser YES
        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'resultado' => 'yes',
        ]);
    }

    public function test_operador_not_in_funciona_correctamente(): void
    {
        // Configurar condición: Bounces not_in 1,2,3
        $this->condicion->update([
            'check_param' => 'Bounces',
            'check_operator' => 'not_in',
            'check_value' => '1,2,3',
        ]);

        Bus::fake([EnviarEtapaJob::class]);
        $this->mockAthenaService(['Views' => 5, 'Clicks' => 0, 'Bounces' => 0]);

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(AthenaCampaignService::class));

        // 0 not_in [1,2,3] debería ser YES
        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'resultado' => 'yes',
        ]);
    }

    public function test_job_registra_flujo_job_completado(): void
    {
        Bus::fake([EnviarEtapaJob::class]);
        $this->mockAthenaService(['Views' => 1, 'Clicks' => 0, 'Bounces' => 0]);

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(AthenaCampaignService::class));

        $this->assertDatabaseHas('flujo_jobs', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'job_type' => 'verificar_condicion',
            'estado' => 'completed',
        ]);
    }

    public function test_job_actualiza_estado_ejecucion(): void
    {
        Bus::fake([EnviarEtapaJob::class]);
        $this->mockAthenaService(['Views' => 5, 'Clicks' => 0, 'Bounces' => 0]);

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(AthenaCampaignService::class));

        $this->ejecucion->refresh();
        $this->assertEquals('in_progress', $this->ejecucion->estado);
        $this->assertEquals('stage-yes', $this->ejecucion->proximo_nodo);
    }

    public function test_guarda_valor_actual_de_estadisticas(): void
    {
        Bus::fake([EnviarEtapaJob::class]);
        $this->mockAthenaService(['Views' => 42, 'Clicks' => 10, 'Bounces' => 0]);

        $job = $this->createVerificarCondicionJob();
        $job->handle(app(AthenaCampaignService::class));

        $this->assertDatabaseHas('flujo_ejecucion_condiciones', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'check_result_value' => 42,
        ]);
    }

    // ============================================
    // Helpers
    // ============================================

    private function mockAthenaService(array $stats): void
    {
        $mock = Mockery::mock(AthenaCampaignService::class);
        $mock->shouldReceive('getStatistics')
            ->andReturn([
                'error' => false,
                'codigo' => 200,
                'mensaje' => array_merge([
                    'messageID' => 12345,
                    'Recipients' => 100,
                    'Views' => 0,
                    'Clicks' => 0,
                    'Bounces' => 0,
                    'Unsubscribes' => 0,
                ], $stats),
            ]);

        $this->app->instance(AthenaCampaignService::class, $mock);
    }

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
}
