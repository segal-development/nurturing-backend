<?php

namespace App\Jobs;

use App\Imports\ProspectosImport;
use App\Models\Importacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcesarImportacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600; // 1 hora

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

        try {
            Log::info('ProcesarImportacionJob: Iniciando procesamiento', [
                'importacion_id' => $this->importacionId,
                'ruta_archivo' => $this->rutaArchivo,
            ]);

            // Actualizar estado a procesando
            $importacion->update([
                'estado' => 'procesando',
                'metadata' => array_merge($importacion->metadata ?? [], [
                    'procesamiento_iniciado_en' => now()->toISOString(),
                ]),
            ]);

            // Descargar archivo de GCS a almacenamiento temporal
            $contenido = Storage::disk($this->diskName)->get($this->rutaArchivo);
            $tempPath = storage_path('app/temp_' . basename($this->rutaArchivo));
            file_put_contents($tempPath, $contenido);

            // Procesar archivo con el import existente
            $import = new ProspectosImport($this->importacionId);
            Excel::import($import, $tempPath);

            // Actualizar importación con resultados
            $import->actualizarImportacion();

            // Limpiar archivos temporales
            @unlink($tempPath);
            
            // Eliminar archivo de GCS (ya no se necesita)
            Storage::disk($this->diskName)->delete($this->rutaArchivo);

            Log::info('ProcesarImportacionJob: Procesamiento completado', [
                'importacion_id' => $this->importacionId,
                'registros_exitosos' => $import->getRegistrosExitosos(),
                'registros_fallidos' => $import->getRegistrosFallidos(),
            ]);

        } catch (\Exception $e) {
            Log::error('ProcesarImportacionJob: Error en procesamiento', [
                'importacion_id' => $this->importacionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
