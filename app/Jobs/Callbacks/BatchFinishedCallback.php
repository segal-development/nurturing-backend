<?php

namespace App\Jobs\Callbacks;

use App\Models\FlujoEjecucionEtapa;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Log;

/**
 * Callback para cuando un batch finaliza (éxito o fallo).
 * Usa clase invocable en lugar de closure para evitar problemas de serialización en Laravel 12.
 *
 * Esta clase también actúa como red de seguridad: si el batch finalizó con
 * fallos parciales, `then()` (BatchCompletedCallback) NUNCA se invoca y la
 * etapa queda eternamente en estado `executing`. Aquí garantizamos que el
 * estado se complete y el flujo avance.
 */
class BatchFinishedCallback
{
    public function __construct(
        public array $callbackData
    ) {}

    public function __invoke(Batch $batch): void
    {
        Log::info('BatchFinishedCallback: Batch finalizado', [
            'batch_id' => $batch->id,
            'total_processed' => $batch->processedJobs(),
            'total_failed' => $batch->failedJobs,
            'pending' => $batch->pendingJobs,
            'etapa_ejecucion_id' => $this->callbackData['etapa_ejecucion_id'] ?? null,
        ]);

        $etapaId = $this->callbackData['etapa_ejecucion_id'] ?? null;
        if (! $etapaId) {
            return;
        }

        $etapa = FlujoEjecucionEtapa::find($etapaId);
        if (! $etapa) {
            return;
        }

        // Idempotency: if then() already ran, etapa is already completed.
        // Skip to avoid scheduling the next stage twice.
        if ($etapa->estado === 'completed') {
            return;
        }

        // then() never fired (because batch had failures despite allowFailures()).
        // Delegate to BatchCompletedCallback to mark completed + schedule next node.
        Log::info('BatchFinishedCallback: then() did not fire (partial failures), delegating to completion logic', [
            'etapa_ejecucion_id' => $etapaId,
            'failed_jobs' => $batch->failedJobs,
        ]);

        (new BatchCompletedCallback($this->callbackData))($batch);
    }
}
