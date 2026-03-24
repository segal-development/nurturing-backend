<?php

namespace Tests\Unit\Services;

use App\Models\Flujo;
use App\Services\StageOrderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for StageOrderResolver service.
 *
 * Tests the stage ordering logic used by:
 * - EnviarEtapaJob (filtering by previous stage completion)
 * - CatchUpProspectosJob (determining next stage for behind-prospects)
 * - BackfillUltimaEtapaCommand (validating stage progression)
 */
class StageOrderResolverTest extends TestCase
{
    use RefreshDatabase;

    private StageOrderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new StageOrderResolver;
    }

    // ============================================
    // TESTS: getStageOrder()
    // ============================================

    /** @test */
    public function get_stage_order_returns_ordered_array_of_node_ids(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
                'stage-3' => 'sms',
            ]),
        ]);

        $stageOrder = $this->resolver->getStageOrder($flujo);

        // getStageOrder includes end node for completion detection
        $this->assertCount(4, $stageOrder);
        $this->assertEquals(['stage-1', 'stage-2', 'stage-3', 'end-1'], $stageOrder);
    }

    /** @test */
    public function get_stage_order_handles_empty_flow_gracefully(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => [],
        ]);

        $stageOrder = $this->resolver->getStageOrder($flujo);

        $this->assertIsArray($stageOrder);
        $this->assertEmpty($stageOrder);
    }

    /** @test */
    public function get_stage_order_handles_flow_with_only_start_and_end_nodes(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => [
                'stages' => [
                    ['id' => 'start-1', 'type' => 'start', 'label' => 'Inicio'],
                    ['id' => 'end-1', 'type' => 'end', 'label' => 'Fin'],
                ],
                'branches' => [
                    ['source_node_id' => 'start-1', 'target_node_id' => 'end-1'],
                ],
                'initial_node' => 'start-1',
            ],
        ]);

        $stageOrder = $this->resolver->getStageOrder($flujo);

        // End node is included in order but filtered out for executable stages
        $this->assertContains('end-1', $stageOrder);
    }

    /** @test */
    public function get_stage_order_skips_condition_nodes(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => [
                'stages' => [
                    ['id' => 'start-1', 'type' => 'start'],
                    ['id' => 'stage-1', 'type' => 'email'],
                    ['id' => 'condition-1', 'type' => 'condition'],
                    ['id' => 'stage-2', 'type' => 'email'],
                    ['id' => 'end-1', 'type' => 'end'],
                ],
                'branches' => [
                    ['source_node_id' => 'start-1', 'target_node_id' => 'stage-1'],
                    ['source_node_id' => 'stage-1', 'target_node_id' => 'condition-1'],
                    ['source_node_id' => 'condition-1', 'target_node_id' => 'stage-2'],
                    ['source_node_id' => 'stage-2', 'target_node_id' => 'end-1'],
                ],
                'initial_node' => 'start-1',
            ],
        ]);

        $stageOrder = $this->resolver->getStageOrder($flujo);

        // Condition nodes should be skipped
        $this->assertNotContains('condition-1', $stageOrder);
        $this->assertContains('stage-1', $stageOrder);
        $this->assertContains('stage-2', $stageOrder);
    }

    /** @test */
    public function get_stage_order_handles_single_stage_flow(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-solo' => 'email',
            ]),
        ]);

        $stageOrder = $this->resolver->getStageOrder($flujo);

        // Single executable stage + end node
        $this->assertCount(2, $stageOrder);
        $this->assertEquals('stage-solo', $stageOrder[0]);
        $this->assertEquals('end-1', $stageOrder[1]);
    }

    // ============================================
    // TESTS: getFirstStage()
    // ============================================

    /** @test */
    public function get_first_stage_returns_correct_first_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
                'stage-3' => 'sms',
            ]),
        ]);

        $firstStage = $this->resolver->getFirstStage($flujo);

        $this->assertEquals('stage-1', $firstStage);
    }

    /** @test */
    public function get_first_stage_returns_null_for_empty_flow(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => [],
        ]);

        $firstStage = $this->resolver->getFirstStage($flujo);

        $this->assertNull($firstStage);
    }

    /** @test */
    public function get_first_stage_returns_first_executable_stage_not_start_node(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'email-stage' => 'email',
                'sms-stage' => 'sms',
            ]),
        ]);

        $firstStage = $this->resolver->getFirstStage($flujo);

        $this->assertNotEquals('start-1', $firstStage);
        $this->assertEquals('email-stage', $firstStage);
    }

    // ============================================
    // TESTS: getPreviousStage()
    // ============================================

    /** @test */
    public function get_previous_stage_returns_null_for_first_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
            ]),
        ]);

        $previousStage = $this->resolver->getPreviousStage($flujo, 'stage-1');

        $this->assertNull($previousStage);
    }

    /** @test */
    public function get_previous_stage_returns_correct_previous_for_later_stages(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
                'stage-3' => 'sms',
            ]),
        ]);

        $this->assertEquals('stage-1', $this->resolver->getPreviousStage($flujo, 'stage-2'));
        $this->assertEquals('stage-2', $this->resolver->getPreviousStage($flujo, 'stage-3'));
    }

    /** @test */
    public function get_previous_stage_returns_null_for_unknown_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
            ]),
        ]);

        $previousStage = $this->resolver->getPreviousStage($flujo, 'unknown-stage');

        $this->assertNull($previousStage);
    }

    // ============================================
    // TESTS: getNextStage()
    // ============================================

    /** @test */
    public function get_next_stage_returns_correct_next_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
                'stage-3' => 'sms',
            ]),
        ]);

        $this->assertEquals('stage-2', $this->resolver->getNextStage($flujo, 'stage-1'));
        $this->assertEquals('stage-3', $this->resolver->getNextStage($flujo, 'stage-2'));
    }

    /** @test */
    public function get_next_stage_returns_null_for_last_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
                'stage-3' => 'sms',
            ]),
        ]);

        $nextStage = $this->resolver->getNextStage($flujo, 'stage-3');

        $this->assertNull($nextStage);
    }

    /** @test */
    public function get_next_stage_returns_null_for_unknown_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
            ]),
        ]);

        $nextStage = $this->resolver->getNextStage($flujo, 'unknown-stage');

        $this->assertNull($nextStage);
    }

    // ============================================
    // TESTS: isEligibleForStage()
    // ============================================

    /** @test */
    public function is_eligible_for_stage_returns_true_for_first_stage_with_null_ultima(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
            ]),
        ]);

        $isEligible = $this->resolver->isEligibleForStage($flujo, null, 'stage-1');

        $this->assertTrue($isEligible);
    }

    /** @test */
    public function is_eligible_for_stage_returns_false_for_non_first_stage_with_null_ultima(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
            ]),
        ]);

        $isEligible = $this->resolver->isEligibleForStage($flujo, null, 'stage-2');

        $this->assertFalse($isEligible);
    }

    /** @test */
    public function is_eligible_for_stage_returns_true_when_ultima_matches_previous_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
                'stage-3' => 'sms',
            ]),
        ]);

        // Prospect completed stage-1, should be eligible for stage-2
        $this->assertTrue($this->resolver->isEligibleForStage($flujo, 'stage-1', 'stage-2'));

        // Prospect completed stage-2, should be eligible for stage-3
        $this->assertTrue($this->resolver->isEligibleForStage($flujo, 'stage-2', 'stage-3'));
    }

    /** @test */
    public function is_eligible_for_stage_returns_false_when_ultima_doesnt_match_previous(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
                'stage-3' => 'sms',
            ]),
        ]);

        // Prospect completed stage-1, should NOT be eligible for stage-3 (skipping stage-2)
        $isEligible = $this->resolver->isEligibleForStage($flujo, 'stage-1', 'stage-3');

        $this->assertFalse($isEligible);
    }

    /** @test */
    public function is_eligible_for_stage_returns_false_when_already_completed_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
            ]),
        ]);

        // Prospect already completed stage-1, should NOT be eligible for stage-1 again
        $isEligible = $this->resolver->isEligibleForStage($flujo, 'stage-1', 'stage-1');

        $this->assertFalse($isEligible);
    }

    /** @test */
    public function is_eligible_for_stage_returns_false_for_unknown_target_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
            ]),
        ]);

        $isEligible = $this->resolver->isEligibleForStage($flujo, null, 'unknown-stage');

        $this->assertFalse($isEligible);
    }

    /** @test */
    public function is_eligible_for_stage_handles_legacy_ultima_not_in_current_stages(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
            ]),
        ]);

        // Prospect has an orphan/legacy ultima_etapa that doesn't exist in current flow
        // Should treat as NULL and only be eligible for first stage
        $isEligible = $this->resolver->isEligibleForStage($flujo, 'old-deleted-stage', 'stage-1');

        $this->assertTrue($isEligible);
    }

    // ============================================
    // TESTS: getStagePosition()
    // ============================================

    /** @test */
    public function get_stage_position_returns_correct_index(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
                'stage-2' => 'email',
                'stage-3' => 'sms',
            ]),
        ]);

        $this->assertEquals(0, $this->resolver->getStagePosition($flujo, 'stage-1'));
        $this->assertEquals(1, $this->resolver->getStagePosition($flujo, 'stage-2'));
        $this->assertEquals(2, $this->resolver->getStagePosition($flujo, 'stage-3'));
    }

    /** @test */
    public function get_stage_position_returns_null_for_unknown_stage(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => $this->createLinearFlowStructure([
                'stage-1' => 'email',
            ]),
        ]);

        $position = $this->resolver->getStagePosition($flujo, 'unknown-stage');

        $this->assertNull($position);
    }

    // ============================================
    // TESTS: Real-world flow structure scenarios
    // ============================================

    /** @test */
    public function handles_complex_flow_with_multiple_stage_types(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => [
                'stages' => [
                    ['id' => 'start-1', 'type' => 'start'],
                    ['id' => 'email-1', 'type' => 'email', 'label' => 'Welcome Email'],
                    ['id' => 'sms-1', 'type' => 'sms', 'label' => 'Reminder SMS'],
                    ['id' => 'stage-1', 'type' => 'stage', 'label' => 'Generic Stage'],
                    ['id' => 'email-2', 'type' => 'email', 'label' => 'Follow-up'],
                    ['id' => 'end-1', 'type' => 'end'],
                ],
                'branches' => [
                    ['source_node_id' => 'start-1', 'target_node_id' => 'email-1'],
                    ['source_node_id' => 'email-1', 'target_node_id' => 'sms-1'],
                    ['source_node_id' => 'sms-1', 'target_node_id' => 'stage-1'],
                    ['source_node_id' => 'stage-1', 'target_node_id' => 'email-2'],
                    ['source_node_id' => 'email-2', 'target_node_id' => 'end-1'],
                ],
                'initial_node' => 'start-1',
            ],
        ]);

        $stageOrder = $this->resolver->getStageOrder($flujo);

        // Should include email, sms, stage types (and end for completion detection)
        $this->assertContains('email-1', $stageOrder);
        $this->assertContains('sms-1', $stageOrder);
        $this->assertContains('stage-1', $stageOrder);
        $this->assertContains('email-2', $stageOrder);

        // First executable stage should be email-1
        $this->assertEquals('email-1', $this->resolver->getFirstStage($flujo));
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Create a simple linear flow structure with the given stages.
     *
     * @param  array  $stageTypes  Associative array of node_id => type (email, sms, stage)
     */
    private function createLinearFlowStructure(array $stageTypes): array
    {
        $stages = [
            ['id' => 'start-1', 'type' => 'start', 'label' => 'Inicio'],
        ];
        $branches = [];
        $previousId = 'start-1';

        foreach ($stageTypes as $nodeId => $type) {
            $stages[] = [
                'id' => $nodeId,
                'type' => $type,
                'label' => ucfirst($type).' - '.$nodeId,
            ];

            $branches[] = [
                'source_node_id' => $previousId,
                'target_node_id' => $nodeId,
            ];

            $previousId = $nodeId;
        }

        // Add end node
        $stages[] = ['id' => 'end-1', 'type' => 'end', 'label' => 'Fin'];
        $branches[] = [
            'source_node_id' => $previousId,
            'target_node_id' => 'end-1',
        ];

        return [
            'stages' => $stages,
            'branches' => $branches,
            'initial_node' => 'start-1',
        ];
    }
}
