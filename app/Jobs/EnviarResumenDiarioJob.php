<?php

namespace App\Jobs;

use App\Services\AlertasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para enviar el resumen diario de métricas de envíos.
 * 
 * Se ejecuta automáticamente todos los días a la hora configurada.
 */
class EnviarResumenDiarioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function handle(AlertasService $alertasService): void
    {
        Log::info('[ResumenDiario] Iniciando envío de resumen diario');

        try {
            $alertasService->enviarResumenDiario();
            Log::info('[ResumenDiario] Resumen diario enviado exitosamente');
        } catch (\Exception $e) {
            Log::error('[ResumenDiario] Error enviando resumen diario', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
