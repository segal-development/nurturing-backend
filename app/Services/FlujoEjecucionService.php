<?php

namespace App\Services;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlujoEjecucionService
{
    /**
     * Resume a paused execution, recalculating all pending etapa dates.
     *
     * This prevents mass-fire by shifting all future dates based on
     * how long the execution was paused.
     */
    public function resume(FlujoEjecucion $ejecucion): FlujoEjecucion
    {
        // Calculate pause duration using pausada_en or fallback to updated_at
        $pausedAt = $ejecucion->pausada_en ?? $ejecucion->updated_at;
        $pauseDuration = now()->diffInSeconds($pausedAt);

        Log::info('FlujoEjecucionService: Resuming execution', [
            'ejecucion_id' => $ejecucion->id,
            'paused_at' => $pausedAt,
            'pause_duration_seconds' => $pauseDuration,
        ]);

        DB::transaction(function () use ($ejecucion, $pauseDuration) {
            // Shift all pending etapa dates forward
            $pendingEtapas = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
                ->whereIn('estado', ['pending', 'scheduled'])
                ->whereNotNull('fecha_programada')
                ->get();

            foreach ($pendingEtapas as $etapa) {
                $newDate = $etapa->fecha_programada->addSeconds($pauseDuration);
                $etapa->fecha_programada = $newDate;
                $etapa->save();

                Log::debug('FlujoEjecucionService: Shifted etapa date', [
                    'etapa_id' => $etapa->id,
                    'old_date' => $etapa->getOriginal('fecha_programada'),
                    'new_date' => $newDate,
                ]);
            }

            // Find next etapa date for fecha_proximo_nodo
            $nextEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
                ->whereIn('estado', ['pending', 'scheduled'])
                ->whereNotNull('fecha_programada')
                ->orderBy('fecha_programada')
                ->first();

            // Update execution state
            $ejecucion->estado = 'in_progress';
            $ejecucion->pausada_en = null;
            $ejecucion->fecha_proximo_nodo = $nextEtapa?->fecha_programada;
            $ejecucion->save();

            Log::info('FlujoEjecucionService: Resume completed', [
                'ejecucion_id' => $ejecucion->id,
                'etapas_shifted' => $pendingEtapas->count(),
                'next_etapa_date' => $ejecucion->fecha_proximo_nodo,
            ]);
        });

        return $ejecucion->fresh();
    }

    /**
     * Pause an execution and record the pause timestamp.
     */
    public function pause(FlujoEjecucion $ejecucion): FlujoEjecucion
    {
        $ejecucion->estado = 'paused';
        $ejecucion->pausada_en = now();
        $ejecucion->save();

        Log::info('FlujoEjecucionService: Execution paused', [
            'ejecucion_id' => $ejecucion->id,
            'paused_at' => $ejecucion->pausada_en,
        ]);

        return $ejecucion;
    }
}
