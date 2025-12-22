<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Batching;

use App\Contracts\Batching\BatchingStrategyInterface;
use App\Services\Batching\EnvioBatchService;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for EnvioBatchService.
 * 
 * Note: Tests that require database (RefreshDatabase) are in Feature tests.
 * These are pure unit tests that mock the strategy.
 */
class EnvioBatchServiceTest extends TestCase
{
    private EnvioBatchService $service;

    private BatchingStrategyInterface $mockStrategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockStrategy = Mockery::mock(BatchingStrategyInterface::class);
        $this->service = new EnvioBatchService($this->mockStrategy);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function should_use_batching_delegates_to_strategy(): void
    {
        $this->mockStrategy->shouldReceive('shouldBatch')
            ->with(100)
            ->once()
            ->andReturn(true);

        $result = $this->service->shouldUseBatching(100);

        $this->assertTrue($result);
    }

    /** @test */
    public function should_use_batching_returns_false_when_below_threshold(): void
    {
        $this->mockStrategy->shouldReceive('shouldBatch')
            ->with(50)
            ->once()
            ->andReturn(false);

        $result = $this->service->shouldUseBatching(50);

        $this->assertFalse($result);
    }

    /** @test */
    public function dispatch_batches_returns_empty_for_no_prospectos(): void
    {
        $result = $this->service->dispatchBatches(
            flujoEjecucionId: 1,
            etapaEjecucionId: 1,
            prospectoIds: [],
            stage: ['id' => 'stage1'],
            branches: []
        );

        $this->assertFalse($result->hasProspectos());
        $this->assertEquals(0, $result->totalProspectos);
    }

    /** @test */
    public function dispatch_batches_returns_direct_when_below_threshold(): void
    {
        $prospectoIds = [1, 2, 3, 4, 5];

        $this->mockStrategy->shouldReceive('shouldBatch')
            ->with(5)
            ->once()
            ->andReturn(false);

        $this->mockStrategy->shouldReceive('getConfig')
            ->once()
            ->andReturn(['threshold' => 100]);

        $result = $this->service->dispatchBatches(
            flujoEjecucionId: 1,
            etapaEjecucionId: 1,
            prospectoIds: $prospectoIds,
            stage: ['id' => 'stage1'],
            branches: []
        );

        $this->assertTrue($result->isDirectSend());
        $this->assertEquals($prospectoIds, $result->directProspectoIds);
    }

    /** @test */
    public function get_config_returns_strategy_config(): void
    {
        $expectedConfig = [
            'threshold' => 20000,
            'batch_count' => 24,
            'delay_minutes' => 10,
        ];

        $this->mockStrategy->shouldReceive('getConfig')
            ->once()
            ->andReturn($expectedConfig);

        $config = $this->service->getConfig();

        $this->assertEquals($expectedConfig, $config);
    }
}
