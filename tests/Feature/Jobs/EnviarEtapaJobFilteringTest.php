<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EnviarEtapaJob;
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
 * Integration tests for EnviarEtapaJob filtering by ultima_etapa_node_id.
 *
 * Verifies that perpetual executions correctly filter prospects based on
 * their progress through stages, preventing duplicate sends.
 */
class EnviarEtapaJobFilteringTest extends TestCase
{
    use RefreshDatabase;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

    private FlujoEjecucion $ejecucion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();

        // Create a flow with 3 stages
        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => $this->createThreeStageFlow(),
        ]);

        // Create a perpetual execution
        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-1',
        ]);
    }

    // ============================================
    // TESTS: First stage filtering
    // ============================================

    /** @test */
    public function first_stage_only_sends_to_prospects_with_null_ultima(): void
    {
        Bus::fake();

        // Create prospects with different progress states
        $newProspect = $this->createProspectoEnFlujo(null); // New - should receive
        $completedStage1 = $this->createProspectoEnFlujo('stage-1'); // Already completed - should NOT receive
        $completedStage2 = $this->createProspectoEnFlujo('stage-2'); // Already ahead - should NOT receive

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage-1',
                'type' => 'email',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test content',
            ],
            prospectoIds: [
                $newProspect->prospecto_id,
                $completedStage1->prospecto_id,
                $completedStage2->prospecto_id,
            ]
        );

        $job->handle();

        // Only the new prospect should be in the batch
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1;
        });
    }

    // ============================================
    // TESTS: Later stages filtering
    // ============================================

    /** @test */
    public function later_stages_only_send_to_prospects_who_completed_previous_stage(): void
    {
        Bus::fake();

        // Stage 2: only prospects who completed stage-1 should receive
        $newProspect = $this->createProspectoEnFlujo(null); // New - should NOT receive stage 2
        $completedStage1 = $this->createProspectoEnFlujo('stage-1'); // Ready for stage 2 - SHOULD receive
        $completedStage2 = $this->createProspectoEnFlujo('stage-2'); // Already completed - should NOT receive

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-2',
            'estado' => 'pending',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage-2',
                'type' => 'email',
                'label' => 'Etapa 2',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test content',
            ],
            prospectoIds: [
                $newProspect->prospecto_id,
                $completedStage1->prospecto_id,
                $completedStage2->prospecto_id,
            ]
        );

        $job->handle();

        // Only the prospect who completed stage-1 should be in the batch
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1;
        });
    }

    /** @test */
    public function stage_3_only_sends_to_prospects_who_completed_stage_2(): void
    {
        Bus::fake();

        // Stage 3: only prospects who completed stage-2 should receive
        $completedStage1 = $this->createProspectoEnFlujo('stage-1'); // Not ready - should NOT receive
        $completedStage2 = $this->createProspectoEnFlujo('stage-2'); // Ready for stage 3 - SHOULD receive
        $completedStage3 = $this->createProspectoEnFlujo('stage-3'); // Already completed - should NOT receive

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-3',
            'estado' => 'pending',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage-3',
                'type' => 'email',
                'label' => 'Etapa 3',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test content',
            ],
            prospectoIds: [
                $completedStage1->prospecto_id,
                $completedStage2->prospecto_id,
                $completedStage3->prospecto_id,
            ]
        );

        $job->handle();

        // Only the prospect who completed stage-2 should be in the batch
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1;
        });
    }

    // ============================================
    // TESTS: Non-perpetual executions
    // ============================================

    /** @test */
    public function non_perpetual_executions_do_not_filter_by_ultima_etapa(): void
    {
        Bus::fake();

        // Create a non-perpetual execution
        $nonPerpetualEjecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => false, // NOT perpetual
        ]);

        // Create prospects with different states - all should be included
        $newProspect = $this->createProspectoEnFlujo(null);
        $completedStage1 = $this->createProspectoEnFlujo('stage-1');

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $nonPerpetualEjecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $nonPerpetualEjecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage-1',
                'type' => 'email',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test content',
            ],
            prospectoIds: [
                $newProspect->prospecto_id,
                $completedStage1->prospecto_id,
            ]
        );

        $job->handle();

        // Both prospects should be included (no filtering for non-perpetual)
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 2;
        });
    }

    // ============================================
    // TESTS: Edge cases
    // ============================================

    /** @test */
    public function empty_eligible_list_completes_successfully_with_zero_sends(): void
    {
        Bus::fake();

        // All prospects have already completed the stage
        $completedStage1 = $this->createProspectoEnFlujo('stage-1');
        $anotherCompleted = $this->createProspectoEnFlujo('stage-1');

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1', // First stage, but all prospects already completed
            'estado' => 'pending',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage-1',
                'type' => 'email',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test content',
            ],
            prospectoIds: [
                $completedStage1->prospecto_id,
                $anotherCompleted->prospecto_id,
            ]
        );

        $job->handle();

        // No batch should be dispatched
        Bus::assertNothingBatched();

        // Etapa should be marked as completed
        $this->assertEquals('completed', $etapaEjecucion->fresh()->estado);
    }

    /** @test */
    public function mixed_batch_only_includes_eligible_prospects(): void
    {
        Bus::fake();

        // Create 5 prospects with mixed states
        $eligible1 = $this->createProspectoEnFlujo(null);
        $eligible2 = $this->createProspectoEnFlujo(null);
        $notEligible1 = $this->createProspectoEnFlujo('stage-1');
        $notEligible2 = $this->createProspectoEnFlujo('stage-2');
        $notEligible3 = $this->createProspectoEnFlujo('stage-1');

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage-1',
                'type' => 'email',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test content',
            ],
            prospectoIds: [
                $eligible1->prospecto_id,
                $eligible2->prospecto_id,
                $notEligible1->prospecto_id,
                $notEligible2->prospecto_id,
                $notEligible3->prospecto_id,
            ]
        );

        $job->handle();

        // Only the 2 eligible prospects should be in the batch
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 2;
        });
    }

    /** @test */
    public function cancelled_prospects_are_excluded_from_batch(): void
    {
        Bus::fake();

        // Create an eligible but cancelled prospect
        $cancelledProspect = $this->createProspectoEnFlujo(null);
        $cancelledProspect->update(['cancelado' => true]);

        $activeProspect = $this->createProspectoEnFlujo(null);

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage-1',
                'type' => 'email',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test content',
            ],
            prospectoIds: [
                $cancelledProspect->prospecto_id,
                $activeProspect->prospecto_id,
            ]
        );

        $job->handle();

        // Only the active prospect should be included
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1;
        });
    }

    /** @test */
    public function completed_prospects_are_excluded_from_batch(): void
    {
        Bus::fake();

        // Create an eligible but completed prospect (flow-wise)
        $completedProspect = $this->createProspectoEnFlujo(null);
        $completedProspect->update(['completado' => true]);

        $activeProspect = $this->createProspectoEnFlujo(null);

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage-1',
                'type' => 'email',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test content',
            ],
            prospectoIds: [
                $completedProspect->prospecto_id,
                $activeProspect->prospecto_id,
            ]
        );

        $job->handle();

        // Only the active prospect should be included
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1;
        });
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    private function createThreeStageFlow(): array
    {
        return [
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'label' => 'Inicio'],
                ['id' => 'stage-1', 'type' => 'email', 'label' => 'Etapa 1'],
                ['id' => 'stage-2', 'type' => 'email', 'label' => 'Etapa 2'],
                ['id' => 'stage-3', 'type' => 'email', 'label' => 'Etapa 3'],
                ['id' => 'end-1', 'type' => 'end', 'label' => 'Fin'],
            ],
            'branches' => [
                ['source_node_id' => 'start-1', 'target_node_id' => 'stage-1'],
                ['source_node_id' => 'stage-1', 'target_node_id' => 'stage-2'],
                ['source_node_id' => 'stage-2', 'target_node_id' => 'stage-3'],
                ['source_node_id' => 'stage-3', 'target_node_id' => 'end-1'],
            ],
            'initial_node' => 'start-1',
        ];
    }

    private function createProspectoEnFlujo(?string $ultimaEtapaNodeId): ProspectoEnFlujo
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => fake()->unique()->email(),
        ]);

        return ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => $ultimaEtapaNodeId,
            'completado' => false,
            'cancelado' => false,
        ]);
    }
}
