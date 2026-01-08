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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job para procesar un LOTE COMPLETO de importaciones.
 * 
 * ESTRATEGIA: Un solo job procesa TODOS los archivos del lote secuencialmente.
 * Esto elimina problemas de concurrencia, race conditions y cache desincronizado.
 * 
 * FLUJO:
 * 1. Obtener todas las importaciones pendientes/procesando del lote
 * 2. Procesar cada una secuencialmente
 * 3. Si se cae, retomar desde el archivo y fila donde quedó
 * 
 * CHECKPOINT:
 * - Se guarda en lote.metadata: current_importacion_id, last_processed_row
 * - Cada importación tiene su propio checkpoint también
 */
class ProcesarLoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 9999;
    public int $timeout = 0;
    public bool $failOnTimeout = false;
    public int $backoff = 30;

    public function __construct(
        public int $loteId
    ) {}

    public function handle(): void
    {
        Log::info('ProcesarLoteJob: Iniciando', ['lote_id' => $this->loteId]);

        $lote = Lote::find($this->loteId);
        
        if (!$lote) {
            Log::error('ProcesarLoteJob: Lote no encontrado', ['lote_id' => $this->loteId]);
            $this->delete();
            return;
        }

        // Si el lote ya está completado o fallido, no procesar
        if (in_array($lote->estado, ['completado', 'fallido'])) {
            Log::info('ProcesarLoteJob: Lote ya finalizado', [
                'lote_id' => $this->loteId,
                'estado' => $lote->estado
            ]);
            $this->delete();
            return;
        }

        // Marcar lote como procesando
        $lote->update(['estado' => 'procesando']);

        try {
            $this->procesarImportacionesDelLote($lote);
            $this->finalizarLote($lote);
            $this->delete();
        } catch (\Exception $e) {
            Log::error('ProcesarLoteJob: Error procesando lote', [
                'lote_id' => $this->loteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // No marcar como fallido, dejar que se reintente
            throw $e;
        }
    }

    /**
     * Procesa todas las importaciones del lote secuencialmente.
     * 
     * IMPORTANTE: Usa un loop con refresh para detectar nuevas importaciones
     * que se agreguen mientras el job está corriendo.
     */
    private function procesarImportacionesDelLote(Lote $lote): void
    {
        $procesadas = [];
        $intentosSinNuevas = 0;
        $maxIntentosSinNuevas = 3; // Esperar hasta 3 ciclos sin nuevas importaciones
        
        while ($intentosSinNuevas < $maxIntentosSinNuevas) {
            // Refrescar el lote para ver nuevas importaciones
            $lote->refresh();
            
            // Obtener importaciones pendientes que NO hemos procesado aún
            $importaciones = $lote->importaciones()
                ->whereIn('estado', ['pendiente', 'procesando'])
                ->whereNotIn('id', $procesadas)
                ->orderBy('id')
                ->get();

            if ($importaciones->isEmpty()) {
                // No hay más importaciones por procesar
                // Esperar un poco por si se están subiendo más archivos
                $intentosSinNuevas++;
                
                if ($intentosSinNuevas < $maxIntentosSinNuevas) {
                    Log::info('ProcesarLoteJob: Sin importaciones pendientes, esperando...', [
                        'lote_id' => $lote->id,
                        'intento' => $intentosSinNuevas,
                        'procesadas' => count($procesadas)
                    ]);
                    sleep(5); // Esperar 5 segundos por si vienen más archivos
                }
                continue;
            }
            
            // Reset contador porque encontramos importaciones
            $intentosSinNuevas = 0;

            Log::info('ProcesarLoteJob: Procesando importaciones', [
                'lote_id' => $lote->id,
                'total_importaciones' => $importaciones->count(),
                'importacion_ids' => $importaciones->pluck('id')->toArray(),
                'ya_procesadas' => count($procesadas)
            ]);

            foreach ($importaciones as $importacion) {
                $this->procesarImportacion($importacion, $lote);
                $procesadas[] = $importacion->id;
            }
        }
        
        Log::info('ProcesarLoteJob: Todas las importaciones procesadas', [
            'lote_id' => $lote->id,
            'total_procesadas' => count($procesadas)
        ]);
    }

    /**
     * Procesa una importación individual.
     * 
     * IMPORTANTE: Libera memoria después de cada archivo para evitar OOM.
     */
    private function procesarImportacion(Importacion $importacion, Lote $lote): void
    {
        Log::info('ProcesarLoteJob: Iniciando importación', [
            'lote_id' => $lote->id,
            'importacion_id' => $importacion->id,
            'archivo' => $importacion->nombre_archivo,
            'memoria_antes_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);

        // Actualizar checkpoint del lote
        $lote->update([
            'metadata' => array_merge($lote->metadata ?? [], [
                'current_importacion_id' => $importacion->id,
                'procesando_desde' => now()->toISOString()
            ])
        ]);

        // Marcar importación como procesando
        $importacion->update(['estado' => 'procesando']);

        $tempPath = null;

        try {
            // Descargar archivo
            Log::info('ProcesarLoteJob: Descargando archivo...', [
                'importacion_id' => $importacion->id
            ]);
            $tempPath = $this->downloadFile($importacion);
            
            // Actualizar metadata
            Log::info('ProcesarLoteJob: Archivo descargado, iniciando procesamiento...', [
                'importacion_id' => $importacion->id,
                'file_size_mb' => round(filesize($tempPath) / 1024 / 1024, 2),
                'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
            ]);
            
            $importacion->update([
                'metadata' => array_merge($importacion->metadata ?? [], [
                    'procesamiento_iniciado_en' => now()->toISOString(),
                    'file_size_mb' => round(filesize($tempPath) / 1024 / 1024, 2),
                ])
            ]);

            // Procesar con el servicio existente
            Log::info('ProcesarLoteJob: Creando ProspectoImportService...', [
                'importacion_id' => $importacion->id
            ]);
            $service = new ProspectoImportService($importacion, $tempPath);
            
            Log::info('ProcesarLoteJob: Iniciando import()...', [
                'importacion_id' => $importacion->id,
                'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
            ]);
            $service->import();

            // Verificar que se marcó como completado
            $importacion->refresh();
            if ($importacion->estado === 'procesando') {
                // Forzar completado si el servicio no lo hizo
                $this->forceMarkImportacionCompleted($importacion, $service);
            }

            // Limpiar archivos
            $this->cleanup($importacion, $tempPath);
            
            // IMPORTANTE: Liberar memoria del servicio
            unset($service);

            Log::info('ProcesarLoteJob: Importación completada', [
                'lote_id' => $lote->id,
                'importacion_id' => $importacion->id,
                'registros_exitosos' => $importacion->fresh()->registros_exitosos,
                'memoria_despues_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
            ]);

            // Actualizar totales del lote después de cada importación
            $lote->recalcularTotales();
            
            // Forzar garbage collection después de cada archivo grande
            gc_collect_cycles();

        } catch (\Exception $e) {
            // Limpiar archivo temporal si existe
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            Log::error('ProcesarLoteJob: Error en importación', [
                'lote_id' => $lote->id,
                'importacion_id' => $importacion->id,
                'error' => $e->getMessage(),
                'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
            ]);

            // Marcar esta importación como fallida pero continuar con las demás
            $importacion->update([
                'estado' => 'fallido',
                'metadata' => array_merge($importacion->metadata ?? [], [
                    'error' => $e->getMessage(),
                    'fallido_en' => now()->toISOString()
                ])
            ]);

            $lote->recalcularTotales();
            
            // Forzar garbage collection
            gc_collect_cycles();
        }
    }

    /**
     * Descarga archivo de GCS a temporal.
     */
    private function downloadFile(Importacion $importacion): string
    {
        $disk = $importacion->metadata['disk'] ?? 'gcs';
        $rutaArchivo = $importacion->ruta_archivo;

        if (!Storage::disk($disk)->exists($rutaArchivo)) {
            throw new \Exception("Archivo no encontrado en storage: {$rutaArchivo}");
        }

        $tempPath = sys_get_temp_dir() . '/' . uniqid('import_') . '.xlsx';
        $content = Storage::disk($disk)->get($rutaArchivo);
        file_put_contents($tempPath, $content);

        Log::info('ProcesarLoteJob: Archivo descargado', [
            'importacion_id' => $importacion->id,
            'size_mb' => round(strlen($content) / 1024 / 1024, 2)
        ]);

        return $tempPath;
    }

    /**
     * Fuerza marcar importación como completada.
     */
    private function forceMarkImportacionCompleted(Importacion $importacion, ProspectoImportService $service): void
    {
        $importacion->update([
            'estado' => 'completado',
            'total_registros' => $service->getRowsProcessed(),
            'registros_exitosos' => $service->getRegistrosExitosos(),
            'registros_fallidos' => $service->getRegistrosFallidos(),
            'metadata' => array_merge($importacion->metadata ?? [], [
                'completado_en' => now()->toISOString(),
                'completado_por' => 'lote_job_fallback'
            ])
        ]);
    }

    /**
     * Limpia archivos temporales y de storage.
     */
    private function cleanup(Importacion $importacion, ?string $tempPath): void
    {
        // Eliminar archivo temporal
        if ($tempPath && file_exists($tempPath)) {
            @unlink($tempPath);
        }

        // Eliminar archivo de GCS
        try {
            $disk = $importacion->metadata['disk'] ?? 'gcs';
            Storage::disk($disk)->delete($importacion->ruta_archivo);
        } catch (\Exception $e) {
            Log::warning('ProcesarLoteJob: Error eliminando archivo de GCS', [
                'importacion_id' => $importacion->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Finaliza el lote después de procesar todas las importaciones.
     */
    private function finalizarLote(Lote $lote): void
    {
        $lote->refresh();
        $lote->recalcularTotales();

        $importaciones = $lote->importaciones()->get();
        $todasFinalizadas = $importaciones->every(fn($i) => in_array($i->estado, ['completado', 'fallido']));
        $algunaFallida = $importaciones->contains(fn($i) => $i->estado === 'fallido');

        if ($todasFinalizadas) {
            $estadoFinal = $algunaFallida ? 'fallido' : 'completado';
            $lote->update([
                'estado' => $estadoFinal,
                'cerrado_en' => now(),
                'metadata' => array_merge($lote->metadata ?? [], [
                    'finalizado_en' => now()->toISOString(),
                    'finalizado_por' => 'lote_job'
                ])
            ]);

            Log::info('ProcesarLoteJob: Lote finalizado', [
                'lote_id' => $lote->id,
                'estado' => $estadoFinal,
                'total_registros' => $lote->total_registros,
                'registros_exitosos' => $lote->registros_exitosos
            ]);
        }
    }
}
