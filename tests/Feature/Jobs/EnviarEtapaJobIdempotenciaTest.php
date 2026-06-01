<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EnviarEtapaJob;
use App\Jobs\EnviarEmailEtapaProspectoJob;
use App\Jobs\EnviarSmsEtapaProspectoJob;
use App\Models\Envio;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Feature tests for idempotency filter in EnviarEtapaJob.
 *
 * TDD RED phase — tests written before implementation of the filter in EnviarEtapaJob.
 *
 * Verifies that EnviarEtapaJob skips leaf jobs for (prospecto_id, canal) pairs
 * that already have a blocking envío (enviado/abierto/clickeado/pendiente),
 * while 'fallido' and no-envío cases still enqueue.
 *
 * Uses NON-perpetual executions to isolate idempotency logic from
 * the perpetual-stage-filtering logic (tested in EnviarEtapaJobFilteringTest).
 */
class EnviarEtapaJobIdempotenciaTest extends TestCase
{
    use RefreshDatabase;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

    private FlujoEjecucion $ejecucion;

    private FlujoEjecucionEtapa $etapaEjecucion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();

        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => $this->createSingleStageFlow(),
        ]);

        // Non-perpetual so stage-based filtering does NOT apply
        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => false,
            'prospectos_ids' => [],
        ]);

        $this->etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ]);
    }

    // ============================================
    // TESTS: Estados bloqueantes — NO encola
    // ============================================

    /** @test */
    public function prospecto_con_envio_enviado_no_se_encola(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujo();
        $this->createEnvioParaEtapa($pef->prospecto_id, 'email', 'enviado');

        $this->dispatchJobFor([$pef->prospecto_id]);

        Bus::assertNothingBatched();
    }

    /** @test */
    public function prospecto_con_envio_pendiente_no_se_encola(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujo();
        $this->createEnvioParaEtapa($pef->prospecto_id, 'email', 'pendiente');

        $this->dispatchJobFor([$pef->prospecto_id]);

        Bus::assertNothingBatched();
    }

    /** @test */
    public function prospecto_con_envio_abierto_no_se_encola(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujo();
        $this->createEnvioParaEtapa($pef->prospecto_id, 'email', 'abierto');

        $this->dispatchJobFor([$pef->prospecto_id]);

        Bus::assertNothingBatched();
    }

    /** @test */
    public function prospecto_con_envio_clickeado_no_se_encola(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujo();
        $this->createEnvioParaEtapa($pef->prospecto_id, 'email', 'clickeado');

        $this->dispatchJobFor([$pef->prospecto_id]);

        Bus::assertNothingBatched();
    }

    // ============================================
    // TESTS: Estados no bloqueantes — SÍ encola
    // ============================================

    /** @test */
    public function prospecto_con_envio_fallido_SI_se_encola(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujo();
        $this->createEnvioParaEtapa($pef->prospecto_id, 'email', 'fallido');

        $this->dispatchJobFor([$pef->prospecto_id]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    /** @test */
    public function prospecto_sin_envio_SI_se_encola(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujo();
        // No Envio record created

        $this->dispatchJobFor([$pef->prospecto_id]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    // ============================================
    // TESTS: Escenarios de etapa completa y mezcla
    // ============================================

    /** @test */
    public function etapa_completamente_enviada_encola_cero_jobs(): void
    {
        Bus::fake();

        $pef1 = $this->createProspectoEnFlujo();
        $pef2 = $this->createProspectoEnFlujo();
        $pef3 = $this->createProspectoEnFlujo();

        $this->createEnvioParaEtapa($pef1->prospecto_id, 'email', 'enviado');
        $this->createEnvioParaEtapa($pef2->prospecto_id, 'email', 'abierto');
        $this->createEnvioParaEtapa($pef3->prospecto_id, 'email', 'clickeado');

        $this->dispatchJobFor([
            $pef1->prospecto_id,
            $pef2->prospecto_id,
            $pef3->prospecto_id,
        ]);

        Bus::assertNothingBatched();
    }

    /** @test */
    public function mezcla_nuevos_fallidos_y_ya_enviados_encola_correctos(): void
    {
        Bus::fake();

        $p1 = $this->createProspectoEnFlujo(); // enviado — NO encola
        $p2 = $this->createProspectoEnFlujo(); // fallido — SÍ encola
        $p3 = $this->createProspectoEnFlujo(); // sin envío — SÍ encola

        $this->createEnvioParaEtapa($p1->prospecto_id, 'email', 'enviado');
        $this->createEnvioParaEtapa($p2->prospecto_id, 'email', 'fallido');

        $this->dispatchJobFor([
            $p1->prospecto_id,
            $p2->prospecto_id,
            $p3->prospecto_id,
        ]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
    }

    // ============================================
    // TESTS: tipo_mensaje='ambos' — filtro per-canal
    // ============================================

    /** @test */
    public function tipo_mensaje_ambos_canal_email_enviado_encola_solo_sms(): void
    {
        Bus::fake();

        // Create a flujo for tipo_mensaje='ambos'
        $flujoAmbos = Flujo::factory()->porAmbos()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => $this->createSingleStageFlowAmbos(),
        ]);

        $ejecucionAmbos = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujoAmbos->id,
            'es_perpetuo' => false,
            'prospectos_ids' => [],
        ]);

        $etapaAmbos = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucionAmbos->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ]);

        // Prospecto with both email and phone
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
            'telefono' => '123456789',
        ]);

        ProspectoEnFlujo::factory()->create([
            'flujo_id' => $flujoAmbos->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'email',
            'completado' => false,
            'cancelado' => false,
        ]);

        // Email was already sent — SMS was not
        Envio::factory()->enviado()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $etapaAmbos->id,
            'canal' => 'email',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucionAmbos->id,
            etapaEjecucionId: $etapaAmbos->id,
            stage: [
                'id' => 'stage-1',
                'type' => 'ambos',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'ambos',
                'plantilla_mensaje' => 'Test content',
                'plantilla_mensaje_sms' => 'SMS content',
                'template' => ['asunto' => 'Test'],
            ],
            prospectoIds: [$prospecto->id],
        );

        $job->handle();

        Bus::assertBatched(function ($batch) {
            if ($batch->jobs->count() !== 1) {
                return false;
            }
            return $batch->jobs->first() instanceof EnviarSmsEtapaProspectoJob;
        });
    }

    // ============================================
    // TESTS: Re-despacho múltiple
    // ============================================

    /** @test */
    public function redespacho_multiple_no_acumula_leaf_jobs(): void
    {
        Bus::fake();

        $p1 = $this->createProspectoEnFlujo(); // sin envío — SÍ encola en cada corrida
        $p2 = $this->createProspectoEnFlujo(); // enviado — NUNCA encola

        $this->createEnvioParaEtapa($p2->prospecto_id, 'email', 'enviado');

        $prospectoIds = [$p1->prospecto_id, $p2->prospecto_id];

        // Dispatch 3 times using fresh etapaEjecucion instances to avoid
        // isAlreadyCompleted early-exit (each corrida is a distinct etapa context)
        $etapa2 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1-run2',
            'estado' => 'pending',
        ]);

        $etapa3 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1-run3',
            'estado' => 'pending',
        ]);

        // Primera corrida (usando etapaEjecucion del setUp)
        $this->dispatchJobFor($prospectoIds);

        // Segunda corrida — mismos envíos bloqueantes para p2
        Envio::factory()->create([
            'prospecto_id' => $p2->prospecto_id,
            'flujo_ejecucion_etapa_id' => $etapa2->id,
            'canal' => 'email',
            'estado' => 'enviado',
            'fecha_enviado' => now(),
        ]);
        $job2 = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapa2->id,
            stage: ['id' => 'stage-1', 'type' => 'email', 'label' => 'Etapa 1', 'tipo_mensaje' => 'email', 'plantilla_mensaje' => 'Test content', 'template' => ['asunto' => 'Test']],
            prospectoIds: $prospectoIds,
        );
        $job2->handle();

        // Tercera corrida
        Envio::factory()->create([
            'prospecto_id' => $p2->prospecto_id,
            'flujo_ejecucion_etapa_id' => $etapa3->id,
            'canal' => 'email',
            'estado' => 'enviado',
            'fecha_enviado' => now(),
        ]);
        $job3 = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapa3->id,
            stage: ['id' => 'stage-1', 'type' => 'email', 'label' => 'Etapa 1', 'tipo_mensaje' => 'email', 'plantilla_mensaje' => 'Test content', 'template' => ['asunto' => 'Test']],
            prospectoIds: $prospectoIds,
        );
        $job3->handle();

        // In all 3 runs, p1 should get a job (1 per run) but p2 should never appear.
        // We verify: every dispatched batch contains exactly 1 job, and no batch
        // has 2 jobs (which would indicate p2 slipped through).
        $batches = Bus::dispatchedBatches();
        $this->assertCount(3, $batches, 'Expected 3 batches (one per run)');
        foreach ($batches as $batch) {
            $this->assertCount(1, $batch->jobs, 'Each batch must have exactly 1 job (only p1, never p2)');
        }
    }

    // ============================================
    // Helper methods
    // ============================================

    private function createProspectoEnFlujo(): ProspectoEnFlujo
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => fake()->unique()->email(),
        ]);

        return ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'completado' => false,
            'cancelado' => false,
        ]);
    }

    private function createEnvioParaEtapa(int $prospectoId, string $canal, string $estado): Envio
    {
        return Envio::factory()->create([
            'prospecto_id' => $prospectoId,
            'flujo_ejecucion_etapa_id' => $this->etapaEjecucion->id,
            'canal' => $canal,
            'estado' => $estado,
            'fecha_enviado' => in_array($estado, ['enviado', 'abierto', 'clickeado']) ? now() : null,
            'fecha_abierto' => in_array($estado, ['abierto', 'clickeado']) ? now() : null,
            'fecha_clickeado' => $estado === 'clickeado' ? now() : null,
        ]);
    }

    private function dispatchJobFor(array $prospectoIds): void
    {
        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: [
                'id' => 'stage-1',
                'type' => 'email',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test content',
                'template' => ['asunto' => 'Test'],
            ],
            prospectoIds: $prospectoIds,
        );

        $job->handle();
    }

    private function createSingleStageFlow(): array
    {
        return [
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'label' => 'Inicio'],
                ['id' => 'stage-1', 'type' => 'email', 'label' => 'Etapa 1'],
                ['id' => 'end-1', 'type' => 'end', 'label' => 'Fin'],
            ],
            'branches' => [
                ['source_node_id' => 'start-1', 'target_node_id' => 'stage-1'],
                ['source_node_id' => 'stage-1', 'target_node_id' => 'end-1'],
            ],
            'initial_node' => 'start-1',
        ];
    }

    private function createSingleStageFlowAmbos(): array
    {
        return [
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'label' => 'Inicio'],
                ['id' => 'stage-1', 'type' => 'ambos', 'label' => 'Etapa 1'],
                ['id' => 'end-1', 'type' => 'end', 'label' => 'Fin'],
            ],
            'branches' => [
                ['source_node_id' => 'start-1', 'target_node_id' => 'stage-1'],
                ['source_node_id' => 'stage-1', 'target_node_id' => 'end-1'],
            ],
            'initial_node' => 'start-1',
        ];
    }
}
