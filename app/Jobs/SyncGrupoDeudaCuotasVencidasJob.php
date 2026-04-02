<?php

namespace App\Jobs;

use App\Models\ExternalApiSource;
use App\Services\GrupoDeudaApiSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para sincronizar cuotas vencidas desde la API de Grupo Deudas.
 *
 * Endpoint: /CuotasVencidas
 * Trae cuotas que vencieron hoy (morosos) para seguimiento de cobro.
 *
 * Se ejecuta diariamente a las 7am.
 * Siempre trae datos del día actual (no es incremental).
 */
class SyncGrupoDeudaCuotasVencidasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número máximo de intentos.
     */
    public int $tries = 3;

    /**
     * Tiempo máximo de ejecución (5 minutos).
     */
    public int $timeout = 300;

    /**
     * ID del usuario que ejecutó la sincronización (null = sistema).
     */
    private ?int $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(?int $userId = null)
    {
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('SyncGrupoDeudaCuotasVencidasJob: Iniciando', [
            'user_id' => $this->userId,
        ]);

        $source = $this->getSource();

        if (! $source) {
            Log::warning('SyncGrupoDeudaCuotasVencidasJob: No se encontró fuente activa');

            return;
        }

        try {
            $service = new GrupoDeudaApiSyncService;
            $resultado = $service->syncCuotasVencidas($source, $this->userId);

            Log::info('SyncGrupoDeudaCuotasVencidasJob: Sincronización completada', [
                'source' => $source->name,
                'lotes_creados' => count($resultado['lotes']),
                'total_prospectos' => $resultado['total_prospectos'],
                'nuevos' => $resultado['nuevos'],
                'actualizados' => $resultado['actualizados'],
                'omitidos_en_flujo' => $resultado['omitidos_en_flujo'],
            ]);

        } catch (\Exception $e) {
            Log::error('SyncGrupoDeudaCuotasVencidasJob: Error en sincronización', [
                'source' => $source->name,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtiene la fuente de cuotas vencidas.
     */
    private function getSource(): ?ExternalApiSource
    {
        return ExternalApiSource::where('name', 'grupo_deuda_cuotas_vencidas')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncGrupoDeudaCuotasVencidasJob: Job falló definitivamente', [
            'error' => $exception->getMessage(),
        ]);

        $source = $this->getSource();
        $source?->markAsFailed("Job failed after {$this->tries} attempts: {$exception->getMessage()}");
    }
}
