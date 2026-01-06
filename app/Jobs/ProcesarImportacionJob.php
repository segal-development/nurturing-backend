<?php

namespace App\Jobs;

use App\Imports\SpoutProspectosImport;
use App\Models\Importacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job para procesar importaciones de prospectos en background.
 * 
 * Usa OpenSpout para streaming real de archivos XLSX, permitiendo
 * procesar archivos de cualquier tamaño con memoria constante (~50MB).
 */
class ProcesarImportacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     * 2 horas para archivos muy grandes (500k+ registros)
     */
    public int $timeout = 7200;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $importacionId,
        public string $rutaArchivo,
        public string $diskName = 'gcs'
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
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
            Log::info('ProcesarImportacionJob: Iniciando procesamiento con OpenSpout', [
                'importacion_id' => $this->importacionId,
                'ruta_archivo' => $this->rutaArchivo,
                'memory_limit' => ini_get('memory_limit'),
            ]);

            // Actualizar estado a procesando
            $importacion->update([
                'estado' => 'procesando',
                'metadata' => array_merge($importacion->metadata ?? [], [
                    'procesamiento_iniciado_en' => now()->toISOString(),
                    'motor' => 'openspout',
                ]),
            ]);

            // Verificar que el archivo existe en storage
            if (!Storage::disk($this->diskName)->exists($this->rutaArchivo)) {
                throw new \Exception("Archivo no encontrado en storage: {$this->rutaArchivo}");
            }

            // Obtener tamaño del archivo en storage
            $storageSize = Storage::disk($this->diskName)->size($this->rutaArchivo);
            Log::info('ProcesarImportacionJob: Archivo encontrado en storage', [
                'importacion_id' => $this->importacionId,
                'storage_size_mb' => round($storageSize / 1024 / 1024, 2),
            ]);

            // Descargar archivo de GCS a almacenamiento temporal
            $contenido = Storage::disk($this->diskName)->get($this->rutaArchivo);
            
            if (empty($contenido)) {
                throw new \Exception("Archivo descargado está vacío");
            }
            
            // Asegurar que el archivo temporal tenga extensión .xlsx
            $originalName = basename($this->rutaArchivo);
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            if (empty($extension)) {
                $extension = 'xlsx';
            }
            $tempPath = storage_path('app/temp_' . uniqid() . '.' . $extension);
            
            // Guardar archivo temporal
            $bytesWritten = file_put_contents($tempPath, $contenido);
            
            // Liberar memoria del contenido descargado
            unset($contenido);
            
            // Verificar integridad del archivo
            $localSize = filesize($tempPath);
            if ($localSize !== $storageSize) {
                throw new \Exception("Tamaño del archivo no coincide. Storage: {$storageSize}, Local: {$localSize}");
            }

            Log::info('ProcesarImportacionJob: Archivo descargado correctamente', [
                'importacion_id' => $this->importacionId,
                'temp_path' => $tempPath,
                'size_mb' => round($localSize / 1024 / 1024, 2),
                'bytes_written' => $bytesWritten,
            ]);

            // Procesar archivo con OpenSpout (streaming real)
            $import = new SpoutProspectosImport($this->importacionId, $tempPath);
            $import->import();
            $import->finalize();

            // Limpiar archivos temporales
            @unlink($tempPath);
            
            // Eliminar archivo de GCS (ya no se necesita)
            Storage::disk($this->diskName)->delete($this->rutaArchivo);

            Log::info('ProcesarImportacionJob: Procesamiento completado', [
                'importacion_id' => $this->importacionId,
                'registros_procesados' => $import->getRowsProcessed(),
                'registros_exitosos' => $import->getRegistrosExitosos(),
                'registros_fallidos' => $import->getRegistrosFallidos(),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);

        } catch (\Exception $e) {
            // Limpiar archivo temporal si existe
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            Log::error('ProcesarImportacionJob: Error en procesamiento', [
                'importacion_id' => $this->importacionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);

            $importacion->update([
                'estado' => 'fallido',
                'metadata' => array_merge($importacion->metadata ?? [], [
                    'error' => $e->getMessage(),
                    'error_en' => now()->toISOString(),
                ]),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcesarImportacionJob: Job falló definitivamente', [
            'importacion_id' => $this->importacionId,
            'error' => $exception->getMessage(),
        ]);

        $importacion = Importacion::find($this->importacionId);
        if ($importacion) {
            $importacion->update([
                'estado' => 'fallido',
                'metadata' => array_merge($importacion->metadata ?? [], [
                    'error_final' => $exception->getMessage(),
                    'fallido_en' => now()->toISOString(),
                ]),
            ]);
        }
    }
}
