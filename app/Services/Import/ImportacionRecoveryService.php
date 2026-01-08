<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Jobs\ProcesarImportacionJob;
use App\Models\Importacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Servicio de recuperación automática de importaciones "stuck".
 * 
 * Detecta importaciones que quedaron en estado "procesando" sin actualización
 * reciente (proceso murió abruptamente) y las re-encola automáticamente.
 * 
 * Este servicio se ejecuta al inicio de cada worker para garantizar
 * que ninguna importación quede abandonada.
 * 
 * IMPORTANTE: Es idempotente y seguro para ejecución concurrente.
 */
final class ImportacionRecoveryService
{
    // =========================================================================
    // CONFIGURACIÓN
    // =========================================================================

    /** Minutos sin actualización para considerar una importación como "stuck" */
    private const STUCK_THRESHOLD_MINUTES = 3;

    /** Máximo de importaciones a recuperar por ejecución */
    private const MAX_RECOVERIES_PER_RUN = 5;

    /** Tolerancia de registros para considerar importación completada */
    private const COMPLETION_TOLERANCE_RECORDS = 10;

    /** Porcentaje mínimo procesado para considerar completada */
    private const COMPLETION_THRESHOLD_PERCENTAGE = 0.99;

    /** Mínimo de registros exitosos para aplicar regla de porcentaje */
    private const MIN_RECORDS_FOR_PERCENTAGE_RULE = 1000;

    /** Delay en segundos antes de re-encolar (evita race conditions) */
    private const REQUEUE_DELAY_SECONDS = 5;

    // =========================================================================
    // API PÚBLICA
    // =========================================================================

    /**
     * Detecta y recupera importaciones stuck.
     * 
     * @return array{recovered: int, importaciones: array<int>}
     */
    public function recoverStuckImportations(): array
    {
        $stuckImportaciones = $this->findStuckImportaciones();
        
        if ($stuckImportaciones->isEmpty()) {
            Log::info('ImportacionRecoveryService: No hay importaciones stuck');
            return $this->buildRecoveryResult([]);
        }

        $recoveredIds = $this->processStuckImportaciones($stuckImportaciones);

        $this->logRecoveryResult($stuckImportaciones->count(), $recoveredIds);

        return $this->buildRecoveryResult($recoveredIds);
    }

    /**
     * Obtiene estadísticas de importaciones para health check.
     */
    public function getHealthStats(): array
    {
        return [
            'importaciones_por_estado' => $this->getImportacionesPorEstado(),
            'stuck_count' => $this->getStuckCount(),
            'jobs_in_queue' => $this->getJobsInQueue(),
            'threshold_minutes' => self::STUCK_THRESHOLD_MINUTES,
            'checked_at' => now()->toISOString(),
        ];
    }

    // =========================================================================
    // BÚSQUEDA DE IMPORTACIONES STUCK
    // =========================================================================

    /**
     * Encuentra importaciones que están "stuck" (procesando sin updates recientes).
     * 
     * @return Collection<int, Importacion>
     */
    private function findStuckImportaciones(): Collection
    {
        $threshold = now()->subMinutes(self::STUCK_THRESHOLD_MINUTES);

        return Importacion::where('estado', 'procesando')
            ->where('updated_at', '<', $threshold)
            ->orderBy('updated_at', 'asc') // Las más antiguas primero
            ->limit(self::MAX_RECOVERIES_PER_RUN)
            ->get();
    }

    /**
     * Intenta recuperar una importación específica.
     * 
     * Verifica que no exista ya un job en cola para evitar duplicados.
     * También detecta importaciones que terminaron pero no se marcaron como completadas.
     */
    private function tryRecoverImportacion(Importacion $importacion): bool
    {
        if ($this->hasExistingJob($importacion->id)) {
            $this->logJobAlreadyExists($importacion->id);
            return false;
        }

        if (!$this->validateFileExists($importacion)) {
            return $this->handleMissingFile($importacion);
        }

        return $this->requeue($importacion);
    }

    /**
     * Maneja el caso donde el archivo no existe.
     * 
     * Si la importación parece completa (tiene registros procesados), la marca como completada.
     * Si no, la marca como fallida.
     */
    private function handleMissingFile(Importacion $importacion): bool
    {
        if ($this->shouldMarkAsCompleted($importacion)) {
            $this->logCompletingWithoutFile($importacion);
            $this->markAsCompleted($importacion);
            return true;
        }
        
        $this->logFileMissing($importacion);
        $this->markAsFailed($importacion, 'Archivo no encontrado durante recovery');
        return false;
    }
    
