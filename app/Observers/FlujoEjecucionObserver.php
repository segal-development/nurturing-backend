<?php

namespace App\Observers;

use App\Models\FlujoEjecucion;
use App\Services\CostoService;
use Illuminate\Support\Facades\Log;

class FlujoEjecucionObserver
{
    public function __construct(
        private CostoService $costoService
    ) {}

    /**
     * Handle the FlujoEjecucion "created" event.
     * Calculate and save estimated cost when execution starts.
     */
    public function created(FlujoEjecucion $flujoEjecucion): void
    {
        try {
            // Calculate estimated cost based on flow structure and number of prospects
            $flujo = $flujoEjecucion->flujo;
            $cantidadProspectos = count($flujoEjecucion->prospectos_ids ?? []);

            if ($flujo && $cantidadProspectos > 0) {
                $costoEstimado = $this->costoService->calcularCostoEstimado($flujo, $cantidadProspectos);
                
                // Update without triggering another observer event
                FlujoEjecucion::withoutEvents(function () use ($flujoEjecucion, $costoEstimado) {
                    $flujoEjecucion->update([
                        'costo_estimado' => $costoEstimado['resumen']['costo_total'],
                    ]);
                });

                Log::info('FlujoEjecucionObserver: Costo estimado guardado', [
                    'ejecucion_id' => $flujoEjecucion->id,
                    'costo_estimado' => $costoEstimado['resumen']['costo_total'],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('FlujoEjecucionObserver: Error calculando costo estimado', [
                'ejecucion_id' => $flujoEjecucion->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the FlujoEjecucion "updated" event.
     * Calculate real cost when execution completes.
     */
    public function updated(FlujoEjecucion $flujoEjecucion): void
    {
        // Check if estado changed to 'completed'
        if ($flujoEjecucion->wasChanged('estado') && $flujoEjecucion->estado === 'completed') {
            try {
                // Only calculate if real cost hasn't been set yet
                if ($flujoEjecucion->costo_real === null) {
                    Log::info('FlujoEjecucionObserver: Calculando costo real para ejecución completada', [
                        'ejecucion_id' => $flujoEjecucion->id,
                    ]);

                    // Update without triggering another observer event
                    FlujoEjecucion::withoutEvents(function () use ($flujoEjecucion) {
                        $this->costoService->actualizarCostosEjecucion($flujoEjecucion);
                    });

                    Log::info('FlujoEjecucionObserver: Costo real guardado', [
                        'ejecucion_id' => $flujoEjecucion->id,
                        'costo_real' => $flujoEjecucion->fresh()->costo_real,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('FlujoEjecucionObserver: Error calculando costo real', [
                    'ejecucion_id' => $flujoEjecucion->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
