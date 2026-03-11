<?php

namespace App\Jobs;

use App\Models\Envio;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job de limpieza mensual para mantener la BD en tamaño manejable.
 *
 * Política de retención: 3 meses
 *
 * Acciones:
 * 1. Archivar prospectos sin interacción en 3+ meses
 * 2. Eliminar envíos de más de 3 meses (stats ya agregadas en envios_mensuales)
 * 3. Eliminar prospecto_en_flujo completados/cancelados de más de 3 meses
 *
 * IMPORTANTE: Ejecutar DESPUÉS de AgregarEnviosMensualesJob para no perder stats.
 */
class LimpiezaMensualJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600; // 1 hora

    public int $tries = 3;

    public int $uniqueFor = 3600;

    private const CHUNK_SIZE = 5000;

    private const MESES_RETENCION = 3;

    public function __construct(
        public bool $dryRun = false
    ) {}

    public function uniqueId(): string
    {
        return 'limpieza-mensual-'.now()->format('Y-m');
    }

    public function handle(): void
    {
        $inicio = now();
        $fechaCorte = now()->subMonths(self::MESES_RETENCION);

        Log::info('🧹 Iniciando limpieza mensual', [
            'fecha_corte' => $fechaCorte->toDateString(),
            'meses_retencion' => self::MESES_RETENCION,
            'dry_run' => $this->dryRun,
        ]);

        $resultados = [
            'prospectos_archivados' => $this->archivarProspectosInactivos($fechaCorte),
            'envios_eliminados' => $this->eliminarEnviosViejos($fechaCorte),
            'prospecto_en_flujo_eliminados' => $this->eliminarProspectoEnFlujoViejos($fechaCorte),
        ];

        $duracion = now()->diffInSeconds($inicio);

        Log::info('✅ Limpieza mensual completada', [
            ...$resultados,
            'duracion_segundos' => $duracion,
            'dry_run' => $this->dryRun,
        ]);
    }

    /**
     * Archiva prospectos sin interacción en los últimos N meses.
     *
     * Criterio: fecha_ultimo_contacto < fecha_corte O nunca contactado y created_at < fecha_corte
     */
    private function archivarProspectosInactivos(\Carbon\Carbon $fechaCorte): int
    {
        $query = Prospecto::query()
            ->where('estado', 'activo')
            ->where(function ($q) use ($fechaCorte) {
                $q->where('fecha_ultimo_contacto', '<', $fechaCorte)
                    ->orWhere(function ($q2) use ($fechaCorte) {
                        $q2->whereNull('fecha_ultimo_contacto')
                            ->where('created_at', '<', $fechaCorte);
                    });
            });

        $total = $query->count();

        Log::info('📊 Prospectos a archivar', ['total' => $total]);

        if ($this->dryRun || $total === 0) {
            return $total;
        }

        // Actualizar en chunks para no bloquear la BD
        $actualizados = 0;

        $query->chunkById(self::CHUNK_SIZE, function ($prospectos) use (&$actualizados) {
            $ids = $prospectos->pluck('id')->toArray();

            Prospecto::whereIn('id', $ids)->update([
                'estado' => 'archivado',
                'updated_at' => now(),
            ]);

            $actualizados += count($ids);

            Log::info('📦 Chunk de prospectos archivados', ['actualizados' => $actualizados]);
        });

        return $actualizados;
    }

    /**
     * Elimina envíos de más de N meses.
     *
     * IMPORTANTE: Las estadísticas ya están en envios_mensuales,
     * así que podemos eliminar los registros individuales.
     */
    private function eliminarEnviosViejos(\Carbon\Carbon $fechaCorte): int
    {
        $total = Envio::where('created_at', '<', $fechaCorte)->count();

        Log::info('📊 Envíos a eliminar', ['total' => $total]);

        if ($this->dryRun || $total === 0) {
            return $total;
        }

        $eliminados = 0;

        // Eliminar en chunks usando DELETE con LIMIT (más eficiente que chunkById para deletes)
        do {
            $deleted = Envio::where('created_at', '<', $fechaCorte)
                ->limit(self::CHUNK_SIZE)
                ->delete();

            $eliminados += $deleted;

            if ($deleted > 0) {
                Log::info('🗑️ Chunk de envíos eliminados', [
                    'eliminados_chunk' => $deleted,
                    'total_eliminados' => $eliminados,
                ]);

                // Dar respiro a la BD
                usleep(100000); // 100ms
            }
        } while ($deleted > 0);

        return $eliminados;
    }

    /**
     * Elimina registros de prospecto_en_flujo completados o cancelados de más de N meses.
     */
    private function eliminarProspectoEnFlujoViejos(\Carbon\Carbon $fechaCorte): int
    {
        $query = ProspectoEnFlujo::query()
            ->where('updated_at', '<', $fechaCorte)
            ->where(function ($q) {
                $q->where('completado', true)
                    ->orWhere('cancelado', true);
            });

        $total = $query->count();

        Log::info('📊 ProspectoEnFlujo a eliminar', ['total' => $total]);

        if ($this->dryRun || $total === 0) {
            return $total;
        }

        $eliminados = 0;

        do {
            $deleted = ProspectoEnFlujo::query()
                ->where('updated_at', '<', $fechaCorte)
                ->where(function ($q) {
                    $q->where('completado', true)
                        ->orWhere('cancelado', true);
                })
                ->limit(self::CHUNK_SIZE)
                ->delete();

            $eliminados += $deleted;

            if ($deleted > 0) {
                Log::info('🗑️ Chunk de prospecto_en_flujo eliminados', [
                    'eliminados_chunk' => $deleted,
                    'total_eliminados' => $eliminados,
                ]);

                usleep(100000); // 100ms
            }
        } while ($deleted > 0);

        return $eliminados;
    }

    /**
     * Maneja fallos del job.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('❌ Error en limpieza mensual', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
