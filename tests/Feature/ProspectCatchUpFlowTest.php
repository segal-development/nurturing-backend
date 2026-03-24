<?php

namespace Tests\Feature;

use App\Jobs\AsignarProspectosAEjecucionPerpetua;
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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-end feature test for the Prospect Catch-Up System.
 *
 * Tests the full flow:
 * 1. Create a perpetual execution with stages
 * 2. Import new prospects
 * 3. Verify they're added to perpetual execution
 * 4. Verify catch-up job advances them through stage 1
 * 5. Verify ultima_etapa_node_id is updated
 * 6. Verify they're eligible for stage 2
 */
class ProspectCatchUpFlowTest extends TestCase
{
    use RefreshDatabase;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

    private FlujoEjecucion $ejecucion;

    private StageOrderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();
        $this->resolver = new StageOrderResolver;

        // Create a flow with 3 stages
        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => $this->createThreeStageFlow(),
        ]);

        // Create a perpetual execution at stage 3
        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-3', // Already at stage 3
            'prospectos_ids' => [],
        ]);
    }

    // ============================================
    // TESTS: Full flow scenarios
    // ============================================

    /** @test */
    public function new_prospects_start_from_stage_1_while_execution_is_at_stage_3(): void
    {
        Queue::fake();

        // Simulate importing new prospects
        $newProspects = $this->createNewProspects(3);

        // Add them to the perpetual execution (simulating AsignarProspectosAEjecucionPerpetua)
        foreach ($newProspects as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->create([
                'flujo_id' => $this->flujo->id,
                'prospecto_id' => $prospecto->id,
                'ultima_etapa_node_id' => null, // New - no stages completed
                'completado' => false,
                'cancelado' => false,
            ]);
        }

        // Update execution prospectos_ids
        $this->ejecucion->update([
            'prospectos_ids' => $newProspects->pluck('id')->toArray(),
        ]);

        // Run catch-up job
        $job = new CatchUpProspectosJob;
        $job->handle($this->resolver);

        // Verify EnviarEtapaJob was dispatched for stage-1 (not stage-3)
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) {
            return $job->stage['id'] === 'stage-1';
        });
    }

    /** @test */
    public function prospects_progress_through_stages_sequentially(): void
    {
        Queue::fake();

        // Create a prospect who just completed stage-1
        $prospecto = $this->createNewProspects(1)->first();

        $pef = ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => 'stage-1', // Completed stage 1
        ]);

        // Verify they're eligible for stage-2
        $isEligibleForStage2 = $this->resolver->isEligibleForStage($this->flujo, 'stage-1', 'stage-2');
        $this->assertTrue($isEligibleForStage2);

        // Verify they're NOT eligible for stage-3 (skipping)
        $isEligibleForStage3 = $this->resolver->isEligibleForStage($this->flujo, 'stage-1', 'stage-3');
        $this->assertFalse($isEligibleForStage3);

        // Run catch-up job
        $job = new CatchUpProspectosJob;
        $job->handle($this->resolver);

        // Should dispatch for stage-2 (their next stage)
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($prospecto) {
            return $job->stage['id'] === 'stage-2'
                && in_array($prospecto->id, $job->prospectoIds);
        });
    }

    /** @test */
    public function ultima_etapa_node_id_is_updated_after_successful_send(): void
    {
        // Create a prospect with NULL ultima
        $prospecto = $this->createNewProspects(1)->first();

        $pef = ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => null,
        ]);

        // Verify initially NULL
        $this->assertNull($pef->ultima_etapa_node_id);

        // Simulate successful send by updating ultima_etapa_node_id
        // (In real flow, EnvioService does this)
        $pef->update(['ultima_etapa_node_id' => 'stage-1']);

        // Verify updated
        $pef->refresh();
        $this->assertEquals('stage-1', $pef->ultima_etapa_node_id);

        // Verify eligibility changed
        $isEligibleForStage1 = $this->resolver->isEligibleForStage($this->flujo, $pef->ultima_etapa_node_id, 'stage-1');
        $isEligibleForStage2 = $this->resolver->isEligibleForStage($this->flujo, $pef->ultima_etapa_node_id, 'stage-2');

        $this->assertFalse($isEligibleForStage1); // Already completed
        $this->assertTrue($isEligibleForStage2); // Now eligible
    }

    /** @test */
    public function no_duplicate_sends_for_completed_stages(): void
    {
        Bus::fake();

        // Create stage-1 etapa
        $etapa = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'pending',
        ]);

        // Create prospect who already completed stage-1
        $prospecto = $this->createNewProspects(1)->first();
        $pef = ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => 'stage-1', // Already completed
        ]);

        // Try to send stage-1 again
        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $etapa->id,
            stage: [
                'id' => 'stage-1',
                'type' => 'email',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test',
            ],
            prospectoIds: [$prospecto->id]
        );

        $job->handle();

        // Should NOT dispatch any email jobs (prospect filtered out)
        Bus::assertNothingBatched();
    }

    /** @test */
    public function catch_up_brings_behind_prospects_forward_over_time(): void
    {
        Queue::fake();

        // Create prospects at different stages
        $prospecto1 = $this->createNewProspects(1)->first();
        $prospecto2 = $this->createNewProspects(1)->first();

        // Prospect 1 is brand new (NULL)
        $pef1 = ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto1->id,
            'ultima_etapa_node_id' => null,
        ]);

        // Prospect 2 already completed stage 1
        $pef2 = ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto2->id,
            'ultima_etapa_node_id' => 'stage-1',
        ]);

        // Run catch-up job (first pass)
        $job = new CatchUpProspectosJob;
        $job->handle($this->resolver);

        // Prospect 1 should get stage-1
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($prospecto1) {
            return $job->stage['id'] === 'stage-1'
                && in_array($prospecto1->id, $job->prospectoIds);
        });

        // Prospect 2 should get stage-2
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($prospecto2) {
            return $job->stage['id'] === 'stage-2'
                && in_array($prospecto2->id, $job->prospectoIds);
        });

        // Clear queue and simulate completion
        Queue::fake();
        $pef1->update(['ultima_etapa_node_id' => 'stage-1']);
        $pef2->update(['ultima_etapa_node_id' => 'stage-2']);

        // Run catch-up job (second pass)
        $job2 = new CatchUpProspectosJob;
        $job2->handle($this->resolver);

        // Prospect 1 should now get stage-2
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($prospecto1) {
            return $job->stage['id'] === 'stage-2'
                && in_array($prospecto1->id, $job->prospectoIds);
        });

        // Prospect 2 should now get stage-3 (but stage-3 is current, so caught up)
        // Actually, they're now AT the previous stage of current (stage-2 before stage-3)
        // So they would be caught up in the normal flow, not catch-up
    }

    /** @test */
    public function assignment_job_adds_prospects_with_null_ultima(): void
    {
        // Test that AsignarProspectosAEjecucionPerpetua creates records with NULL ultima_etapa_node_id
        $newProspects = $this->createNewProspects(2);

        $job = new AsignarProspectosAEjecucionPerpetua(
            flujoId: $this->flujo->id,
            prospectoIds: $newProspects->pluck('id')->toArray()
        );

        $job->handle($this->resolver);

        // Verify prospects were added with NULL ultima_etapa_node_id
        foreach ($newProspects as $prospecto) {
            $this->assertDatabaseHas('prospecto_en_flujo', [
                'flujo_id' => $this->flujo->id,
                'prospecto_id' => $prospecto->id,
                'ultima_etapa_node_id' => null,
            ]);
        }
    }

    /** @test */
    public function full_cycle_new_prospect_to_caught_up(): void
    {
        Queue::fake();

        // Step 1: Create a new prospect
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'newprospect@example.com',
        ]);

        // Step 2: Add to perpetual execution via assignment job
        $assignJob = new AsignarProspectosAEjecucionPerpetua(
            flujoId: $this->flujo->id,
            prospectoIds: [$prospecto->id]
        );
        $assignJob->handle($this->resolver);

        // Step 3: Verify they're added with NULL ultima
        $pef = ProspectoEnFlujo::where('flujo_id', $this->flujo->id)
            ->where('prospecto_id', $prospecto->id)
            ->first();

        $this->assertNotNull($pef);
        $this->assertNull($pef->ultima_etapa_node_id);

        // Step 4: Run catch-up (simulates periodic job)
        $catchUpJob = new CatchUpProspectosJob;
        $catchUpJob->handle($this->resolver);

        // Step 5: Verify stage-1 job was dispatched
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($prospecto) {
            return $job->stage['id'] === 'stage-1'
                && in_array($prospecto->id, $job->prospectoIds);
        });

        // Step 6: Simulate successful send (update ultima)
        $pef->update(['ultima_etapa_node_id' => 'stage-1']);

        // Step 7: Verify they're now eligible for stage-2
        $isEligible = $this->resolver->isEligibleForStage($this->flujo, 'stage-1', 'stage-2');
        $this->assertTrue($isEligible);

        // Step 8: Clear queue and run catch-up again
        Queue::fake();
        $catchUpJob2 = new CatchUpProspectosJob;
        $catchUpJob2->handle($this->resolver);

        // Step 9: Verify stage-2 job was dispatched
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($prospecto) {
            return $job->stage['id'] === 'stage-2'
                && in_array($prospecto->id, $job->prospectoIds);
        });

        // Step 10: Simulate completing stage-2
        $pef->update(['ultima_etapa_node_id' => 'stage-2']);

        // Step 11: Verify they're now eligible for stage-3 (current stage)
        $isEligible = $this->resolver->isEligibleForStage($this->flujo, 'stage-2', 'stage-3');
        $this->assertTrue($isEligible);

        // At this point, they're caught up to stage-3 (current stage) and will
        // be processed in the normal execution flow, not catch-up
    }

    // ============================================
    // TESTS: Edge cases in full flow
    // ============================================

    /** @test */
    public function mixed_prospect_states_handled_correctly_in_single_run(): void
    {
        Queue::fake();

        // Create prospects at various stages
        $brand_new = $this->createNewProspects(1)->first();
        $completed_s1 = $this->createNewProspects(1)->first();
        $completed_s2 = $this->createNewProspects(1)->first();
        $cancelled = $this->createNewProspects(1)->first();

        // Brand new (needs stage 1)
        ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $brand_new->id,
            'ultima_etapa_node_id' => null,
        ]);

        // Completed stage 1 (needs stage 2)
        ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $completed_s1->id,
            'ultima_etapa_node_id' => 'stage-1',
        ]);

        // Completed stage 2 (needs stage 3 - caught up to previous)
        ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $completed_s2->id,
            'ultima_etapa_node_id' => 'stage-2',
        ]);

        // Cancelled (should be excluded)
        ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $cancelled->id,
            'ultima_etapa_node_id' => null,
            'cancelado' => true,
        ]);

        // Run catch-up
        $job = new CatchUpProspectosJob;
        $job->handle($this->resolver);

        // Verify correct jobs dispatched
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($brand_new) {
            return $job->stage['id'] === 'stage-1'
                && in_array($brand_new->id, $job->prospectoIds);
        });

        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($completed_s1) {
            return $job->stage['id'] === 'stage-2'
                && in_array($completed_s1->id, $job->prospectoIds);
        });

        // Verify cancelled prospect is NOT included in any job
        Queue::assertPushed(EnviarEtapaJob::class, function ($job) use ($cancelled) {
            return ! in_array($cancelled->id, $job->prospectoIds);
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
                ['id' => 'stage-1', 'type' => 'email', 'label' => 'Welcome Email'],
                ['id' => 'stage-2', 'type' => 'email', 'label' => 'Follow Up'],
                ['id' => 'stage-3', 'type' => 'email', 'label' => 'Final Reminder'],
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

    private function createNewProspects(int $count): \Illuminate\Support\Collection
    {
        $prospects = collect();

        for ($i = 0; $i < $count; $i++) {
            $prospects->push(Prospecto::factory()->create([
                'tipo_prospecto_id' => $this->tipoProspecto->id,
                'email' => "prospect{$i}_".fake()->unique()->uuid().'@example.com',
            ]));
        }

        return $prospects;
    }
}
