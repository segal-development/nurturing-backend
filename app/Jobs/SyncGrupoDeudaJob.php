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
 * Job para sincronizar contratos nuevos desde la API de Grupo Deudas.
 *
 * Se puede ejecutar:
 * - Programado (cada hora)
 * - Manualmente desde el admin o comando
 *
 * Usa sync incremental basado en last_synced_at.
 */
class SyncGrupoDeudaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 60;

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
        Log::info('SyncGrupoDeudaJob: Iniciando', [
            'user_id' => $this->userId,
        ]);

        $source = $this->getGrupoDeudaSource();

        if (! $source) {
            Log::warning('SyncGrupoDeudaJob: No se encontró fuente activa de Grupo Deudas');

            return;
        }

        try {
            $service = new GrupoDeudaApiSyncService;
            $resultado = $service->sync($source, $this->userId);

            Log::info('SyncGrupoDeudaJob: Sincronización completada', [
                'source' => $source->name,
                'lotes_creados' => count($resultado['lotes']),
                'total_prospectos' => $resultado['total_prospectos'],
                'nuevos' => $resultado['nuevos'],
                'actualizados' => $resultado['actualizados'],
                'omitidos_en_flujo' => $resultado['omitidos_en_flujo'],
            ]);

        } catch (\Exception $e) {
            $isClientError = preg_match('/Error HTTP (4\d{2})/', $e->getMessage());

            Log::error('SyncGrupoDeudaJob: Error en sincronización', [
                'source' => $source->name,
                'error' => $e->getMessage(),
                'will_retry' => ! $isClientError,
            ]);

            if ($isClientError) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    /**
     * Obtiene la fuente de Grupo Deudas.
     */
    private function getGrupoDeudaSource(): ?ExternalApiSource
    {
        return ExternalApiSource::where('name', 'grupo_deuda_contratos')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncGrupoDeudaJob: Job falló definitivamente', [
            'error' => $exception->getMessage(),
        ]);

        $source = $this->getGrupoDeudaSource();
        $source?->markAsFailed("Job failed after {$this->tries} attempts: {$exception->getMessage()}");
    }
}
