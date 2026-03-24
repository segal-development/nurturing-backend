<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CatchUpProspectosJob;
use App\Jobs\EnviarEtapaJob;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\StageOrderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Integration tests for CatchUpProspectosJob.
 *
 * Verifies the catch-up mechanism that processes prospects who are behind
 * the current execution stage in perpetual flows.
 */
class CatchUpProspectosJobTest extends TestCase
{
    use RefreshDatabase;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

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
    }

    // ============================================
    // TESTS: Finding perpetual executions
    // ============================================

    /** @test */
    public function finds_perpetual_executions_in_progress(): void
    {
        Queue::fake();

        // Create perpetual execution in progress
        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2', // Currently at stage 2
        ]);

        // Create a new prospect (NULL ultima) who needs stage 1
        $this->createProspectoEnFlujo($ejecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should dispatch EnviarEtapaJob for the new prospect
        Queue::assertPushed(EnviarEtapaJob::class);
    }

    /** @test */
    public function skips_non_perpetual_executions(): void
    {
        Queue::fake();

        // Create non-perpetual execution
        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => false, // NOT perpetual
            'nodo_actual' => 'stage-2',
        ]);

        // Create a behind prospect
        $this->createProspectoEnFlujo($ejecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should NOT dispatch anything
        Queue::assertNotPushed(EnviarEtapaJob::class);
    }

    /** @test */
    public function skips_executions_not_in_progress(): void
    {
        Queue::fake();

        // Create perpetual execution that's pending (not started)
        $pendingEjecucion = FlujoEjecucion::factory()->pending()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
        ]);

        // Create perpetual execution that's completed
        $completedEjecucion = FlujoEjecucion::factory()->completed()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
        ]);

        $this->createProspectoEnFlujo($pendingEjecucion, null);
        $this->createProspectoEnFlujo($completedEjecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should NOT dispatch anything
        Queue::assertNotPushed(EnviarEtapaJob::class);
    }

    // ============================================
    // TESTS: Identifying prospects needing catch-up
    // ============================================

    /** @test */
    public function identifies_new_prospects_needing_stage_1(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-3', // Currently at stage 3
        ]);

        // Create 3 new prospects (NULL ultima)
        $newProspect1 = $this->createProspectoEnFlujo($ejecucion, null);
        $newProspect2 = $this->createProspectoEnFlujo($ejecucion, null);
        $newProspect3 = $this->createProspectoEnFlujo($ejecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should dispatch EnviarEtapaJob for stage-1
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) {
            return $job->stage['id'] === 'stage-1';
        });
    }

    /** @test */
    public function identifies_behind_prospects_needing_next_stage(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-3', // Currently at stage 3
        ]);

        // Create a prospect at stage-1 who needs stage-2
        $this->createProspectoEnFlujo($ejecucion, 'stage-1');

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should dispatch EnviarEtapaJob for stage-2 (next stage after stage-1)
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) {
            return $job->stage['id'] === 'stage-2';
        });
    }

    /** @test */
    public function processes_multiple_behind_groups_at_different_stages(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-3', // Currently at stage 3
        ]);

        // Create prospects at different stages
        $this->createProspectoEnFlujo($ejecucion, null); // Needs stage-1
        $this->createProspectoEnFlujo($ejecucion, 'stage-1'); // Needs stage-2

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should dispatch jobs for both groups
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) {
            return $job->stage['id'] === 'stage-1'; // For new prospects
        });

        Queue::assertPushed(EnviarEtapaJob::class, function ($job) {
            return $job->stage['id'] === 'stage-2'; // For prospects at stage-1
        });
    }

    /** @test */
    public function does_not_dispatch_for_prospects_already_at_current_stage(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2', // Currently at stage 2
        ]);

        // Create a prospect already at stage-1 (previous to current)
        // They need stage-2, but that's the CURRENT stage being processed normally
        // Only prospects behind THAT should be caught up
        $this->createProspectoEnFlujo($ejecucion, 'stage-1');

        // Create a prospect already at stage-2 (current stage)
        $this->createProspectoEnFlujo($ejecucion, 'stage-2');

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should dispatch for prospects at stage-1 needing stage-2
        // But NOT for prospects already at stage-2
        Queue::assertPushed(EnviarEtapaJob::class, 1);
    }

    // ============================================
    // TESTS: Dispatches correct EnviarEtapaJob
    // ============================================

    /** @test */
    public function dispatches_enviar_etapa_job_with_correct_parameters(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
        ]);

        $prospect = $this->createProspectoEnFlujo($ejecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($ejecucion, $prospect) {
            return $job->flujoEjecucionId === $ejecucion->id
                && in_array($prospect->prospecto_id, $job->prospectoIds)
                && $job->stage['id'] === 'stage-1';
        });
    }

    /** @test */
    public function creates_flujo_ejecucion_etapa_for_dispatched_stage(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
        ]);

        $this->createProspectoEnFlujo($ejecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should create FlujoEjecucionEtapa for stage-1
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ]);
    }

    /** @test */
    public function uses_existing_pending_etapa_if_available(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
        ]);

        // Create existing pending etapa
        $existingEtapa = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
            'prospectos_ids' => [999], // Some existing prospect
            'prospectos_count' => 1,
        ]);

        $prospect = $this->createProspectoEnFlujo($ejecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should update existing etapa, not create new one
        $existingEtapa->refresh();
        $this->assertContains($prospect->prospecto_id, $existingEtapa->prospectos_ids);
        $this->assertContains(999, $existingEtapa->prospectos_ids);

        // Should NOT create a duplicate etapa
        $this->assertEquals(1, FlujoEjecucionEtapa::where([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ])->count());
    }

    // ============================================
    // TESTS: Edge cases
    // ============================================

    /** @test */
    public function handles_empty_executions_gracefully(): void
    {
        Queue::fake();

        // Create perpetual execution with no prospects
        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
        ]);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);

        // Should not throw exception
        $job->handle($resolver);

        // Should not dispatch anything
        Queue::assertNotPushed(EnviarEtapaJob::class);
    }

    /** @test */
    public function handles_flow_without_stages_gracefully(): void
    {
        Queue::fake();

        // Create flow with empty config
        $emptyFlujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => [],
        ]);

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $emptyFlujo->id,
            'es_perpetuo' => true,
        ]);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);

        // Should not throw exception
        $job->handle($resolver);

        Queue::assertNotPushed(EnviarEtapaJob::class);
    }

    /** @test */
    public function excludes_cancelled_prospects(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
        ]);

        // Create cancelled prospect
        $cancelledProspect = $this->createProspectoEnFlujo($ejecucion, null);
        $cancelledProspect->update(['cancelado' => true]);

        // Create active prospect
        $activeProspect = $this->createProspectoEnFlujo($ejecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should only include active prospect
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($activeProspect, $cancelledProspect) {
            return in_array($activeProspect->prospecto_id, $job->prospectoIds)
                && ! in_array($cancelledProspect->prospecto_id, $job->prospectoIds);
        });
    }

    /** @test */
    public function excludes_completed_prospects(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
        ]);

        // Create completed prospect
        $completedProspect = $this->createProspectoEnFlujo($ejecucion, null);
        $completedProspect->update(['completado' => true]);

        // Create active prospect
        $activeProspect = $this->createProspectoEnFlujo($ejecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should only include active prospect
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($activeProspect, $completedProspect) {
            return in_array($activeProspect->prospecto_id, $job->prospectoIds)
                && ! in_array($completedProspect->prospecto_id, $job->prospectoIds);
        });
    }

    /** @test */
    public function dispatches_to_catchup_queue(): void
    {
        Queue::fake();

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
        ]);

        $this->createProspectoEnFlujo($ejecucion, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Verify job was dispatched to catchup queue
        Queue::assertPushedOn('catchup', EnviarEtapaJob::class);
    }

    /** @test */
    public function handles_multiple_perpetual_executions(): void
    {
        Queue::fake();

        // Create second flow
        $flujo2 = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => $this->createThreeStageFlow(),
        ]);

        // Create two perpetual executions
        $ejecucion1 = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
        ]);

        $ejecucion2 = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujo2->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-3',
        ]);

        // Create behind prospects for each
        $this->createProspectoEnFlujo($ejecucion1, null);
        $this->createProspectoEnFlujoForFlujo($flujo2, $ejecucion2, null);

        $job = new CatchUpProspectosJob;
        $resolver = $this->app->make(StageOrderResolver::class);
        $job->handle($resolver);

        // Should dispatch for both executions
        Queue::assertPushed(EnviarEtapaJob::class, 2);
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

    private function createProspectoEnFlujo(FlujoEjecucion $ejecucion, ?string $ultimaEtapaNodeId): ProspectoEnFlujo
    {
        return $this->createProspectoEnFlujoForFlujo($this->flujo, $ejecucion, $ultimaEtapaNodeId);
    }

    private function createProspectoEnFlujoForFlujo(Flujo $flujo, FlujoEjecucion $ejecucion, ?string $ultimaEtapaNodeId): ProspectoEnFlujo
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => fake()->unique()->email(),
        ]);

        return ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => $ultimaEtapaNodeId,
            'completado' => false,
            'cancelado' => false,
        ]);
    }
}
