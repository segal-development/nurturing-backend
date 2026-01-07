<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Jobs\ProcesarImportacionJob;
use App\Models\Importacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    /**
     * Minutos sin actualización para considerar una importación como "stuck".
     * Debe ser mayor que el intervalo de checkpoints (cada ~38 segundos a 130 reg/s).
     */
    private const STUCK_THRESHOLD_MINUTES = 3;

    /**
     * Máximo de importaciones a recuperar por ejecución.
     * Previene sobrecarga si hay muchas stuck.
     */
    private const MAX_RECOVERIES_PER_RUN = 5;

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
            return ['recovered' => 0, 'importaciones' => []];
        }

        $recovered = [];

        foreach ($stuckImportaciones as $importacion) {
            if ($this->tryRecoverImportacion($importacion)) {
                $recovered[] = $importacion->id;
            }
        }

        Log::info('ImportacionRecoveryService: Recovery completado', [
            'total_stuck' => $stuckImportaciones->count(),
            'recovered' => count($recovered),
            'importacion_ids' => $recovered,
        ]);

        return [
            'recovered' => count($recovered),
            'importaciones' => $recovered,
        ];
    }

    /**
     * Encuentra importaciones que están "stuck" (procesando sin updates recientes).
     */
    private function findStuckImportaciones()
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
        // Verificar si ya hay un job en cola para esta importación
        if ($this->hasExistingJob($importacion->id)) {
            Log::info('ImportacionRecoveryService: Job ya existe en cola', [
                'importacion_id' => $importacion->id,
            ]);
            return false;
        }

        // Verificar que el archivo aún existe
        if (!$this->validateFileExists($importacion)) {
            // CASO ESPECIAL: Si el archivo no existe pero hay registros procesados,
            // probablemente el proceso terminó exitosamente pero murió antes de markAsCompleted()
            // En ese caso, marcamos como completado, no como fallido.
            if ($this->shouldMarkAsCompleted($importacion)) {
                Log::info('ImportacionRecoveryService: Archivo no existe pero hay registros, marcando como completado', [
                    'importacion_id' => $importacion->id,
                    'registros_exitosos' => $importacion->registros_exitosos,
                    'total_registros' => $importacion->total_registros,
                ]);
                $this->markAsCompleted($importacion);
                return true;
            }
            
            Log::warning('ImportacionRecoveryService: Archivo no encontrado, marcando como fallido', [
                'importacion_id' => $importacion->id,
                'ruta_archivo' => $importacion->ruta_archivo,
            ]);
            $this->markAsFailed($importacion, 'Archivo no encontrado durante recovery');
            return false;
        }

        // Re-encolar el job
        return $this->requeue($importacion);
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
        
        // Si no hay registros procesados, no está completo
        if ($exitosos === 0 && $total === 0) {
            return false;
        }
        
        // Si exitosos + fallidos >= total - 10, consideramos que terminó
        // (tolerancia de 10 registros por posibles off-by-one en conteo)
        $procesados = $exitosos + $fallidos;
        
        if ($total > 0 && $procesados >= ($total - 10)) {
            return true;
        }
        
        // Si tiene muchos exitosos (> 1000) y el total es similar, también
        if ($exitosos > 1000 && $total > 0 && ($procesados / $total) > 0.99) {
            return true;
        }
        
        return false;
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

        Log::info('ImportacionRecoveryService: Lote actualizado por recovery', [
            'lote_id' => $lote->id,
            'estado' => $estadoLote,
            'total_registros' => $totalRegistros,
        ]);
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
            $disk = \Illuminate\Support\Facades\Storage::disk('gcs');
            return $disk->exists($importacion->ruta_archivo);
        } catch (\Exception $e) {
            Log::warning('ImportacionRecoveryService: Error verificando archivo', [
                'importacion_id' => $importacion->id,
                'error' => $e->getMessage(),
            ]);
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
            
            Log::info('ImportacionRecoveryService: Re-encolando importación', [
                'importacion_id' => $importacion->id,
                'checkpoint' => $checkpoint,
                'minutos_sin_update' => now()->diffInMinutes($importacion->updated_at),
            ]);

            // Actualizar timestamp para indicar que el recovery lo tocó
            $importacion->update([
                'metadata' => array_merge($importacion->metadata ?? [], [
                    'recovery_at' => now()->toISOString(),
                    'recovery_from_checkpoint' => $checkpoint,
                ]),
            ]);

            // Dispatch con delay de 5 segundos para evitar race conditions
            ProcesarImportacionJob::dispatch(
                $importacion->id,
                $importacion->ruta_archivo,
                'gcs'
            )->delay(now()->addSeconds(5));

            return true;

        } catch (\Exception $e) {
            Log::error('ImportacionRecoveryService: Error re-encolando', [
                'importacion_id' => $importacion->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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

    /**
     * Obtiene estadísticas de importaciones para health check.
     */
    public function getHealthStats(): array
    {
        $stats = DB::table('importaciones')
            ->select('estado', DB::raw('count(*) as count'))
            ->groupBy('estado')
            ->pluck('count', 'estado')
            ->toArray();

        $stuckCount = Importacion::where('estado', 'procesando')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_THRESHOLD_MINUTES))
            ->count();

        $jobsInQueue = DB::table('jobs')
            ->where('payload', 'like', '%ProcesarImportacionJob%')
            ->count();

        return [
            'importaciones_por_estado' => $stats,
            'stuck_count' => $stuckCount,
            'jobs_in_queue' => $jobsInQueue,
            'threshold_minutes' => self::STUCK_THRESHOLD_MINUTES,
            'checked_at' => now()->toISOString(),
        ];
    }
}
