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
use Illuminate\Support\Collection;
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

    // =========================================================================
    // CONFIGURACION DE COLA
    // =========================================================================

    public int $tries = 9999;
    public int $timeout = 0;
    public bool $failOnTimeout = false;
    public int $backoff = 30;

    // =========================================================================
    // CONSTANTES INTERNAS
    // =========================================================================

    /** Intentos máximos esperando nuevas importaciones */
    private const MAX_INTENTOS_SIN_NUEVAS = 3;

    /** Segundos de espera entre intentos sin nuevas importaciones */
    private const SEGUNDOS_ESPERA_NUEVAS = 5;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(
        public int $loteId
    ) {}

    // =========================================================================
    // HANDLER PRINCIPAL
    // =========================================================================

    public function handle(): void
    {
        $this->logInicio();

        $lote = Lote::find($this->loteId);
        
        if (!$lote) {
            $this->logLoteNoEncontrado();
            $this->delete();
            return;
        }

        if ($this->esEstadoFinal($lote)) {
            $this->logLoteYaFinalizado($lote);
            $this->delete();
            return;
        }

        $lote->update(['estado' => 'procesando']);

        try {
            $this->procesarImportacionesDelLote($lote);
            $this->finalizarLote($lote);
            $this->delete();
        } catch (\Exception $e) {
            $this->logErrorProcesandoLote($e);
            throw $e;
        }
    }

    // =========================================================================
    // PROCESAMIENTO DE IMPORTACIONES
    // =========================================================================

    /**
     * Procesa todas las importaciones del lote secuencialmente.
     * 
     * Usa un loop con refresh para detectar nuevas importaciones
     * que se agreguen mientras el job está corriendo.
     */
    private function procesarImportacionesDelLote(Lote $lote): void
    {
        $procesadas = [];
        $intentosSinNuevas = 0;
        
        while ($intentosSinNuevas < self::MAX_INTENTOS_SIN_NUEVAS) {
            $lote->refresh();
            
            $importaciones = $this->obtenerImportacionesPendientes($lote, $procesadas);

            if ($importaciones->isEmpty()) {
                $intentosSinNuevas++;
                
                if ($intentosSinNuevas < self::MAX_INTENTOS_SIN_NUEVAS) {
                    $this->logEsperandoNuevasImportaciones($lote, $intentosSinNuevas, count($procesadas));
                    sleep(self::SEGUNDOS_ESPERA_NUEVAS);
                }
                continue;
            }
            
            $intentosSinNuevas = 0;
            $this->logProcesandoImportaciones($lote, $importaciones, count($procesadas));

            foreach ($importaciones as $importacion) {
                $this->procesarImportacion($importacion, $lote);
                $procesadas[] = $importacion->id;
            }
        }
        
        $this->logTodasProcesadas($lote, count($procesadas));
    }

    /**
     * @return Collection<int, Importacion>
     */
    private function obtenerImportacionesPendientes(Lote $lote, array $procesadas): Collection
    {
        return $lote->importaciones()
            ->whereIn('estado', ['pendiente', 'procesando'])
            ->whereNotIn('id', $procesadas)
            ->orderBy('id')
            ->get();
    }

    /**
     * Procesa una importación individual.
     * 
     * IMPORTANTE: Libera memoria después de cada archivo para evitar OOM.
     */
    private function procesarImportacion(Importacion $importacion, Lote $lote): void
    {
        $this->logInicioImportacion($lote, $importacion);
        $this->actualizarCheckpointLote($lote, $importacion);
        $importacion->update(['estado' => 'procesando']);

        $tempPath = null;

        try {
            $tempPath = $this->descargarArchivo($importacion);
            $this->procesarConServicio($importacion, $tempPath);
            $this->cleanup($importacion, $tempPath);
            
            unset($tempPath);
            
            $this->logImportacionCompletada($lote, $importacion);
            $lote->recalcularTotales();
            $this->liberarMemoria();

        } catch (\Exception $e) {
            $this->handleErrorImportacion($importacion, $lote, $tempPath, $e);
        }
    }

    private function procesarConServicio(Importacion $importacion, string $tempPath): void
    {
        $this->actualizarMetadataInicio($importacion, $tempPath);
        
        $this->logCreandoServicio($importacion);
        $service = new ProspectoImportService($importacion, $tempPath);
        
        $this->logIniciandoImport($importacion);
        $service->import();

        $this->verificarYForzarCompletado($importacion, $service);
        
        unset($service);
    }

    private function verificarYForzarCompletado(Importacion $importacion, ProspectoImportService $service): void
    {
        $importacion->refresh();
        
        if ($importacion->estado !== 'procesando') {
            return;
        }

        $this->forceMarkImportacionCompleted($importacion, $service);
    }

    private function forceMarkImportacionCompleted(Importacion $importacion, ProspectoImportService $service): void
    {
        $importacion->update([
            'estado' => 'completado',
            'total_registros' => $service->getRowsProcessed(),
            'registros_exitosos' => $service->getRegistrosExitosos(),
            'registros_fallidos' => $service->getRegistrosFallidos(),
            'metadata' => array_merge($importacion->metadata ?? [], [
                'completado_en' => now()->toISOString(),
                'completado_por' => 'lote_job_fallback',
            ]),
        ]);
    }

    // =========================================================================
    // DESCARGA DE ARCHIVOS
    // =========================================================================

    private function descargarArchivo(Importacion $importacion): string
    {
        $this->logDescargandoArchivo($importacion);
        
        $disk = $importacion->metadata['disk'] ?? 'gcs';
        $rutaArchivo = $importacion->ruta_archivo;

        if (!Storage::disk($disk)->exists($rutaArchivo)) {
            throw new \Exception("Archivo no encontrado en storage: {$rutaArchivo}");
        }

        $content = Storage::disk($disk)->get($rutaArchivo);
        $tempPath = sys_get_temp_dir() . '/' . uniqid('import_') . '.xlsx';
        file_put_contents($tempPath, $content);

        $this->logArchivoDescargado($importacion, strlen($content));

        unset($content);

        return $tempPath;
    }

    // =========================================================================
    // METADATA Y CHECKPOINTS
    // =========================================================================

    private function actualizarCheckpointLote(Lote $lote, Importacion $importacion): void
    {
        $lote->update([
            'metadata' => array_merge($lote->metadata ?? [], [
                'current_importacion_id' => $importacion->id,
                'procesando_desde' => now()->toISOString(),
            ]),
        ]);
    }

    private function actualizarMetadataInicio(Importacion $importacion, string $tempPath): void
    {
        $this->logArchivoDescargadoIniciandoProcesamiento($importacion, $tempPath);
        
        $importacion->update([
            'metadata' => array_merge($importacion->metadata ?? [], [
                'procesamiento_iniciado_en' => now()->toISOString(),
                'file_size_mb' => round(filesize($tempPath) / 1024 / 1024, 2),
            ]),
        ]);
    }

    // =========================================================================
    // CLEANUP Y MEMORIA
    // =========================================================================

    private function cleanup(Importacion $importacion, ?string $tempPath): void
    {
        $this->eliminarArchivoTemporal($tempPath);
        $this->eliminarArchivoStorage($importacion);
    }

    private function eliminarArchivoTemporal(?string $tempPath): void
    {
        if ($tempPath && file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    private function eliminarArchivoStorage(Importacion $importacion): void
    {
        try {
            $disk = $importacion->metadata['disk'] ?? 'gcs';
            Storage::disk($disk)->delete($importacion->ruta_archivo);
        } catch (\Exception $e) {
            Log::warning('ProcesarLoteJob: Error eliminando archivo de GCS', [
                'importacion_id' => $importacion->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function liberarMemoria(): void
    {
        gc_collect_cycles();
    }

    // =========================================================================
    // MANEJO DE ERRORES
    // =========================================================================

    private function handleErrorImportacion(
        Importacion $importacion,
        Lote $lote,
        ?string $tempPath,
        \Exception $e
    ): void {
        $this->eliminarArchivoTemporal($tempPath);

        $this->logErrorEnImportacion($lote, $importacion, $e);

        $importacion->update([
            'estado' => 'fallido',
            'metadata' => array_merge($importacion->metadata ?? [], [
                'error' => $e->getMessage(),
                'fallido_en' => now()->toISOString(),
            ]),
        ]);

        $lote->recalcularTotales();
        $this->liberarMemoria();
    }

    // =========================================================================
    // FINALIZACION
    // =========================================================================

    private function esEstadoFinal(Lote $lote): bool
    {
        return in_array($lote->estado, ['completado', 'fallido']);
    }

    private function finalizarLote(Lote $lote): void
    {
        $lote->refresh();
        $lote->recalcularTotales();

        $importaciones = $lote->importaciones()->get();
        
        if (!$this->todasFinalizadas($importaciones)) {
            return;
        }

        $algunaFallida = $this->tieneAlgunaFallida($importaciones);
        $estadoFinal = $algunaFallida ? 'fallido' : 'completado';
        
        $this->marcarLoteComoFinalizado($lote, $estadoFinal);
        $this->logLoteFinalizado($lote, $estadoFinal);
    }

    private function todasFinalizadas(Collection $importaciones): bool
    {
        return $importaciones->every(
            fn($i) => in_array($i->estado, ['completado', 'fallido'])
        );
    }

    private function tieneAlgunaFallida(Collection $importaciones): bool
    {
        return $importaciones->contains(fn($i) => $i->estado === 'fallido');
    }

    private function marcarLoteComoFinalizado(Lote $lote, string $estadoFinal): void
    {
        $lote->update([
            'estado' => $estadoFinal,
            'cerrado_en' => now(),
            'metadata' => array_merge($lote->metadata ?? [], [
                'finalizado_en' => now()->toISOString(),
                'finalizado_por' => 'lote_job',
            ]),
        ]);
    }

    // =========================================================================
    // LOGGING
    // =========================================================================

    private function logInicio(): void
    {
        Log::info('ProcesarLoteJob: Iniciando', ['lote_id' => $this->loteId]);
    }

    private function logLoteNoEncontrado(): void
    {
        Log::error('ProcesarLoteJob: Lote no encontrado', ['lote_id' => $this->loteId]);
    }

    private function logLoteYaFinalizado(Lote $lote): void
    {
        Log::info('ProcesarLoteJob: Lote ya finalizado', [
            'lote_id' => $this->loteId,
            'estado' => $lote->estado,
        ]);
    }

    private function logErrorProcesandoLote(\Exception $e): void
    {
        Log::error('ProcesarLoteJob: Error procesando lote', [
            'lote_id' => $this->loteId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    private function logEsperandoNuevasImportaciones(Lote $lote, int $intento, int $procesadas): void
    {
        Log::info('ProcesarLoteJob: Sin importaciones pendientes, esperando...', [
            'lote_id' => $lote->id,
            'intento' => $intento,
            'procesadas' => $procesadas,
        ]);
    }

    private function logProcesandoImportaciones(Lote $lote, Collection $importaciones, int $yaProcesadas): void
    {
        Log::info('ProcesarLoteJob: Procesando importaciones', [
            'lote_id' => $lote->id,
            'total_importaciones' => $importaciones->count(),
            'importacion_ids' => $importaciones->pluck('id')->toArray(),
            'ya_procesadas' => $yaProcesadas,
        ]);
    }

    private function logTodasProcesadas(Lote $lote, int $total): void
    {
        Log::info('ProcesarLoteJob: Todas las importaciones procesadas', [
            'lote_id' => $lote->id,
            'total_procesadas' => $total,
        ]);
    }

    private function logInicioImportacion(Lote $lote, Importacion $importacion): void
    {
        Log::info('ProcesarLoteJob: Iniciando importación', [
            'lote_id' => $lote->id,
            'importacion_id' => $importacion->id,
            'archivo' => $importacion->nombre_archivo,
            'memoria_antes_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    private function logDescargandoArchivo(Importacion $importacion): void
    {
        Log::info('ProcesarLoteJob: Descargando archivo...', [
            'importacion_id' => $importacion->id,
        ]);
    }

    private function logArchivoDescargado(Importacion $importacion, int $sizeBytes): void
    {
        Log::info('ProcesarLoteJob: Archivo descargado', [
            'importacion_id' => $importacion->id,
            'size_mb' => round($sizeBytes / 1024 / 1024, 2),
        ]);
    }

    private function logArchivoDescargadoIniciandoProcesamiento(Importacion $importacion, string $tempPath): void
    {
        Log::info('ProcesarLoteJob: Archivo descargado, iniciando procesamiento...', [
            'importacion_id' => $importacion->id,
            'file_size_mb' => round(filesize($tempPath) / 1024 / 1024, 2),
            'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    private function logCreandoServicio(Importacion $importacion): void
    {
        Log::info('ProcesarLoteJob: Creando ProspectoImportService...', [
            'importacion_id' => $importacion->id,
        ]);
    }

    private function logIniciandoImport(Importacion $importacion): void
    {
        Log::info('ProcesarLoteJob: Iniciando import()...', [
            'importacion_id' => $importacion->id,
            'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    private function logImportacionCompletada(Lote $lote, Importacion $importacion): void
    {
        Log::info('ProcesarLoteJob: Importación completada', [
            'lote_id' => $lote->id,
            'importacion_id' => $importacion->id,
            'registros_exitosos' => $importacion->fresh()->registros_exitosos,
            'memoria_despues_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    private function logErrorEnImportacion(Lote $lote, Importacion $importacion, \Exception $e): void
    {
        Log::error('ProcesarLoteJob: Error en importación', [
            'lote_id' => $lote->id,
            'importacion_id' => $importacion->id,
            'error' => $e->getMessage(),
            'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    private function logLoteFinalizado(Lote $lote, string $estadoFinal): void
    {
        Log::info('ProcesarLoteJob: Lote finalizado', [
            'lote_id' => $lote->id,
            'estado' => $estadoFinal,
            'total_registros' => $lote->total_registros,
            'registros_exitosos' => $lote->registros_exitosos,
        ]);
    }
}
