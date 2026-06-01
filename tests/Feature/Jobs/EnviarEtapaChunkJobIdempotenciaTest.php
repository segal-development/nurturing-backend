<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EnviarEtapaChunkJob;
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
 * Feature tests for idempotency filter in EnviarEtapaChunkJob.
 *
 * TDD RED phase — written before implementation.
 *
 * Key behavioral difference vs EnviarEtapaJob:
 *   createJobForProspecto in ChunkJob creates ONE leaf job only.
 *   'ambos' falls through to email (no dual-job). Therefore the
 *   idempotency filter must be per the job that would actually be created.
 *
 * Model A2: chunks can become no-ops when all their prospectos were already
 * sent. The offset is calculated on the ORIGINAL unfiltered population, so
 * paginación correctness is preserved.
 */
class EnviarEtapaChunkJobIdempotenciaTest extends TestCase
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
        ]);

        // Non-perpetual to avoid stage-based filtering
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
    // T5-A: Estado bloqueante — NO encola el leaf job
    // ============================================

    /** @test */
    public function chunk_con_email_enviado_no_encola_leaf_job(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujoEmail();
        $this->createEnvioParaEtapa($pef->prospecto_id, 'email', 'enviado');

        $this->runChunkJobFor([$pef->prospecto_id]);

        Bus::assertNothingBatched();
    }

    /** @test */
    public function chunk_con_email_pendiente_no_encola_leaf_job(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujoEmail();
        $this->createEnvioParaEtapa($pef->prospecto_id, 'email', 'pendiente');

        $this->runChunkJobFor([$pef->prospecto_id]);

        Bus::assertNothingBatched();
    }

    /** @test */
    public function chunk_con_email_abierto_no_encola_leaf_job(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujoEmail();
        $this->createEnvioParaEtapa($pef->prospecto_id, 'email', 'abierto');

        $this->runChunkJobFor([$pef->prospecto_id]);

        Bus::assertNothingBatched();
    }

    // ============================================
    // T5-B: Estados no bloqueantes — SÍ encola
    // ============================================

    /** @test */
    public function chunk_con_email_fallido_SI_encola_leaf_job(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujoEmail();
        $this->createEnvioParaEtapa($pef->prospecto_id, 'email', 'fallido');

        $this->runChunkJobFor([$pef->prospecto_id]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    /** @test */
    public function chunk_sin_envio_previo_SI_encola_leaf_job(): void
    {
        Bus::fake();

        $pef = $this->createProspectoEnFlujoEmail();
        // No Envio created

        $this->runChunkJobFor([$pef->prospecto_id]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    // ============================================
    // T5-C: Chunk completamente enviado = no-op (AC-5)
    // ============================================

    /** @test */
    public function chunk_con_todos_ya_enviados_es_noop(): void
    {
        Bus::fake();

        $pef1 = $this->createProspectoEnFlujoEmail();
        $pef2 = $this->createProspectoEnFlujoEmail();
        $pef3 = $this->createProspectoEnFlujoEmail();

        $this->createEnvioParaEtapa($pef1->prospecto_id, 'email', 'enviado');
        $this->createEnvioParaEtapa($pef2->prospecto_id, 'email', 'abierto');
        $this->createEnvioParaEtapa($pef3->prospecto_id, 'email', 'clickeado');

        $this->runChunkJobFor([
            $pef1->prospecto_id,
            $pef2->prospecto_id,
            $pef3->prospecto_id,
        ]);

        Bus::assertNothingBatched();
    }

    // ============================================
    // T5-D: Chunk mixto — solo encola los correctos (AC-6)
    // ============================================

    /** @test */
    public function mezcla_nuevos_y_ya_enviados_solo_encola_correctos(): void
    {
        Bus::fake();

        $pEnviado  = $this->createProspectoEnFlujoEmail(); // enviado — NO encola
        $pFallido  = $this->createProspectoEnFlujoEmail(); // fallido — SÍ encola
        $pNuevo    = $this->createProspectoEnFlujoEmail(); // sin envío — SÍ encola

        $this->createEnvioParaEtapa($pEnviado->prospecto_id, 'email', 'enviado');
        $this->createEnvioParaEtapa($pFallido->prospecto_id, 'email', 'fallido');

        $this->runChunkJobFor([
            $pEnviado->prospecto_id,
            $pFallido->prospecto_id,
            $pNuevo->prospecto_id,
        ]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
    }

    // ============================================
    // T5-E: SMS tipo — filtro sms correcto
    // ============================================

    /** @test */
    public function chunk_sms_con_sms_enviado_no_encola(): void
    {
        Bus::fake();

        $flujoSms = Flujo::factory()->porSms()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);
        $ejecucionSms = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujoSms->id,
            'es_perpetuo' => false,
            'prospectos_ids' => [],
        ]);
        $etapaSms = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucionSms->id,
            'node_id' => 'stage-sms',
            'estado' => 'pending',
        ]);

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'telefono' => '123456789',
        ]);

        ProspectoEnFlujo::factory()->porSms()->create([
            'flujo_id' => $flujoSms->id,
            'prospecto_id' => $prospecto->id,
            'completado' => false,
            'cancelado' => false,
        ]);

        // SMS already sent for this etapa
        Envio::factory()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $etapaSms->id,
            'canal' => 'sms',
            'estado' => 'enviado',
            'fecha_enviado' => now(),
        ]);

        $job = new EnviarEtapaChunkJob(
            flujoEjecucionId: $ejecucionSms->id,
            etapaEjecucionId: $etapaSms->id,
            stage: [
                'id' => 'stage-sms',
                'type' => 'sms',
                'label' => 'SMS Etapa',
                'tipo_mensaje' => 'sms',
                'plantilla_mensaje' => 'SMS content',
                'plantilla_mensaje_sms' => 'SMS content',
            ],
            flujoId: $flujoSms->id,
            offset: 0,
            limit: 10,
            chunkIndex: 0,
            totalChunks: 1,
        );

        $job->handle();

        Bus::assertNothingBatched();
    }

    // ============================================
    // T5-F: Offset de paginación no se afecta (modelo A2)
    // ============================================

    /** @test */
    public function offset_del_chunk_no_cambia_cuando_hay_bloqueados(): void
    {
        Bus::fake();

        // Create 4 prospectos, offset=2 means we only process prospectos 3 and 4
        $p1 = $this->createProspectoEnFlujoEmail();
        $p2 = $this->createProspectoEnFlujoEmail();
        $p3 = $this->createProspectoEnFlujoEmail();
        $p4 = $this->createProspectoEnFlujoEmail();

        // p3 (which falls in offset=2 window) is already sent
        $this->createEnvioParaEtapa($p3->prospecto_id, 'email', 'enviado');
        // p4 is new

        // chunk with offset=2, limit=2 — should see p3 and p4 (by DB id order)
        // p3 is blocked, p4 should be enqueued
        $job = new EnviarEtapaChunkJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: $this->defaultStage(),
            flujoId: $this->flujo->id,
            offset: 2,
            limit: 2,
            chunkIndex: 1,
            totalChunks: 2,
        );

        $job->handle();

        // p4 should be dispatched (1 job), p3 skipped
        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    // ============================================
    // Helper methods
    // ============================================

    private function createProspectoEnFlujoEmail(): ProspectoEnFlujo
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

    /**
     * Run a ChunkJob for the given prospect IDs (offset=0, limit=count).
     */
    private function runChunkJobFor(array $prospectoIds): void
    {
        $job = new EnviarEtapaChunkJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: $this->defaultStage(),
            flujoId: $this->flujo->id,
            offset: 0,
            limit: count($prospectoIds) + 10,
            chunkIndex: 0,
            totalChunks: 1,
        );

        $job->handle();
    }

    private function defaultStage(): array
    {
        return [
            'id' => 'stage-1',
            'type' => 'email',
            'label' => 'Etapa 1',
            'tipo_mensaje' => 'email',
            'plantilla_mensaje' => 'Test content',
            'template' => ['asunto' => 'Test'],
        ];
    }
}
