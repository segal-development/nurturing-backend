<?php

namespace App\Observers;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Support\Facades\Log;

/**
 * Observer para FlujoEjecucionEtapa.
 *
 * CRITICAL: Garantiza que cuando una etapa se completa (de cualquier forma),
 * el siguiente nodo del flujo SIEMPRE se programe correctamente.
 *
 * Esto resuelve el problema donde etapas marcadas manualmente como 'completed'
 * (vía tinker, recovery commands, etc.) dejaban el flujo "colgado" sin programar
 * el siguiente nodo.
 */
class FlujoEjecucionEtapaObserver
{
    /**
     * Handle the FlujoEjecucionEtapa "updated" event.
     *
     * Cuando una etapa cambia a 'completed', verificar si el siguiente nodo
     * ya está programado. Si no, programarlo.
     */
    public function updated(FlujoEjecucionEtapa $etapa): void
    {
        // Solo actuar cuando el estado cambió a 'completed'
        if (! $etapa->wasChanged('estado') || $etapa->estado !== 'completed') {
            return;
        }

        $this->verificarYProgramarSiguienteNodo($etapa);
    }

    /**
     * Verifica si el siguiente nodo está programado y lo programa si no lo está.
     */
    private function verificarYProgramarSiguienteNodo(FlujoEjecucionEtapa $etapa): void
    {
        try {
            $ejecucion = $etapa->ejecucion;
            if (! $ejecucion) {
                Log::warning('FlujoEjecucionEtapaObserver: No se encontró ejecución para etapa', [
                    'etapa_id' => $etapa->id,
                ]);

                return;
            }

            // Obtener datos del flujo
            $flujoData = $ejecucion->flujo->flujo_data ?? [];
            $branches = $this->normalizeBranches($flujoData);
            $stages = $flujoData['stages'] ?? [];
            $conditions = $flujoData['conditions'] ?? [];

            // Buscar la conexión desde este nodo
            $siguienteConexion = collect($branches)->firstWhere('source_node_id', $etapa->node_id);

            if (! $siguienteConexion) {
                // No hay siguiente nodo - verificar si hay que completar la ejecución
                $this->verificarCompletarEjecucion($ejecucion, $etapa);

                return;
            }

            $siguienteNodoId = $siguienteConexion['target_node_id'];

            // Si es nodo final, completar ejecución
            if (str_starts_with($siguienteNodoId, 'end-')) {
                $this->verificarCompletarEjecucion($ejecucion, $etapa, $siguienteNodoId);

                return;
            }

            // Verificar si ya existe una etapa para el siguiente nodo
            $siguienteEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
                ->where('node_id', $siguienteNodoId)
                ->first();

            // Si ya existe y está pending con prospectos, ya está programada
            if ($siguienteEtapa && $siguienteEtapa->estado === 'pending') {
                // Verificar que tiene prospectos y que la ejecución apunta al siguiente nodo
                if ($ejecucion->proximo_nodo === $siguienteNodoId) {
                    Log::debug('FlujoEjecucionEtapaObserver: Siguiente nodo ya programado correctamente', [
                        'etapa_completada_id' => $etapa->id,
                        'siguiente_etapa_id' => $siguienteEtapa->id,
                        'siguiente_nodo' => $siguienteNodoId,
                    ]);

                    return;
                }
            }

            // Si llegamos aquí, necesitamos programar el siguiente nodo
            Log::info('FlujoEjecucionEtapaObserver: Programando siguiente nodo faltante', [
                'etapa_completada_id' => $etapa->id,
                'etapa_node_id' => $etapa->node_id,
                'siguiente_nodo_id' => $siguienteNodoId,
            ]);

            $this->programarSiguienteNodo($ejecucion, $etapa, $siguienteNodoId, $stages, $conditions, $branches);

        } catch (\Exception $e) {
            Log::error('FlujoEjecucionEtapaObserver: Error programando siguiente nodo', [
                'etapa_id' => $etapa->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Normaliza edges a branches para compatibilidad.
     */
    private function normalizeBranches(array $flujoData): array
    {
        $branches = $flujoData['branches'] ?? $flujoData['edges'] ?? [];

        // Si son edges, convertir al formato de branches
        if (! empty($flujoData['edges']) && empty($flujoData['branches'])) {
            $branches = collect($flujoData['edges'])->map(function ($edge) {
                return [
                    'source_node_id' => $edge['source'] ?? null,
                    'target_node_id' => $edge['target'] ?? null,
                    'source_handle' => $edge['sourceHandle'] ?? null,
                ];
            })->toArray();
        }

        return $branches;
    }

    /**
     * Verifica si la ejecución debe completarse después de esta etapa.
     *
     * Para flujos NO perpetuos: cuando ya no hay etapas pending/executing, marca la
     * ejecución como `completed` (el flujo terminó).
     *
     * Para flujos PERPETUOS: NUNCA marca la ejecución como `completed` automáticamente,
     * porque la ejecución sigue viva esperando nuevos prospectos. La transicion correcta
     * es a `waiting` y la maneja `BatchCompletedCallback::finalizarFlujo`.
     */
    private function verificarCompletarEjecucion(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapa, ?string $nodoFinal = null): void
    {
        // En flujos perpetuos, no se completan automáticamente: BatchCompletedCallback
        // las pone en `waiting` y CatchUp las re-activa con cada nuevo prospecto.
        $esPerpetuo = $ejecucion->es_perpetuo || ($ejecucion->flujo?->es_perpetuo ?? false);
        if ($esPerpetuo) {
            return;
        }

        // Solo completar si todas las etapas activas están completadas o failed
        $etapasActivas = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->whereIn('estado', ['pending', 'executing'])
            ->count();

        if ($etapasActivas === 0) {
            // Si la ejecución no está ya completada, actualizarla
            if ($ejecucion->estado !== 'completed') {
                FlujoEjecucion::withoutEvents(function () use ($ejecucion) {
                    $ejecucion->update([
                        'estado' => 'completed',
                        'fecha_fin' => now(),
                        'proximo_nodo' => null,
                        'fecha_proximo_nodo' => null,
                    ]);
                });

                Log::info('FlujoEjecucionEtapaObserver: Ejecución completada', [
                    'ejecucion_id' => $ejecucion->id,
                    'nodo_final' => $nodoFinal,
                ]);
            }
        }
    }

    /**
     * Programa el siguiente nodo del flujo.
     */
    private function programarSiguienteNodo(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaCompletada,
        string $siguienteNodoId,
        array $stages,
        array $conditions,
        array $branches
    ): void {
        // Buscar datos del siguiente nodo
        $siguienteNodo = collect($stages)->firstWhere('id', $siguienteNodoId);
        if (! $siguienteNodo) {
            $siguienteNodo = collect($conditions)->firstWhere('id', $siguienteNodoId);
        }

        $tipoNodo = $siguienteNodo['type'] ?? (str_starts_with($siguienteNodoId, 'condition') ? 'condition' : 'stage');

        // Obtener prospectos de la etapa completada
        $prospectoIds = $etapaCompletada->prospectos()->pluck('prospectos.id')->toArray();
        if (empty($prospectoIds)) {
            $prospectoIds = $ejecucion->prospectos()->pluck('prospectos.id')->toArray();
        }

        // Buscar si ya existe la etapa siguiente
        $siguienteEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $siguienteNodoId)
            ->first();

        // Calcular fecha programada
        if ($siguienteEtapa && $siguienteEtapa->fecha_programada) {
            $fechaProgramada = $siguienteEtapa->fecha_programada;
        } else {
            if ($tipoNodo === 'condition') {
                $tiempoVerificacion = 24; // horas por defecto
                $fechaProgramada = now()->addHours($tiempoVerificacion);
            } else {
                $tiempoEspera = $siguienteNodo['tiempo_espera'] ?? 0;
                $fechaProgramada = now()->addDays($tiempoEspera);
            }
        }

        // Crear o actualizar la etapa siguiente
        if ($siguienteEtapa) {
            // MERGE prospectos en lugar de sobrescribir
            $siguienteEtapa->prospectos()->syncWithoutDetaching($prospectoIds);
            $mergedProspectos = $siguienteEtapa->prospectos()->pluck('prospectos.id')->toArray();

            FlujoEjecucionEtapa::withoutEvents(function () use ($siguienteEtapa, $mergedProspectos, $tipoNodo, $etapaCompletada) {
                $updateData = [
                    'prospectos_ids' => $mergedProspectos,
                    'prospectos_count' => count($mergedProspectos),
                    'estado' => 'pending',
                ];

                if ($tipoNodo === 'condition') {
                    $updateData['response_athenacampaign'] = [
                        'pending_condition' => true,
                        'source_message_id' => $etapaCompletada->message_id,
                        'source_etapa_id' => $etapaCompletada->id,
                        'programmed_by_observer' => true,
                    ];
                }

                $siguienteEtapa->update($updateData);
            });

            Log::info('FlujoEjecucionEtapaObserver: Etapa siguiente actualizada', [
                'etapa_id' => $siguienteEtapa->id,
                'node_id' => $siguienteNodoId,
                'prospectos_count' => count($mergedProspectos),
            ]);
        } else {
            // Crear etapa nueva
            $etapaData = [
                'flujo_ejecucion_id' => $ejecucion->id,
                'etapa_id' => null,
                'node_id' => $siguienteNodoId,
                'prospectos_ids' => $prospectoIds,
                'prospectos_count' => count($prospectoIds),
                'fecha_programada' => $fechaProgramada,
                'estado' => 'pending',
            ];

            if ($tipoNodo === 'condition') {
                $etapaData['response_athenacampaign'] = [
                    'pending_condition' => true,
                    'source_message_id' => $etapaCompletada->message_id,
                    'source_etapa_id' => $etapaCompletada->id,
                    'programmed_by_observer' => true,
                ];
            }

            $siguienteEtapa = FlujoEjecucionEtapa::create($etapaData);

            Log::info('FlujoEjecucionEtapaObserver: Etapa siguiente creada', [
                'etapa_id' => $siguienteEtapa->id,
                'node_id' => $siguienteNodoId,
                'prospectos_count' => count($prospectoIds),
            ]);
        }

        // Actualizar la ejecución para apuntar al siguiente nodo
        FlujoEjecucion::withoutEvents(function () use ($ejecucion, $etapaCompletada, $siguienteNodoId, $fechaProgramada) {
            $ejecucion->update([
                'nodo_actual' => $etapaCompletada->node_id,
                'proximo_nodo' => $siguienteNodoId,
                'fecha_proximo_nodo' => $fechaProgramada,
            ]);
        });

        Log::info('FlujoEjecucionEtapaObserver: Ejecución actualizada con siguiente nodo', [
            'ejecucion_id' => $ejecucion->id,
            'nodo_completado' => $etapaCompletada->node_id,
            'proximo_nodo' => $siguienteNodoId,
            'fecha_programada' => $fechaProgramada,
        ]);
    }
}