    /**
     * Determina si una importación sin archivo debería marcarse como completada.
     * 
     * Criterios:
     * - Tiene registros exitosos > 0
     * - La diferencia entre total_registros y registros_exitosos es mínima (<=10 o <1%)
     * - Esto indica que el proceso terminó pero murió antes de markAsCompleted()
     */
    private function shouldMarkAsCompleted(Importacion $importacion): bool
    {
        $exitosos = $importacion->registros_exitosos ?? 0;
        $total = $importacion->total_registros ?? 0;
        $fallidos = $importacion->registros_fallidos ?? 0;
        $procesados = $exitosos + $fallidos;

        if ($this->hasNoRecordsProcessed($exitosos, $total)) {
            return false;
        }

        return $this->isWithinCompletionTolerance($procesados, $total)
            || $this->meetsPercentageThreshold($exitosos, $procesados, $total);
    }

    private function hasNoRecordsProcessed(int $exitosos, int $total): bool
    {
        return $exitosos === 0 && $total === 0;
    }

    private function isWithinCompletionTolerance(int $procesados, int $total): bool
    {
        return $total > 0 && $procesados >= ($total - self::COMPLETION_TOLERANCE_RECORDS);
    }

    private function meetsPercentageThreshold(int $exitosos, int $procesados, int $total): bool
    {
        if ($exitosos <= self::MIN_RECORDS_FOR_PERCENTAGE_RULE || $total === 0) {
            return false;
        }

        return ($procesados / $total) > self::COMPLETION_THRESHOLD_PERCENTAGE;
    }
    
    /**
     * Marca una importación como completada (para casos donde terminó pero no se marcó).
     */
    private function markAsCompleted(Importacion $importacion): void
    {
        $importacion->update([
            'estado' => 'completado',
            'metadata' => array_merge($importacion->metadata ?? [], [
                'completado_en' => now()->toISOString(),
                'completado_por' => 'recovery_service',
                'nota' => 'Marcado como completado por recovery - proceso terminó pero no se guardó estado',
            ]),
        ]);
        
        // Actualizar el lote si existe
        $this->updateLoteIfExists($importacion);
    }
    
    /**
     * Actualiza el lote padre cuando una importación se marca como completada por recovery.
     */
    private function updateLoteIfExists(Importacion $importacion): void
    {
        $importacion->refresh();
        
        if (!$importacion->lote_id) {
            return;
        }

        $lote = $importacion->lote;
        if (!$lote) {
            return;
        }

        // Recalcular totales del lote
        $importaciones = $lote->importaciones()->get();
        
        $totalRegistros = $importaciones->sum('total_registros');
        $registrosExitosos = $importaciones->sum('registros_exitosos');
        $registrosFallidos = $importaciones->sum('registros_fallidos');
        
        $todasCompletadas = $importaciones->every(fn ($imp) => in_array($imp->estado, ['completado', 'fallido']));
        $algunaFallida = $importaciones->contains(fn ($imp) => $imp->estado === 'fallido');
        
        $estadoLote = $lote->estado;
        if ($todasCompletadas) {
            $estadoLote = $algunaFallida ? 'fallido' : 'completado';
        }

        $lote->update([
            'total_registros' => $totalRegistros,
            'registros_exitosos' => $registrosExitosos,
            'registros_fallidos' => $registrosFallidos,
            'estado' => $estadoLote,
            'cerrado_en' => $todasCompletadas ? now() : null,
        ]);

        $this->logLoteUpdated($lote->id, $estadoLote, $totalRegistros);
    }

    /**
     * Verifica si ya existe un job en cola para esta importación.
     * 
     * Busca en el payload del job serializado.
     */
    private function hasExistingJob(int $importacionId): bool
    {
        $searchPattern = '"importacionId";i:' . $importacionId . ';';
        
        $exists = DB::table('jobs')
            ->where('payload', 'like', '%ProcesarImportacionJob%')
            ->where('payload', 'like', '%' . $searchPattern . '%')
            ->exists();

        return $exists;
    }

    /**
     * Valida que el archivo de la importación aún existe en storage.
     */
    private function validateFileExists(Importacion $importacion): bool
    {
        if (empty($importacion->ruta_archivo)) {
            return false;
        }

        try {
            return Storage::disk('gcs')->exists($importacion->ruta_archivo);
        } catch (\Exception $e) {
            $this->logFileCheckError($importacion, $e);
            return false;
        }
    }

    /**
     * Re-encola el job de importación.
     */
    private function requeue(Importacion $importacion): bool
    {
        try {
            $checkpoint = $importacion->metadata['last_processed_row'] ?? 0;
            
            $this->logRequeuing($importacion, $checkpoint);
            $this->updateRecoveryMetadata($importacion, $checkpoint);
            $this->dispatchWithDelay($importacion);

            return true;
        } catch (\Exception $e) {
            $this->logRequeueError($importacion, $e);
            return false;
        }
    }

