<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Importacion;
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

    /**
     * Intentos altos - el job debe persistir hasta completarse.
     * El lock en BD evita ejecuciones paralelas.
     */
    public int $tries = 9999;
    
    /**
     * Sin timeout de Laravel - Cloud Run maneja el timeout.
     */
    public int $timeout = 0;
    
    /**
     * No fallar por timeout.
     */
    public bool $failOnTimeout = false;

    /**
     * Backoff entre reintentos (segundos).
     * Evita que el job se re-intente inmediatamente si falla.
     */
    public int $backoff = 30;

    public function __construct(
        public int $importacionId,
        public string $rutaArchivo,
        public string $diskName = 'gcs'
    ) {}

    /**
     * Ejecuta el job de importación.
     */
    public function handle(): void
    {
        // Paso 1: Intentar adquirir lock (sin eliminar de cola)
        if (!$this->tryAcquireLock()) {
            return; // Otro worker ya lo tiene, salir sin eliminar de cola
        }

        // Paso 2: Verificar si ya está completado
        if ($this->isAlreadyCompleted()) {
            $this->deleteAndLog('Importación ya completada');
            return;
        }

        // Paso 3: Procesar importación
        $success = $this->processImport();

        // Paso 4: Solo eliminar de cola si terminó exitosamente
        if ($success) {
            $this->deleteAndLog('Importación completada exitosamente');
        }
        // Si no fue exitoso, el job permanece en cola para reintento
    }

    /**
     * Intenta adquirir el lock usando UPDATE condicional.
     */
    private function tryAcquireLock(): bool
    {
        $importacion = Importacion::find($this->importacionId);
        
        if (!$importacion) {
            Log::error('ProcesarImportacionJob: Importación no encontrada', [
                'importacion_id' => $this->importacionId,
            ]);
            $this->delete();
            return false;
        }

        // Si ya está procesando, verificar si el proceso anterior murió
        if ($importacion->estado === 'procesando') {
            return $this->tryRecoverStaleLock($importacion);
        }

        // Si está pendiente, adquirir lock
        if ($importacion->estado === 'pendiente') {
            return $this->acquireFreshLock();
        }

        // Estados finales (completado, fallido) - no procesar
        Log::info('ProcesarImportacionJob: Estado final, no procesar', [
            'importacion_id' => $this->importacionId,
            'estado' => $importacion->estado,
        ]);
        return false;
    }

    /**
     * Intenta recuperar un lock abandonado (proceso anterior murió).
     */
    private function tryRecoverStaleLock(Importacion $importacion): bool
    {
        $lastUpdate = $importacion->updated_at;
        $minutesSinceUpdate = now()->diffInMinutes($lastUpdate);

        // Si se actualizó hace menos de 2 minutos, otro proceso lo tiene activo
        if ($minutesSinceUpdate < 2) {
            Log::info('ProcesarImportacionJob: Lock activo, otro worker procesando', [
                'importacion_id' => $this->importacionId,
                'minutes_since_update' => $minutesSinceUpdate,
            ]);
            return false;
        }

        // Lock abandonado - recuperarlo
        Log::warning('ProcesarImportacionJob: Recuperando lock abandonado', [
            'importacion_id' => $this->importacionId,
            'minutes_since_update' => $minutesSinceUpdate,
            'last_checkpoint' => $importacion->metadata['last_processed_row'] ?? 0,
        ]);

        // Actualizar timestamp para indicar que este worker lo tiene
        $importacion->update(['updated_at' => now()]);
        return true;
    }

    /**
     * Adquiere un lock fresco (estado pendiente).
     */
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
            Log::info('ProcesarImportacionJob: No se pudo adquirir lock fresco', [
                'importacion_id' => $this->importacionId,
            ]);
            return false;
        }

        Log::info('ProcesarImportacionJob: Lock fresco adquirido', [
            'importacion_id' => $this->importacionId,
        ]);
        return true;
    }

    /**
     * Verifica si la importación ya está completada.
     */
    private function isAlreadyCompleted(): bool
    {
        $importacion = Importacion::find($this->importacionId);
        return $importacion && $importacion->estado === 'completado';
    }

    /**
     * Procesa la importación usando el servicio.
     * 
     * @return bool True si completó exitosamente
     */
    private function processImport(): bool
    {
        $importacion = Importacion::find($this->importacionId);
        
        if (!$importacion) {
            return false;
        }

        $tempPath = null;

        try {
            $tempPath = $this->downloadFile();
            $this->updateMetadata($importacion, $tempPath);
            
            $service = new ProspectoImportService($importacion, $tempPath);
            $service->import();

            $this->cleanup($tempPath);
            
            Log::info('ProcesarImportacionJob: Procesamiento completado', [
                'importacion_id' => $this->importacionId,
                'registros_procesados' => $service->getRowsProcessed(),
                'exitosos' => $service->getRegistrosExitosos(),
                'fallidos' => $service->getRegistrosFallidos(),
                'memoria_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);

            return true;

        } catch (\Exception $e) {
            $this->handleError($importacion, $tempPath, $e);
            return false;
        }
    }

    /**
     * Descarga el archivo de GCS a almacenamiento temporal.
     */
    private function downloadFile(): string
    {
        Log::info('ProcesarImportacionJob: Descargando archivo', [
            'importacion_id' => $this->importacionId,
            'ruta' => $this->rutaArchivo,
        ]);

        if (!Storage::disk($this->diskName)->exists($this->rutaArchivo)) {
            throw new \Exception("Archivo no encontrado: {$this->rutaArchivo}");
        }

        $storageSize = Storage::disk($this->diskName)->size($this->rutaArchivo);
        $contenido = Storage::disk($this->diskName)->get($this->rutaArchivo);

        if (empty($contenido)) {
            throw new \Exception("Archivo descargado está vacío");
        }

        $extension = pathinfo($this->rutaArchivo, PATHINFO_EXTENSION) ?: 'xlsx';
        $tempPath = storage_path('app/temp_' . uniqid() . '.' . $extension);
        
        file_put_contents($tempPath, $contenido);
        unset($contenido);

        if (filesize($tempPath) !== $storageSize) {
            throw new \Exception("Tamaño no coincide. Storage: {$storageSize}, Local: " . filesize($tempPath));
        }

        Log::info('ProcesarImportacionJob: Archivo descargado', [
            'importacion_id' => $this->importacionId,
            'size_mb' => round($storageSize / 1024 / 1024, 2),
        ]);

        return $tempPath;
    }

    /**
     * Actualiza metadata con información del procesamiento.
     */
    private function updateMetadata(Importacion $importacion, string $tempPath): void
    {
        $importacion->update([
            'metadata' => array_merge($importacion->metadata ?? [], [
                'procesamiento_iniciado_en' => now()->toISOString(),
                'motor' => 'prospectoImportService_v2',
                'file_size_mb' => round(filesize($tempPath) / 1024 / 1024, 2),
            ]),
        ]);
    }

    /**
     * Limpia archivos temporales y de GCS.
     */
    private function cleanup(?string $tempPath): void
    {
        if ($tempPath && file_exists($tempPath)) {
            @unlink($tempPath);
        }

        try {
            Storage::disk($this->diskName)->delete($this->rutaArchivo);
        } catch (\Exception $e) {
            Log::warning('ProcesarImportacionJob: Error eliminando archivo de GCS', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Maneja errores durante el procesamiento.
     */
    private function handleError(Importacion $importacion, ?string $tempPath, \Exception $e): void
    {
        if ($tempPath && file_exists($tempPath)) {
            @unlink($tempPath);
        }

        Log::error('ProcesarImportacionJob: Error en procesamiento', [
            'importacion_id' => $this->importacionId,
            'error' => $e->getMessage(),
            'memoria_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);

        // Guardar error en metadata pero NO marcar como fallido
        // El job se reintentará automáticamente
        $importacion->update([
            'metadata' => array_merge($importacion->metadata ?? [], [
                'ultimo_error' => $e->getMessage(),
                'ultimo_error_en' => now()->toISOString(),
            ]),
        ]);
    }

    /**
     * Elimina el job de la cola y loguea.
     */
    private function deleteAndLog(string $reason): void
    {
        Log::info('ProcesarImportacionJob: Eliminando de cola', [
            'importacion_id' => $this->importacionId,
            'reason' => $reason,
        ]);
        $this->delete();
    }

    /**
     * Determina si el job debe reintentarse.
     */
    public function shouldRetry(\Throwable $exception): bool
    {
        $importacion = Importacion::find($this->importacionId);
        
        // No reintentar si ya está completado
        if ($importacion && $importacion->estado === 'completado') {
            return false;
        }
        
        // Reintentar en otros casos
        return true;
    }
}
