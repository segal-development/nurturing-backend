<?php

namespace App\Jobs;

use App\Models\ExternalApiSource;
use App\Services\ExternalApiSyncService;
use App\Services\SysgalApiSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para sincronizar prospectos desde APIs externas.
 *
 * Soporta:
 * - Informes Comerciales (GET con paginación)
 * - Sysgal (POST con rango de fechas)
 *
 * Se puede ejecutar:
 * - Programado (cada viernes a las 6am)
 * - Manualmente desde el admin
 * - Para una fuente específica
 */
class SyncExternalApiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número máximo de intentos.
     */
    public int $tries = 3;

    /**
     * Tiempo máximo de ejecución (10 minutos para syncs grandes).
     */
    public int $timeout = 600;

    /**
     * ID de la fuente a sincronizar (null = todas las activas).
     */
    private ?int $sourceId;

    /**
     * ID del usuario que ejecutó la sincronización (null = sistema).
     */
    private ?int $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(?int $sourceId = null, ?int $userId = null)
    {
        $this->sourceId = $sourceId;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('SyncExternalApiJob: Iniciando', [
            'source_id' => $this->sourceId,
            'user_id' => $this->userId,
        ]);

        if ($this->sourceId !== null) {
            // Sincronizar una fuente específica
            $this->syncSingleSource();
        } else {
            // Sincronizar todas las fuentes activas
            $this->syncAllSources();
        }
    }

    /**
     * Determina si una fuente es de Sysgal.
     */
    private function isSysgalSource(ExternalApiSource $source): bool
    {
        return str_starts_with($source->name, 'sysgal_');
    }

    /**
     * Obtiene el servicio apropiado para una fuente.
     */
    private function getSyncService(ExternalApiSource $source): ExternalApiSyncService|SysgalApiSyncService
    {
        if ($this->isSysgalSource($source)) {
            return new SysgalApiSyncService;
        }

        return new ExternalApiSyncService;
    }

    /**
     * Sincroniza una fuente específica.
     */
    private function syncSingleSource(): void
    {
        $source = ExternalApiSource::find($this->sourceId);

        if (! $source) {
            Log::warning('SyncExternalApiJob: Fuente no encontrada', [
                'source_id' => $this->sourceId,
            ]);

            return;
        }

        if (! $source->is_active) {
            Log::info('SyncExternalApiJob: Fuente inactiva, omitiendo', [
                'source' => $source->name,
            ]);

            return;
        }

        try {
            $service = $this->getSyncService($source);
            $resultado = $service->sync($source, $this->userId);

            Log::info('SyncExternalApiJob: Fuente sincronizada', [
                'source' => $source->name,
                'service' => get_class($service),
                'lotes_creados' => count($resultado['lotes']),
                'total_prospectos' => $resultado['total_prospectos'],
                'nuevos' => $resultado['nuevos'],
                'actualizados' => $resultado['actualizados'],
                'omitidos_en_flujo' => $resultado['omitidos_en_flujo'],
            ]);
        } catch (\Exception $e) {
            Log::error('SyncExternalApiJob: Error en fuente', [
                'source' => $source->name,
                'error' => $e->getMessage(),
            ]);

            // Re-lanzar para que el job falle y se reintente
            throw $e;
        }
    }

    /**
     * Sincroniza todas las fuentes activas.
     */
    private function syncAllSources(): void
    {
        $sources = ExternalApiSource::active()->get();

        Log::info('SyncExternalApiJob: Sincronizando todas las fuentes', [
            'count' => $sources->count(),
        ]);

        $resultados = [];

        foreach ($sources as $source) {
            try {
                $service = $this->getSyncService($source);
                $resultado = $service->sync($source, $this->userId);
                $resultados[$source->name] = [
                    'status' => 'success',
                    'service' => get_class($service),
                    'lotes_creados' => count($resultado['lotes']),
                    'total_prospectos' => $resultado['total_prospectos'],
                ];
            } catch (\Exception $e) {
                $resultados[$source->name] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];

                // Continuar con las otras fuentes, no fallar todo
                Log::error('SyncExternalApiJob: Error en fuente (continuando)', [
                    'source' => $source->name,
                    'error' => $e->getMessage(),
                ]);
            }

            // Pausa de 30 segundos entre fuentes para no saturar
            if ($source !== $sources->last()) {
                sleep(30);
            }
        }

        Log::info('SyncExternalApiJob: Sincronización masiva completada', [
            'resultados' => $resultados,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncExternalApiJob: Job falló definitivamente', [
            'source_id' => $this->sourceId,
            'error' => $exception->getMessage(),
        ]);

        // Si es una fuente específica, marcarla como fallida
        if ($this->sourceId !== null) {
            $source = ExternalApiSource::find($this->sourceId);
            $source?->markAsFailed("Job failed after {$this->tries} attempts: {$exception->getMessage()}");
        }
    }
}
