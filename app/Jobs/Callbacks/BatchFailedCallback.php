<?php

namespace App\Jobs\Callbacks;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Callback para cuando un batch tiene errores.
 * Usa clase invocable en lugar de closure para evitar problemas de serialización en Laravel 12.
 */
class BatchFailedCallback
{
    public function __construct(
        public array $callbackData
    ) {}

    public function __invoke(Batch $batch, Throwable $e): void
    {
        Log::error('BatchFailedCallback: Batch con errores', [
            'batch_id' => $batch->id,
            'error' => $e->getMessage(),
            'failed_jobs' => $batch->failedJobs,
            'etapa_ejecucion_id' => $this->callbackData['etapa_ejecucion_id'],
        ]);
    }
}