    private function updateRecoveryMetadata(Importacion $importacion, int $checkpoint): void
    {
        $importacion->update([
            'metadata' => array_merge($importacion->metadata ?? [], [
                'recovery_at' => now()->toISOString(),
                'recovery_from_checkpoint' => $checkpoint,
            ]),
        ]);
    }

    private function dispatchWithDelay(Importacion $importacion): void
    {
        ProcesarImportacionJob::dispatch(
            $importacion->id,
            $importacion->ruta_archivo,
            'gcs'
        )->delay(now()->addSeconds(self::REQUEUE_DELAY_SECONDS));
    }

    /**
     * Marca una importación como fallida.
     */
    private function markAsFailed(Importacion $importacion, string $reason): void
    {
        $importacion->update([
            'estado' => 'fallido',
            'metadata' => array_merge($importacion->metadata ?? [], [
                'error' => $reason,
                'fallido_en' => now()->toISOString(),
                'fallido_por' => 'recovery_service',
            ]),
        ]);
    }

    // =========================================================================
    // HELPERS PARA HEALTH STATS
    // =========================================================================

    /**
     * @return array<string, int>
     */
    private function getImportacionesPorEstado(): array
    {
        return DB::table('importaciones')
            ->select('estado', DB::raw('count(*) as count'))
            ->groupBy('estado')
            ->pluck('count', 'estado')
            ->toArray();
    }

    private function getStuckCount(): int
    {
        return Importacion::where('estado', 'procesando')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_THRESHOLD_MINUTES))
            ->count();
    }

    private function getJobsInQueue(): int
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%ProcesarImportacionJob%')
            ->count();
    }

    // =========================================================================
    // HELPERS PARA RECOVERY
    // =========================================================================

    /**
     * @param Collection<int, Importacion> $importaciones
     * @return array<int>
     */
    private function processStuckImportaciones(Collection $importaciones): array
    {
        $recovered = [];

        foreach ($importaciones as $importacion) {
            if ($this->tryRecoverImportacion($importacion)) {
                $recovered[] = $importacion->id;
            }
        }

        return $recovered;
    }

    /**
     * @param array<int> $recoveredIds
     */
    private function logRecoveryResult(int $totalStuck, array $recoveredIds): void
    {
        Log::info('ImportacionRecoveryService: Recovery completado', [
            'total_stuck' => $totalStuck,
            'recovered' => count($recoveredIds),
            'importacion_ids' => $recoveredIds,
        ]);
    }

    /**
     * @param array<int> $recoveredIds
     * @return array{recovered: int, importaciones: array<int>}
     */
    private function buildRecoveryResult(array $recoveredIds): array
    {
        return [
            'recovered' => count($recoveredIds),
            'importaciones' => $recoveredIds,
        ];
    }

    // =========================================================================
    // LOGGING
    // =========================================================================

    private function logJobAlreadyExists(int $importacionId): void
    {
        Log::info('ImportacionRecoveryService: Job ya existe en cola', [
            'importacion_id' => $importacionId,
        ]);
    }

    private function logCompletingWithoutFile(Importacion $importacion): void
    {
        Log::info('ImportacionRecoveryService: Archivo no existe pero hay registros, marcando como completado', [
            'importacion_id' => $importacion->id,
            'registros_exitosos' => $importacion->registros_exitosos,
            'total_registros' => $importacion->total_registros,
        ]);
    }

    private function logFileMissing(Importacion $importacion): void
    {
        Log::warning('ImportacionRecoveryService: Archivo no encontrado, marcando como fallido', [
            'importacion_id' => $importacion->id,
            'ruta_archivo' => $importacion->ruta_archivo,
        ]);
    }

    private function logRequeuing(Importacion $importacion, int $checkpoint): void
    {
        Log::info('ImportacionRecoveryService: Re-encolando importación', [
            'importacion_id' => $importacion->id,
            'checkpoint' => $checkpoint,
            'minutos_sin_update' => now()->diffInMinutes($importacion->updated_at),
        ]);
    }

    private function logRequeueError(Importacion $importacion, \Exception $e): void
    {
        Log::error('ImportacionRecoveryService: Error re-encolando', [
            'importacion_id' => $importacion->id,
            'error' => $e->getMessage(),
        ]);
    }

    private function logLoteUpdated(int $loteId, string $estado, int $totalRegistros): void
    {
        Log::info('ImportacionRecoveryService: Lote actualizado por recovery', [
            'lote_id' => $loteId,
            'estado' => $estado,
            'total_registros' => $totalRegistros,
        ]);
    }

    private function logFileCheckError(Importacion $importacion, \Exception $e): void
    {
        Log::warning('ImportacionRecoveryService: Error verificando archivo', [
            'importacion_id' => $importacion->id,
            'error' => $e->getMessage(),
        ]);
    }
}
