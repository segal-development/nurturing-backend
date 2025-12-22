<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Batching;

use App\Services\Batching\BatchResult;
use Tests\TestCase;

class BatchResultTest extends TestCase
{
    /** @test */
    public function direct_creates_result_for_direct_send(): void
    {
        $prospectoIds = [1, 2, 3, 4, 5];

        $result = BatchResult::direct($prospectoIds);

        $this->assertFalse($result->requiresBatching);
        $this->assertEmpty($result->batches);
        $this->assertEquals(0, $result->totalBatches);
        $this->assertEquals(5, $result->totalProspectos);
        $this->assertEquals($prospectoIds, $result->directProspectoIds);
    }

    /** @test */
    public function batched_creates_result_for_batch_send(): void
    {
        $batches = [
            ['batch_number' => 1, 'size' => 100, 'delay_minutes' => 0, 'is_last' => false],
            ['batch_number' => 2, 'size' => 100, 'delay_minutes' => 10, 'is_last' => false],
            ['batch_number' => 3, 'size' => 100, 'delay_minutes' => 20, 'is_last' => true],
        ];

        $result = BatchResult::batched($batches, 3, 300);

        $this->assertTrue($result->requiresBatching);
        $this->assertCount(3, $result->batches);
        $this->assertEquals(3, $result->totalBatches);
        $this->assertEquals(300, $result->totalProspectos);
        $this->assertEmpty($result->directProspectoIds);
    }

    /** @test */
    public function empty_creates_result_with_no_prospectos(): void
    {
        $result = BatchResult::empty();

        $this->assertFalse($result->requiresBatching);
        $this->assertEmpty($result->batches);
        $this->assertEquals(0, $result->totalBatches);
        $this->assertEquals(0, $result->totalProspectos);
        $this->assertEmpty($result->directProspectoIds);
    }

    /** @test */
    public function has_prospectos_returns_true_when_prospectos_exist(): void
    {
        $result = BatchResult::direct([1, 2, 3]);

        $this->assertTrue($result->hasProspectos());
    }

    /** @test */
    public function has_prospectos_returns_false_when_empty(): void
    {
        $result = BatchResult::empty();

        $this->assertFalse($result->hasProspectos());
    }

    /** @test */
    public function is_direct_send_returns_true_for_direct_sends(): void
    {
        $result = BatchResult::direct([1, 2, 3]);

        $this->assertTrue($result->isDirectSend());
    }

    /** @test */
    public function is_direct_send_returns_false_for_batched_sends(): void
    {
        $result = BatchResult::batched([
            ['batch_number' => 1, 'size' => 100, 'delay_minutes' => 0, 'is_last' => true],
        ], 1, 100);

        $this->assertFalse($result->isDirectSend());
    }

    /** @test */
    public function is_direct_send_returns_false_for_empty(): void
    {
        $result = BatchResult::empty();

        $this->assertFalse($result->isDirectSend());
    }

    /** @test */
    public function get_estimated_completion_minutes_returns_last_batch_delay(): void
    {
        $batches = [
            ['batch_number' => 1, 'size' => 100, 'delay_minutes' => 0, 'is_last' => false],
            ['batch_number' => 2, 'size' => 100, 'delay_minutes' => 10, 'is_last' => false],
            ['batch_number' => 3, 'size' => 100, 'delay_minutes' => 20, 'is_last' => true],
        ];

        $result = BatchResult::batched($batches, 3, 300);

        $this->assertEquals(20, $result->getEstimatedCompletionMinutes());
    }

    /** @test */
    public function get_estimated_completion_minutes_returns_zero_for_direct(): void
    {
        $result = BatchResult::direct([1, 2, 3]);

        $this->assertEquals(0, $result->getEstimatedCompletionMinutes());
    }

    /** @test */
    public function to_array_returns_all_data(): void
    {
        $batches = [
            ['batch_number' => 1, 'size' => 100, 'delay_minutes' => 0, 'is_last' => false],
            ['batch_number' => 2, 'size' => 100, 'delay_minutes' => 10, 'is_last' => true],
        ];

        $result = BatchResult::batched($batches, 2, 200);
        $array = $result->toArray();

        $this->assertArrayHasKey('requires_batching', $array);
        $this->assertArrayHasKey('total_batches', $array);
        $this->assertArrayHasKey('total_prospectos', $array);
        $this->assertArrayHasKey('estimated_completion_minutes', $array);
        $this->assertArrayHasKey('batches', $array);

        $this->assertTrue($array['requires_batching']);
        $this->assertEquals(2, $array['total_batches']);
        $this->assertEquals(200, $array['total_prospectos']);
        $this->assertEquals(10, $array['estimated_completion_minutes']);
        $this->assertCount(2, $array['batches']);
    }

    /** @test */
    public function batch_result_is_immutable(): void
    {
        $result = BatchResult::direct([1, 2, 3]);

        // BatchResult es readonly, esto es más una verificación conceptual
        $this->assertInstanceOf(BatchResult::class, $result);
        $this->assertEquals(3, $result->totalProspectos);
    }
}
