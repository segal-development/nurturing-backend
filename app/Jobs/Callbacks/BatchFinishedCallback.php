<?php

namespace App\Jobs\Callbacks;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Log;

/**
 * Callback para cuando un batch finaliza (éxito o fallo).
 * Usa clase invocable en lugar de closure para evitar problemas de serialización en Laravel 12.
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
        ]);
    }
}
