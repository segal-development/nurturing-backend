<?php

namespace App\Jobs;

use App\Models\ExternalApiSource;
use App\Services\ExternalApiSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para sincronizar prospectos desde APIs externas.
 * 
 * Se puede ejecutar:
 * - Programado (cada viernes a las 2am)
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
     * Tiempo máximo de ejecución (5 minutos).
     */
    public int $timeout = 300;

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
    public function handle(ExternalApiSyncService $service): void
    {
        Log::info('SyncExternalApiJob: Iniciando', [
            'source_id' => $this->sourceId,
            'user_id' => $this->userId,
        ]);

        if ($this->sourceId !== null) {
            // Sincronizar una fuente específica
            $this->syncSingleSource($service);
        } else {
            // Sincronizar todas las fuentes activas
            $this->syncAllSources($service);
        }
    }

    /**
     * Sincroniza una fuente específica.
     */
    private function syncSingleSource(ExternalApiSyncService $service): void
    {
        $source = ExternalApiSource::find($this->sourceId);

        if (!$source) {
            Log::warning('SyncExternalApiJob: Fuente no encontrada', [
                'source_id' => $this->sourceId,
            ]);
            return;
        }

        if (!$source->is_active) {
            Log::info('SyncExternalApiJob: Fuente inactiva, omitiendo', [
                'source' => $source->name,
            ]);
            return;
        }

        try {
            $importacion = $service->sync($source, $this->userId);
            
            Log::info('SyncExternalApiJob: Fuente sincronizada', [
                'source' => $source->name,
                'importacion_id' => $importacion->id,
                'registros_exitosos' => $importacion->registros_exitosos,
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
    private function syncAllSources(ExternalApiSyncService $service): void
    {
        $sources = ExternalApiSource::active()->get();
        
        Log::info('SyncExternalApiJob: Sincronizando todas las fuentes', [
            'count' => $sources->count(),
        ]);

        $resultados = [];

        foreach ($sources as $source) {
            try {
                $importacion = $service->sync($source, $this->userId);
                $resultados[$source->name] = [
                    'status' => 'success',
                    'importacion_id' => $importacion->id,
                    'registros' => $importacion->registros_exitosos,
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
