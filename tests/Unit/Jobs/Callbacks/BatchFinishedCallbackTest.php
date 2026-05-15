<?php

namespace Tests\Unit\Jobs\Callbacks;

use App\Jobs\Callbacks\BatchFinishedCallback;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Regression test for the 2026-05-14 incident.
 *
 * Background: Bus::batch->then() (BatchCompletedCallback) is only invoked
 * if ALL jobs succeed. With allowFailures() + any failed job, only catch()
 * and finally() fire. Neither used to update the etapa's estado, leaving
 * etapas stuck in `executing` forever (caso real: etapa 457 con 2 meses
 * atascada).
 *
 * Fix (commit b94a8d6): BatchFinishedCallback acts as a safety net. If
 * then() didn't run, delegate to BatchCompletedCallback. Idempotent.
 */
class BatchFinishedCallbackTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_is_a_noop_when_etapa_already_completed(): void
    {
        $etapa = $this->makeEtapa(['estado' => 'completed']);

        $callback = new BatchFinishedCallback($this->callbackData($etapa));
        $batch = $this->fakeBatch(failedJobs: 5);

        $callback($batch);

        $etapa->refresh();
        $this->assertEquals('completed', $etapa->estado);
    }

    /** @test */
    public function it_does_nothing_when_etapa_id_missing_from_callback_data(): void
    {
        $callback = new BatchFinishedCallback([]); // no etapa_ejecucion_id key

        // Should not throw.
        $callback($this->fakeBatch());

        $this->assertTrue(true); // assertion: did not throw
    }

    /** @test */
    public function it_does_nothing_when_etapa_does_not_exist(): void
    {
        $callback = new BatchFinishedCallback([
            'etapa_ejecucion_id' => 99999,
            'flujo_ejecucion_id' => 99999,
            'stage' => ['id' => 'stage-x'],
            'prospecto_ids' => [],
            'branches' => [],
            'total_jobs' => 0,
        ]);

        // Should not throw.
        $callback($this->fakeBatch(failedJobs: 1));

        $this->assertTrue(true);
    }

    /** @test */
    public function it_promotes_executing_etapa_to_completed_when_then_did_not_fire(): void
    {
        $etapa = $this->makeEtapa(['estado' => 'executing']);

        // No outbound branches → BatchCompletedCallback::finalizarFlujo()
        // is the simplest path that doesn't trigger more job dispatch.
        $callback = new BatchFinishedCallback([
            'etapa_ejecucion_id' => $etapa->id,
            'flujo_ejecucion_id' => $etapa->flujo_ejecucion_id,
            'stage' => ['id' => 'stage-orphan'],
            'prospecto_ids' => [],
            'branches' => [], // no next node
            'total_jobs' => 10,
        ]);

        $callback($this->fakeBatch(failedJobs: 3, processedJobs: 7));

        $etapa->refresh();
        $this->assertEquals('completed', $etapa->estado);
        $this->assertTrue($etapa->ejecutado);
    }

    private function makeEtapa(array $overrides = []): FlujoEjecucionEtapa
    {
        $flujo = Flujo::factory()->create();
        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $flujo->id,
        ]);

        return FlujoEjecucionEtapa::factory()->create(array_merge([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => 'stage-test',
            'estado' => 'executing',
            'prospectos_count' => 0,
            'prospectos_ids' => [],
        ], $overrides));
    }

    private function callbackData(FlujoEjecucionEtapa $etapa): array
    {
        return [
            'etapa_ejecucion_id' => $etapa->id,
            'flujo_ejecucion_id' => $etapa->flujo_ejecucion_id,
            'stage' => ['id' => 'stage-test'],
            'prospecto_ids' => [],
            'branches' => [],
            'total_jobs' => 0,
        ];
    }

    private function fakeBatch(int $failedJobs = 0, int $processedJobs = 0, int $pendingJobs = 0): Batch
    {
        $batch = Mockery::mock(Batch::class);
        $batch->id = 'test-batch-'.uniqid();
        $batch->failedJobs = $failedJobs;
        $batch->pendingJobs = $pendingJobs;
        $batch->shouldReceive('processedJobs')->andReturn($processedJobs);

        return $batch;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
