<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Importacion;
use App\Models\Lote;
use App\Services\Import\ProspectoImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job para procesar importaciones de prospectos en background.
 * 
 * ESTRATEGIA DE RESILIENCIA:
 * - El job permanece en la cola hasta que termine exitosamente
 * - Si Cloud Run mata el proceso, el scheduler lo retomará
 * - Usa checkpoints en BD para continuar desde donde quedó
 * - Lock optimista en tabla importaciones evita ejecuciones paralelas
 */
class ProcesarImportacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // =========================================================================
    // CONFIGURACION DE COLA
    // =========================================================================

    /** Intentos altos - el job debe persistir hasta completarse */
    public int $tries = 9999;
    
    /** Sin timeout de Laravel - Cloud Run maneja el timeout */
    public int $timeout = 0;
    
    /** No fallar por timeout */
    public bool $failOnTimeout = false;

    /** Backoff entre reintentos (segundos) */
    public int $backoff = 30;

    // =========================================================================
    // CONSTANTES INTERNAS
    // =========================================================================

    /** Minutos máximos sin update para considerar lock abandonado */
    private const STALE_LOCK_THRESHOLD_MINUTES = 2;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(
        public int $importacionId,
        public string $rutaArchivo,
        public string $diskName = 'gcs'
    ) {}

    // =========================================================================
    // HANDLER PRINCIPAL
    // =========================================================================

    public function handle(): void
    {
        $startTime = microtime(true);
        
        $this->logInicio();

        if (!$this->tryAcquireLock()) {
            $this->logNoLock();
            return;
        }

        if ($this->isAlreadyCompleted()) {
            $this->deleteAndLog('Importación ya completada');
            return;
        }

        $success = $this->processImport();

        $this->logFinalizacion($success, $startTime);

        if ($success) {
            $this->deleteAndLog('Importación completada exitosamente');
        }
    }

    // =========================================================================
    // GESTION DE LOCKS
    // =========================================================================

    private function tryAcquireLock(): bool
    {
        $importacion = Importacion::find($this->importacionId);
        
        if (!$importacion) {
            $this->logImportacionNoEncontrada();
            $this->delete();
            return false;
        }

        return match ($importacion->estado) {
            'procesando' => $this->tryRecoverStaleLock($importacion),
            'pendiente' => $this->acquireFreshLock(),
            default => $this->handleEstadoFinal($importacion->estado),
        };
    }

    private function tryRecoverStaleLock(Importacion $importacion): bool
    {
        $minutesSinceUpdate = (int) now()->diffInMinutes($importacion->updated_at);

        if ($minutesSinceUpdate < self::STALE_LOCK_THRESHOLD_MINUTES) {
            $this->logLockActivo($minutesSinceUpdate);
            return false;
        }

        $this->logRecuperandoLock($minutesSinceUpdate, $importacion);
        $importacion->update(['updated_at' => now()]);
        
        return true;
    }

    private function acquireFreshLock(): bool
    {
        $acquired = DB::table('importaciones')
            ->where('id', $this->importacionId)
            ->where('estado', 'pendiente')
            ->update([
                'estado' => 'procesando',
                'updated_at' => now(),
            ]);

        if ($acquired === 0) {
            $this->logNoSePudoAdquirirLock();
            return false;
        }

        $this->logLockAdquirido();
        return true;
    }

    private function handleEstadoFinal(string $estado): bool
    {
        Log::info('ProcesarImportacionJob: Estado final, no procesar', [
            'importacion_id' => $this->importacionId,
            'estado' => $estado,
        ]);
        return false;
    }

    private function isAlreadyCompleted(): bool
    {
        $importacion = Importacion::find($this->importacionId);
        return $importacion && $importacion->estado === 'completado';
    }

    // =========================================================================
    // PROCESAMIENTO
    // =========================================================================

    private function processImport(): bool
    {
        $importacion = Importacion::find($this->importacionId);
        
        if (!$importacion) {
            return false;
        }

        $tempPath = null;

        try {
            $tempPath = $this->downloadFile();
            $this->updateMetadataInicio($importacion, $tempPath);
            
            $service = $this->ejecutarServicioImportacion($importacion, $tempPath);
            $this->verificarYForzarCompletado($importacion, $service);
            $this->cleanup($tempPath);
            $this->forceMemoryCleanup();
            
            $this->logProcesamientoCompletado($importacion, $service);

            return true;

        } catch (\Exception $e) {
            $this->handleError($importacion, $tempPath, $e);
            return false;
        }
    }

    private function ejecutarServicioImportacion(Importacion $importacion, string $tempPath): ProspectoImportService
    {
        $service = new ProspectoImportService($importacion, $tempPath);
        $service->import();
        return $service;
    }

    private function verificarYForzarCompletado(Importacion $importacion, ProspectoImportService $service): void
    {
        $importacion->refresh();
        
        if ($importacion->estado !== 'procesando') {
            return;
        }

        Log::warning('ProcesarImportacionJob: Import terminó pero estado sigue en procesando, forzando completado', [
            'importacion_id' => $this->importacionId,
            'registros_exitosos' => $service->getRegistrosExitosos(),
        ]);
        
        $this->forceMarkAsCompleted($importacion, $service);
    }

    private function forceMarkAsCompleted(Importacion $importacion, ProspectoImportService $service): void
    {
        $importacion->update([
            'estado' => 'completado',
            'total_registros' => $service->getRowsProcessed(),
            'registros_exitosos' => $service->getRegistrosExitosos(),
            'registros_fallidos' => $service->getRegistrosFallidos(),
            'metadata' => array_merge($importacion->metadata ?? [], [
                'completado_en' => now()->toISOString(),
                'completado_por' => 'job_fallback',
            ]),
        ]);

        $this->updateLoteAfterComplete($importacion);
    }

    // =========================================================================
    // GESTION DE LOTE
    // =========================================================================

    /**
     * Actualiza el lote después de completar una importación.
     * 
     * IMPORTANTE: Solo recalcula totales, NO cierra el lote automáticamente.
     * El lote debe ser cerrado manualmente por el usuario via POST /api/lotes/{id}/cerrar.
     */
    private function updateLoteAfterComplete(Importacion $importacion): void
    {
        $importacion->refresh();
        
        if (!$importacion->lote_id) {
            return;
        }

        $lote = $importacion->lote;
        if (!$lote) {
            return;
        }

        $this->recalcularTotalesLote($lote);
    }

    private function recalcularTotalesLote(Lote $lote): void
    {
        $importaciones = $lote->importaciones()->get();
        
        $hayEnProceso = $importaciones->contains(
            fn($imp) => in_array($imp->estado, ['procesando', 'pendiente'])
        );
        
        $estadoLote = $hayEnProceso ? 'procesando' : 'abierto';

        $lote->update([
            'total_registros' => $importaciones->sum('total_registros'),
            'registros_exitosos' => $importaciones->sum('registros_exitosos'),
            'registros_fallidos' => $importaciones->sum('registros_fallidos'),
            'estado' => $estadoLote,
        ]);

        Log::info('ProcesarImportacionJob: Lote actualizado (sin cierre automático)', [
            'lote_id' => $lote->id,
            'estado' => $estadoLote,
        ]);
    }

    // =========================================================================
    // DESCARGA DE ARCHIVO
    // =========================================================================

    private function downloadFile(): string
    {
        $this->logDescargaInicio();

        $this->validarArchivoExiste();
        
        $contenido = $this->descargarContenido();
        $tempPath = $this->guardarEnTemporal($contenido);
        
        unset($contenido);

        $this->logDescargaCompletada($tempPath);

        return $tempPath;
    }

    private function validarArchivoExiste(): void
    {
        if (!Storage::disk($this->diskName)->exists($this->rutaArchivo)) {
            throw new \Exception("Archivo no encontrado: {$this->rutaArchivo}");
        }
    }

    private function descargarContenido(): string
    {
        $contenido = Storage::disk($this->diskName)->get($this->rutaArchivo);

        if (empty($contenido)) {
            throw new \Exception("Archivo descargado está vacío");
        }

        return $contenido;
    }

    private function guardarEnTemporal(string $contenido): string
    {
        $storageSize = Storage::disk($this->diskName)->size($this->rutaArchivo);
        $extension = pathinfo($this->rutaArchivo, PATHINFO_EXTENSION) ?: 'xlsx';
        $tempPath = storage_path('app/temp_' . uniqid() . '.' . $extension);
        
        file_put_contents($tempPath, $contenido);

        if (filesize($tempPath) !== $storageSize) {
            throw new \Exception("Tamaño no coincide. Storage: {$storageSize}, Local: " . filesize($tempPath));
        }

        return $tempPath;
    }

    // =========================================================================
    // METADATA Y CLEANUP
    // =========================================================================

    private function updateMetadataInicio(Importacion $importacion, string $tempPath): void
    {
        $importacion->update([
            'metadata' => array_merge($importacion->metadata ?? [], [
                'procesamiento_iniciado_en' => now()->toISOString(),
                'motor' => 'prospectoImportService_v2',
                'file_size_mb' => round(filesize($tempPath) / 1024 / 1024, 2),
            ]),
        ]);
    }

    private function cleanup(?string $tempPath): void
    {
        $this->eliminarArchivoTemporal($tempPath);
        $this->eliminarArchivoStorage();
    }

    private function eliminarArchivoTemporal(?string $tempPath): void
    {
        if ($tempPath && file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    private function eliminarArchivoStorage(): void
    {
        try {
            Storage::disk($this->diskName)->delete($this->rutaArchivo);
        } catch (\Exception $e) {
            Log::warning('ProcesarImportacionJob: Error eliminando archivo de GCS', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function forceMemoryCleanup(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        gc_mem_caches();
        
        Log::info('ProcesarImportacionJob: Memoria limpiada', [
            'importacion_id' => $this->importacionId,
            'memoria_despues_gc_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    // =========================================================================
    // MANEJO DE ERRORES
    // =========================================================================

    private function handleError(Importacion $importacion, ?string $tempPath, \Exception $e): void
    {
        $this->eliminarArchivoTemporal($tempPath);

        Log::error('ProcesarImportacionJob: Error en procesamiento', [
            'importacion_id' => $this->importacionId,
            'error' => $e->getMessage(),
            'memoria_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);

        $importacion->update([
            'metadata' => array_merge($importacion->metadata ?? [], [
                'ultimo_error' => $e->getMessage(),
                'ultimo_error_en' => now()->toISOString(),
            ]),
        ]);
    }

    public function shouldRetry(\Throwable $exception): bool
    {
        $importacion = Importacion::find($this->importacionId);
        
        return !($importacion && $importacion->estado === 'completado');
    }

    // =========================================================================
    // LOGGING
    // =========================================================================

    private function logInicio(): void
    {
        Log::info('ProcesarImportacionJob: Iniciando', [
            'importacion_id' => $this->importacionId,
            'attempt' => $this->attempts(),
            'memoria_inicial_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    private function logNoLock(): void
    {
        Log::info('ProcesarImportacionJob: No se pudo adquirir lock, saliendo', [
            'importacion_id' => $this->importacionId,
        ]);
    }

    private function logImportacionNoEncontrada(): void
    {
        Log::error('ProcesarImportacionJob: Importación no encontrada', [
            'importacion_id' => $this->importacionId,
        ]);
    }

    private function logLockActivo(int $minutesSinceUpdate): void
    {
        Log::info('ProcesarImportacionJob: Lock activo, otro worker procesando', [
            'importacion_id' => $this->importacionId,
            'minutes_since_update' => $minutesSinceUpdate,
        ]);
    }

    private function logRecuperandoLock(int $minutesSinceUpdate, Importacion $importacion): void
    {
        Log::warning('ProcesarImportacionJob: Recuperando lock abandonado', [
            'importacion_id' => $this->importacionId,
            'minutes_since_update' => $minutesSinceUpdate,
            'last_checkpoint' => $importacion->metadata['last_processed_row'] ?? 0,
        ]);
    }

    private function logNoSePudoAdquirirLock(): void
    {
        Log::info('ProcesarImportacionJob: No se pudo adquirir lock fresco', [
            'importacion_id' => $this->importacionId,
        ]);
    }

    private function logLockAdquirido(): void
    {
        Log::info('ProcesarImportacionJob: Lock fresco adquirido', [
            'importacion_id' => $this->importacionId,
        ]);
    }

    private function logDescargaInicio(): void
    {
        Log::info('ProcesarImportacionJob: Descargando archivo', [
            'importacion_id' => $this->importacionId,
            'ruta' => $this->rutaArchivo,
        ]);
    }

    private function logDescargaCompletada(string $tempPath): void
    {
        Log::info('ProcesarImportacionJob: Archivo descargado', [
            'importacion_id' => $this->importacionId,
            'size_mb' => round(filesize($tempPath) / 1024 / 1024, 2),
        ]);
    }

    private function logProcesamientoCompletado(Importacion $importacion, ProspectoImportService $service): void
    {
        Log::info('ProcesarImportacionJob: Procesamiento completado', [
            'importacion_id' => $this->importacionId,
            'registros_procesados' => $service->getRowsProcessed(),
            'exitosos' => $service->getRegistrosExitosos(),
            'fallidos' => $service->getRegistrosFallidos(),
            'estado_final' => $importacion->fresh()->estado,
            'memoria_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'memoria_actual_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    private function logFinalizacion(bool $success, float $startTime): void
    {
        $elapsed = round(microtime(true) - $startTime, 2);

        if ($success) {
            Log::info('ProcesarImportacionJob: Finalizado exitosamente', [
                'importacion_id' => $this->importacionId,
                'tiempo_total_segundos' => $elapsed,
                'memoria_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);
        } else {
            Log::warning('ProcesarImportacionJob: Finalizado con errores, permanece en cola', [
                'importacion_id' => $this->importacionId,
                'tiempo_segundos' => $elapsed,
            ]);
        }
    }

    private function deleteAndLog(string $reason): void
    {
        Log::info('ProcesarImportacionJob: Eliminando de cola', [
            'importacion_id' => $this->importacionId,
            'reason' => $reason,
        ]);
        $this->delete();
    }
}
