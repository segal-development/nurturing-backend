<?php

namespace App\Jobs;

use App\Models\ProspectoEnFlujo;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Limpieza diaria de prospectos completados en flujos perpetuos.
 *
 * Los flujos perpetuos acumulan prospectos completados rápidamente.
 * Este job elimina los que llevan más de 1 día completados para
 * mantener la tabla liviana.
 *
 * Corre diariamente a las 3:00 AM (horario de baja carga).
 */
class LimpiezaProspectosPerpetuosJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800; // 30 minutos

    public int $tries = 3;

    public int $uniqueFor = 3600;

    private const CHUNK_SIZE = 1000;

    private const DIAS_RETENCION = 1;

    public function __construct(
        public bool $dryRun = false
    ) {}

    public function uniqueId(): string
    {
        return 'limpieza-prospectos-perpetuos-'.now()->format('Y-m-d');
    }

    public function handle(): void
    {
        $inicio = now();
        $fechaCorte = now()->subDays(self::DIAS_RETENCION);

        Log::info('🧹 Iniciando limpieza de prospectos perpetuos', [
            'fecha_corte' => $fechaCorte->toDateTimeString(),
            'dias_retencion' => self::DIAS_RETENCION,
            'dry_run' => $this->dryRun,
        ]);

        $eliminados = $this->eliminarProspectosCompletados($fechaCorte);

        $duracion = now()->diffInSeconds($inicio);

        Log::info('✅ Limpieza de prospectos perpetuos completada', [
            'eliminados' => $eliminados,
            'duracion_segundos' => $duracion,
            'dry_run' => $this->dryRun,
        ]);
    }

    /**
     * Elimina prospectos completados de flujos perpetuos que llevan más de N días.
     */
    private function eliminarProspectosCompletados(\Carbon\Carbon $fechaCorte): int
    {
        // Solo flujos perpetuos (es_perpetuo = true)
        $query = ProspectoEnFlujo::query()
            ->whereHas('flujo', fn ($q) => $q->where('es_perpetuo', true))
            ->where('completado', true)
            ->where('updated_at', '<', $fechaCorte);

        $total = $query->count();

        Log::info('📊 Prospectos perpetuos completados a eliminar', ['total' => $total]);

        if ($this->dryRun || $total === 0) {
            return $total;
        }

        $eliminados = 0;

        // Obtener IDs de flujos perpetuos para query más eficiente
        $flujosPerpetuo = DB::table('flujos')
            ->where('es_perpetuo', true)
            ->pluck('id')
            ->toArray();

        if (empty($flujosPerpetuo)) {
            Log::info('ℹ️ No hay flujos perpetuos configurados');
            return 0;
        }

        // Eliminar en chunks
        do {
            $deleted = ProspectoEnFlujo::query()
                ->whereIn('flujo_id', $flujosPerpetuo)
                ->where('completado', true)
                ->where('updated_at', '<', $fechaCorte)
                ->limit(self::CHUNK_SIZE)
                ->delete();

            $eliminados += $deleted;

            if ($deleted > 0) {
                Log::info('🗑️ Chunk eliminado', [
                    'eliminados_chunk' => $deleted,
                    'total_eliminados' => $eliminados,
                ]);

                // Dar respiro a la BD
                usleep(50000); // 50ms
            }
        } while ($deleted > 0);

        return $eliminados;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('❌ Error en limpieza de prospectos perpetuos', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
