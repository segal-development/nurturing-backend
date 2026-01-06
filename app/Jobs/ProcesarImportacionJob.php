<?php

namespace App\Jobs;

use App\Imports\SpoutProspectosImport;
use App\Models\Importacion;
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
 * Usa OpenSpout para streaming real de archivos XLSX, permitiendo
 * procesar archivos de cualquier tamaño con memoria constante (~50MB).
 * 
 * IMPORTANTE: Este job se auto-elimina de la cola inmediatamente al iniciar
 * para evitar que el Cloud Scheduler lo re-intente. El estado se maneja
 * 100% en la tabla importaciones.
 */
class ProcesarImportacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Intentos ilimitados - manejamos el estado nosotros mismos.
     * Ponemos un número alto para que Laravel no lo marque como fallido.
     */
    public int $tries = 0;
    
    /**
     * Sin timeout de Laravel - lo manejamos nosotros.
     */
    public int $timeout = 0;
    
    /**
     * No fallar por timeout.
     */
    public bool $failOnTimeout = false;

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
     * 
     * ESTRATEGIA: Eliminamos el job de la cola INMEDIATAMENTE y manejamos
     * todo el estado en la tabla importaciones. Esto evita que Laravel
     * cuente attempts y tire MaxAttemptsExceededException.
     */
    public function handle(): void
    {
        // PASO 1: Eliminar este job de la cola INMEDIATAMENTE
        // Esto evita que el scheduler lo re-intente
        $this->delete();
        
        Log::info('ProcesarImportacionJob: Job eliminado de cola, verificando estado', [
            'importacion_id' => $this->importacionId,
        ]);

        // PASO 2: Intentar adquirir el lock usando UPDATE condicional
        // Solo actualiza si estado = 'pendiente', retorna filas afectadas
        $acquired = DB::table('importaciones')
            ->where('id', $this->importacionId)
            ->where('estado', 'pendiente')
            ->update([
                'estado' => 'procesando',
                'updated_at' => now(),
            ]);

        if ($acquired === 0) {
            // No se pudo adquirir el lock - otro worker ya lo tiene o ya terminó
            $importacion = Importacion::find($this->importacionId);
            $estado = $importacion?->estado ?? 'unknown';
            
            Log::info('ProcesarImportacionJob: Importación no está pendiente, saltando', [
                'importacion_id' => $this->importacionId,
                'estado_actual' => $estado,
            ]);
            return;
        }

        Log::info('ProcesarImportacionJob: Lock adquirido, iniciando procesamiento', [
            'importacion_id' => $this->importacionId,
        ]);

        $importacion = Importacion::find($this->importacionId);
        $tempPath = null;

        try {
            Log::info('ProcesarImportacionJob: Iniciando procesamiento con OpenSpout', [
                'importacion_id' => $this->importacionId,
                'ruta_archivo' => $this->rutaArchivo,
                'memory_limit' => ini_get('memory_limit'),
            ]);

            // Actualizar metadata (estado ya fue cambiado por el lock)
            $importacion->update([
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

            // NO re-lanzamos la excepción - ya manejamos el estado nosotros
            // throw $e;
        }
    }
}
