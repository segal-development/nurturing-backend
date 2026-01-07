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
