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
 * Optimizado para archivos grandes (500k+ registros).
 * 
 * Características:
 * - Resume automático si se interrumpe (checkpoints)
 * - Lock optimista en BD para evitar ejecuciones duplicadas
 * - Se auto-elimina de la cola para evitar reintentos de Laravel
 */
class ProcesarImportacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Deshabilitamos el sistema de reintentos de Laravel.
     * Manejamos todo con checkpoints y estado en BD.
     */
    public int $tries = 0;
    public int $timeout = 0;
    public bool $failOnTimeout = false;

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
        // Paso 1: Eliminar de la cola inmediatamente
        $this->deleteFromQueue();

        // Paso 2: Intentar adquirir lock
        if (!$this->acquireLock()) {
            return;
        }

        // Paso 3: Procesar importación
        $this->processImport();
    }

    /**
     * Elimina el job de la cola para evitar reintentos.
     */
    private function deleteFromQueue(): void
    {
        $this->delete();
        
        Log::info('ProcesarImportacionJob: Job eliminado de cola', [
            'importacion_id' => $this->importacionId,
        ]);
    }

    /**
     * Adquiere un lock usando UPDATE condicional.
     * Solo permite procesar si estado = 'pendiente' o 'procesando' (para resume).
     */
    private function acquireLock(): bool
    {
        // Permitir continuar si está pendiente O procesando (resume)
        $acquired = DB::table('importaciones')
            ->where('id', $this->importacionId)
            ->whereIn('estado', ['pendiente', 'procesando'])
            ->update([
                'estado' => 'procesando',
                'updated_at' => now(),
            ]);

        if ($acquired === 0) {
            $importacion = Importacion::find($this->importacionId);
            
            Log::info('ProcesarImportacionJob: No se pudo adquirir lock', [
                'importacion_id' => $this->importacionId,
                'estado_actual' => $importacion?->estado ?? 'not_found',
            ]);
            
            return false;
        }

        Log::info('ProcesarImportacionJob: Lock adquirido', [
            'importacion_id' => $this->importacionId,
        ]);

        return true;
    }

    /**
     * Procesa la importación usando el servicio.
     */
    private function processImport(): void
    {
        $importacion = Importacion::find($this->importacionId);
        
        if (!$importacion) {
            Log::error('ProcesarImportacionJob: Importación no encontrada', [
                'importacion_id' => $this->importacionId,
            ]);
            return;
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

        } catch (\Exception $e) {
            $this->handleError($importacion, $tempPath, $e);
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
        
        $bytesWritten = file_put_contents($tempPath, $contenido);
        unset($contenido);

        // Verificar integridad
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
                'motor' => 'prospectoImportService',
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

        // NO marcamos como fallido - el checkpoint manager ya lo maneja
        // Solo actualizamos si hubo un error fatal antes del servicio
        if ($importacion->estado === 'procesando') {
            $importacion->update([
                'metadata' => array_merge($importacion->metadata ?? [], [
                    'ultimo_error' => $e->getMessage(),
                    'ultimo_error_en' => now()->toISOString(),
                ]),
            ]);
        }
    }
}
