<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Batching;

use App\Services\Batching\FixedBatchingStrategy;
use Tests\TestCase;

class FixedBatchingStrategyTest extends TestCase
{
    private FixedBatchingStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        // Set test config values
        config([
            'batching.threshold' => 100,
            'batching.batch_count' => 4,
            'batching.delay_between_batches_minutes' => 5,
            'batching.max_batch_size' => 50,
        ]);

        $this->strategy = new FixedBatchingStrategy();
    }

    /** @test */
    public function should_batch_returns_false_when_below_threshold(): void
    {
        $this->assertFalse($this->strategy->shouldBatch(50));
        $this->assertFalse($this->strategy->shouldBatch(100));
    }

    /** @test */
    public function should_batch_returns_true_when_above_threshold(): void
    {
        $this->assertTrue($this->strategy->shouldBatch(101));
        $this->assertTrue($this->strategy->shouldBatch(1000));
    }

    /** @test */
    public function create_batches_returns_empty_array_for_empty_input(): void
    {
        $batches = $this->strategy->createBatches([]);

        $this->assertEmpty($batches);
    }

    /** @test */
    public function create_batches_returns_single_batch_when_below_threshold(): void
    {
        $ids = range(1, 50);

        $batches = $this->strategy->createBatches($ids);

        $this->assertCount(1, $batches);
        $this->assertEquals($ids, $batches[0]);
    }

    /** @test */
    public function create_batches_divides_into_multiple_batches_when_above_threshold(): void
    {
        $ids = range(1, 200);

        $batches = $this->strategy->createBatches($ids);

        // 200 / 4 = 50 per batch
        $this->assertCount(4, $batches);
        $this->assertCount(50, $batches[0]);
        $this->assertCount(50, $batches[1]);
        $this->assertCount(50, $batches[2]);
        $this->assertCount(50, $batches[3]);
    }

    /** @test */
    public function create_batches_respects_max_batch_size(): void
    {
        // Con 500 IDs y max_batch_size=50, debería crear más de 4 batches
        $ids = range(1, 500);

        $batches = $this->strategy->createBatches($ids);

        // 500 / 4 = 125, pero max es 50, así que será 500 / 50 = 10 batches
        $this->assertCount(10, $batches);

        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(50, count($batch));
        }
    }

    /** @test */
    public function create_batches_preserves_all_ids(): void
    {
        $ids = range(1, 200);

        $batches = $this->strategy->createBatches($ids);

        $allIds = array_merge(...$batches);
        sort($allIds);

        $this->assertEquals($ids, $allIds);
    }

    /** @test */
    public function get_delay_for_batch_returns_zero_for_first_batch(): void
    {
        $delay = $this->strategy->getDelayForBatch(0, 4);

        $this->assertEquals(0, $delay);
    }

    /** @test */
    public function get_delay_for_batch_returns_incremental_delays(): void
    {
        $this->assertEquals(0, $this->strategy->getDelayForBatch(0, 4));
        $this->assertEquals(5, $this->strategy->getDelayForBatch(1, 4));
        $this->assertEquals(10, $this->strategy->getDelayForBatch(2, 4));
        $this->assertEquals(15, $this->strategy->getDelayForBatch(3, 4));
    }

    /** @test */
    public function get_config_returns_all_configuration_values(): void
    {
        $config = $this->strategy->getConfig();

        $this->assertArrayHasKey('threshold', $config);
        $this->assertArrayHasKey('batch_count', $config);
        $this->assertArrayHasKey('delay_minutes', $config);
        $this->assertArrayHasKey('max_batch_size', $config);

        $this->assertEquals(100, $config['threshold']);
        $this->assertEquals(4, $config['batch_count']);
        $this->assertEquals(5, $config['delay_minutes']);
        $this->assertEquals(50, $config['max_batch_size']);
    }

    /** @test */
    public function handles_uneven_division(): void
    {
        // 103 IDs con 4 batches = 26, 26, 26, 25
        $ids = range(1, 103);

        $batches = $this->strategy->createBatches($ids);

        $totalFromBatches = array_sum(array_map('count', $batches));
        $this->assertEquals(103, $totalFromBatches);
    }
}
