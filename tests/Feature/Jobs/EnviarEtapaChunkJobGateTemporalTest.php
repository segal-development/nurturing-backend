<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EnviarEtapaChunkJob;
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
 * TDD RED → GREEN: Temporal gate in EnviarEtapaChunkJob (high-volume path).
 *
 * Decision 4 (design): the temporal predicate MUST be applied in baseQuery
 * BEFORE skip($offset)->take($limit), not as a PHP post-filter, so that
 * pagination offsets remain coherent across chunks.
 *
 * Gate formula: now() >= fecha_inicio + offset_acumulado(targetStage)
 * (same guard as GuardedTransition::puedeAvanzar)
 *
 * Test scenarios:
 *  T-CT-1: Prospecto too young (fecha_inicio 40d ago, stage needs 60d) → excluded
 *  T-CT-2: Prospecto old enough (fecha_inicio 70d ago, stage needs 60d) → included
 *  T-CT-3: Mixed: one young + one old → only old gets chunk job dispatched
 *  T-CT-4: Pagination coherence: gate applied before skip/take, not after
 */
class EnviarEtapaChunkJobGateTemporalTest extends TestCase
{
    use RefreshDatabase;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujoConOffset;

    private FlujoEjecucion $ejecucion;

    private FlujoEjecucionEtapa $etapaEjecucion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();

        // Flujo with two stages:
        //   stage-1: tiempo_espera=0  → offset=0 days from fecha_inicio
        //   stage-2: tiempo_espera=60 → offset=60 days from fecha_inicio
        $this->flujoConOffset = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => $this->createTwoStageFlowWith60DayOffset(),
        ]);

        // Perpetual execution on stage-2 (the offset stage)
        // prospectos_ids=[] avoids the FK violation in ejecucion_prospecto pivot
        // (same pattern as EnviarEtapaChunkJobIdempotenciaTest)
        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujoConOffset->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
            'prospectos_ids' => [],
        ]);

        $this->etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-2',
            'estado' => 'executing',
        ]);
    }

    // ============================================
    // T-CT-1: Too young → excluded from chunk
    // ============================================

    /** @test */
    public function prospecto_muy_joven_no_entra_en_el_chunk(): void
    {
        Bus::fake();

        // fecha_inicio = 40 days ago, stage-2 requires 60 days → NOT eligible
        $joven = $this->createProspectoConFechaInicio(
            ultimaEtapaNodeId: 'stage-1',
            fechaInicioAgo: 40
        );

        $this->runChunkForStage2(offset: 0, limit: 10);

        // The young prospecto must NOT appear in any batch
        Bus::assertNothingBatched();
    }

    // ============================================
    // T-CT-2: Old enough → included in chunk
    // ============================================

    /** @test */
    public function prospecto_con_suficiente_antiguedad_entra_en_el_chunk(): void
    {
        Bus::fake();

        // fecha_inicio = 70 days ago, stage-2 requires 60 days → eligible
        $mayor = $this->createProspectoConFechaInicio(
            ultimaEtapaNodeId: 'stage-1',
            fechaInicioAgo: 70
        );

        $this->runChunkForStage2(offset: 0, limit: 10);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    // ============================================
    // T-CT-3: Mixed — only old enough gets dispatched
    // ============================================

    /** @test */
    public function mezcla_jovenes_y_maduros_solo_envía_los_maduros(): void
    {
        Bus::fake();

        // Too young — should NOT be dispatched
        $this->createProspectoConFechaInicio(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 30);
        $this->createProspectoConFechaInicio(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 50);

        // Old enough — should be dispatched
        $this->createProspectoConFechaInicio(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 61);
        $this->createProspectoConFechaInicio(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 90);

        $this->runChunkForStage2(offset: 0, limit: 20);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
    }

    // ============================================
    // T-CT-4: Pagination coherence — gate applied pre-paginate
    //
    // Setup: 4 prospectos total:
    //   ids sorted: [p1(young), p2(young), p3(old), p4(old)]
    // Request chunk with offset=0, limit=2
    //
    // CORRECT behaviour (gate in WHERE): base set = [p3, p4] (young ones excluded
    //   before pagination), offset=0 → returns [p3, p4] → 2 jobs dispatched
    //
    // WRONG behaviour (gate as PHP post-filter): base set = [p1,p2,p3,p4],
    //   offset=0 limit=2 → page = [p1,p2] → filter out both → 0 jobs
    //   (this is the bug we're preventing).
    // ============================================

    /** @test */
    public function gate_en_where_mantiene_coherencia_de_paginacion(): void
    {
        Bus::fake();

        // Create 2 young + 2 old. We rely on auto-increment for ordering.
        // Young prospectos (will have lower IDs)
        $y1 = $this->createProspectoConFechaInicio(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 10);
        $y2 = $this->createProspectoConFechaInicio(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 20);
        // Old prospectos (higher IDs, inserted after)
        $o1 = $this->createProspectoConFechaInicio(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 65);
        $o2 = $this->createProspectoConFechaInicio(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 70);

        // With gate in WHERE: eligible set = {o1, o2} sorted by id
        // offset=0 limit=2 → gets o1 and o2 → 2 jobs
        $this->runChunkForStage2(offset: 0, limit: 2);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
    }

    // ============================================
    // Helper methods
    // ============================================

    private function createProspectoConFechaInicio(
        string $ultimaEtapaNodeId,
        int $fechaInicioAgo
    ): ProspectoEnFlujo {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => fake()->unique()->safeEmail(),
        ]);

        return ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujoConOffset->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => $ultimaEtapaNodeId,
            'fecha_inicio' => now()->subDays($fechaInicioAgo),
            'completado' => false,
            'cancelado' => false,
        ]);
    }

    private function runChunkForStage2(int $offset, int $limit): void
    {
        $job = new EnviarEtapaChunkJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: $this->stage2(),
            flujoId: $this->flujoConOffset->id,
            offset: $offset,
            limit: $limit,
            chunkIndex: 0,
            totalChunks: 1,
        );

        $job->handle();
    }

    private function stage2(): array
    {
        return [
            'id' => 'stage-2',
            'type' => 'email',
            'label' => 'Etapa 2 (60 días)',
            'tipo_mensaje' => 'email',
            'plantilla_mensaje' => 'Content for stage 2',
            'template' => ['asunto' => 'Stage 2 subject'],
        ];
    }

    /**
     * Two-stage perpetual flow:
     *   start-1 → stage-1 (day 0) → stage-2 (day 60) → end-1
     */
    private function createTwoStageFlowWith60DayOffset(): array
    {
        return [
            'initial_node' => 'start-1',
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'label' => 'Inicio', 'tiempo_espera' => 0],
                ['id' => 'stage-1', 'type' => 'email', 'label' => 'Etapa 1', 'tiempo_espera' => 0],
                ['id' => 'stage-2', 'type' => 'email', 'label' => 'Etapa 2', 'tiempo_espera' => 60],
                ['id' => 'end-1', 'type' => 'end', 'label' => 'Fin', 'tiempo_espera' => 0],
            ],
            'branches' => [
                ['source_node_id' => 'start-1', 'target_node_id' => 'stage-1'],
                ['source_node_id' => 'stage-1', 'target_node_id' => 'stage-2'],
                ['source_node_id' => 'stage-2', 'target_node_id' => 'end-1'],
            ],
        ];
    }
}
