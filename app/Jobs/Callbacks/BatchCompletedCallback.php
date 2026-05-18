<?php

namespace App\Jobs\Callbacks;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoJob;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Log;

/**
 * Callback para cuando un batch de envíos se completa exitosamente.
 * Usa clase invocable en lugar de closure para evitar problemas de serialización en Laravel 12.
 */
class BatchCompletedCallback
{
    public function __construct(
        public array $callbackData
    ) {}

    public function __invoke(Batch $batch): void
    {
        Log::info('BatchCompletedCallback: Batch completado', [
            'batch_id' => $batch->id,
            'processed' => $batch->processedJobs(),
            'failed' => $batch->failedJobs,
            'etapa_ejecucion_id' => $this->callbackData['etapa_ejecucion_id'],
        ]);

        $etapaEjecucion = FlujoEjecucionEtapa::find($this->callbackData['etapa_ejecucion_id']);
        $ejecucion = FlujoEjecucion::find($this->callbackData['flujo_ejecucion_id']);

        if (! $etapaEjecucion || ! $ejecucion) {
            Log::error('BatchCompletedCallback: No se encontraron modelos', $this->callbackData);

            return;
        }

        $messageId = rand(10000, 99999);

        $etapaEjecucion->update([
            'message_id' => $messageId,
            'estado' => 'completed',
            'ejecutado' => true,
            'fecha_ejecucion' => now(),
            'response_athenacampaign' => [
                'batch_id' => $batch->id,
                'messageID' => $messageId,
                'Recipients' => $batch->processedJobs() - $batch->failedJobs,
                'Errores' => $batch->failedJobs,
                'total_jobs' => $this->callbackData['total_jobs'],
            ],
        ]);

        FlujoJob::where('job_id', $batch->id)
            ->where('job_type', 'enviar_etapa_batch')
            ->update([
                'estado' => 'completed',
                'fecha_procesado' => now(),
            ]);

        // Programar siguiente paso
        $this->procesarSiguientePaso($ejecucion, $etapaEjecucion, $messageId);
    }

    private function procesarSiguientePaso(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion, int $messageId): void
    {
        $stage = $this->callbackData['stage'];
        $branches = $this->callbackData['branches'];
        $prospectoIds = $this->callbackData['prospecto_ids'];

        $conexionesDesdeEsta = collect($branches)->filter(function ($branch) use ($stage) {
            return $branch['source_node_id'] === $stage['id'];
        });

        if ($conexionesDesdeEsta->isEmpty()) {
            $this->finalizarFlujo($ejecucion);

            return;
        }

        $primeraConexion = $conexionesDesdeEsta->first();
        $targetNodeId = $primeraConexion['target_node_id'];

        if (str_starts_with($targetNodeId, 'end-')) {
            $this->finalizarFlujo($ejecucion);

            return;
        }

        $targetNode = $this->findTargetNode($ejecucion, $targetNodeId);
        if (! $targetNode) {
            return;
        }

        $this->procesarNodoSiguiente($ejecucion, $etapaEjecucion, $targetNode, $targetNodeId, $primeraConexion, $messageId, $prospectoIds, $branches);
    }

    private function finalizarFlujo(FlujoEjecucion $ejecucion): void
    {
        // En flujos perpetuos, "terminar" un ciclo NO completa la ejecución:
        // queda en estado 'waiting' esperando nuevos prospectos que CatchUp
        // detecta y procesa. Marcar como 'completed' deja la ejecución muerta
        // y a los nuevos prospectos sin email.
        $esPerpetuo = $ejecucion->flujo?->es_perpetuo ?? false;

        if ($esPerpetuo) {
            Log::info('BatchCompletedCallback: Ciclo perpetuo terminado, ejecucion en waiting', [
                'ejecucion_id' => $ejecucion->id,
                'flujo_id' => $ejecucion->flujo_id,
            ]);
            $ejecucion->update([
                'estado' => 'waiting',
                'proximo_nodo' => null,
                'fecha_proximo_nodo' => null,
            ]);

            return;
        }

        Log::info('BatchCompletedCallback: Finalizando flujo no perpetuo', [
            'ejecucion_id' => $ejecucion->id,
        ]);
        $ejecucion->update([
            'estado' => 'completed',
            'fecha_fin' => now(),
        ]);
    }

    private function findTargetNode(FlujoEjecucion $ejecucion, string $targetNodeId): ?array
    {
        $flujoData = $ejecucion->flujo->flujo_data;
        $stages = $flujoData['stages'] ?? [];
        $conditions = $flujoData['conditions'] ?? [];

        $targetNode = collect($stages)->firstWhere('id', $targetNodeId);

        if (! $targetNode) {
            $targetNode = collect($conditions)->firstWhere('id', $targetNodeId);
        }

        return $targetNode;
    }

    private function procesarNodoSiguiente(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        array $targetNode,
        string $targetNodeId,
        array $primeraConexion,
        int $messageId,
        array $prospectoIds,
        array $branches
    ): void {
        $tipoNodoSiguiente = $targetNode['type'] ?? 'stage';

        match ($tipoNodoSiguiente) {
            'condition' => $this->programarVerificacionCondicion($ejecucion, $etapaEjecucion, $targetNodeId, $primeraConexion, $messageId, $prospectoIds),
            'stage' => $this->programarSiguienteEtapa($ejecucion, $targetNode, $targetNodeId, $prospectoIds),
            'end' => $this->finalizarFlujo($ejecucion),
            default => Log::warning('BatchCompletedCallback: Tipo de nodo desconocido', ['tipo' => $tipoNodoSiguiente]),
        };
    }

    private function programarVerificacionCondicion(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        string $targetNodeId,
        array $conexion,
        int $messageId,
        array $prospectoIds
    ): void {
        $stage = $this->callbackData['stage'];
        $tiempoVerificacion = $stage['tiempo_verificacion_condicion'] ?? 24;
        $fechaVerificacion = now()->addHours($tiempoVerificacion);

        $condicionEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->callbackData['flujo_ejecucion_id'])
            ->where('node_id', $targetNodeId)
            ->first();

        if (! $condicionEtapa) {
            // Use firstOrCreate to avoid race conditions
            $condicionEtapa = FlujoEjecucionEtapa::firstOrCreate(
                [
                    'flujo_ejecucion_id' => $this->callbackData['flujo_ejecucion_id'],
                    'node_id' => $targetNodeId,
                ],
                [
                    'etapa_id' => null,
                    'prospectos_ids' => $prospectoIds,
                    'prospectos_count' => count($prospectoIds),
                    'fecha_programada' => $fechaVerificacion,
                    'estado' => 'pending',
                    'response_athenacampaign' => [
                        'pending_condition' => true,
                        'source_message_id' => $messageId,
                        'source_etapa_id' => $etapaEjecucion->id,
                        'conexion' => $conexion,
                    ],
                ]
            );

            if (! $condicionEtapa->wasRecentlyCreated) {
                $condicionEtapa->prospectos()->syncWithoutDetaching($prospectoIds);
                $mergedCount = $condicionEtapa->prospectos()->count();
                $condicionEtapa->update([
                    'prospectos_ids' => $condicionEtapa->prospectos()->pluck('prospectos.id')->toArray(),
                    'prospectos_count' => $mergedCount,
                ]);
            }
        } else {
            $condicionEtapa->prospectos()->syncWithoutDetaching($prospectoIds);
            $mergedProspectos = $condicionEtapa->prospectos()->pluck('prospectos.id')->toArray();

            Log::info("BatchCompletedCallback: Merging prospects for condition node {$targetNodeId}", [
                'new_count' => count($prospectoIds),
                'merged_count' => count($mergedProspectos),
            ]);

            $condicionEtapa->update([
                'prospectos_ids' => $mergedProspectos,
                'prospectos_count' => count($mergedProspectos),
                'fecha_programada' => $fechaVerificacion,
                'response_athenacampaign' => [
                    'pending_condition' => true,
                    'source_message_id' => $messageId,
                    'source_etapa_id' => $etapaEjecucion->id,
                    'conexion' => $conexion,
                ],
            ]);
        }

        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaVerificacion,
        ]);
    }

    private function programarSiguienteEtapa(FlujoEjecucion $ejecucion, array $targetNode, string $targetNodeId, array $prospectoIds): void
    {
        $siguienteEtapaEjecucion = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->callbackData['flujo_ejecucion_id'])
            ->where('node_id', $targetNodeId)
            ->first();

        // ✅ FIX: Usar fecha_programada existente si la etapa ya fue creada al inicio del flujo
        // Solo calcular desde now() si la etapa no existe (caso edge de flujos dinámicos)
        if ($siguienteEtapaEjecucion && $siguienteEtapaEjecucion->fecha_programada) {
            $fechaProgramada = $siguienteEtapaEjecucion->fecha_programada;
            Log::info('BatchCompletedCallback: Usando fecha_programada existente', [
                'node_id' => $targetNodeId,
                'fecha_programada' => $fechaProgramada,
            ]);
        } else {
            $tiempoEspera = $targetNode['tiempo_espera'] ?? 0;
            $fechaProgramada = now()->addDays($tiempoEspera);
            Log::info('BatchCompletedCallback: Calculando nueva fecha_programada', [
                'node_id' => $targetNodeId,
                'tiempo_espera' => $tiempoEspera,
                'fecha_programada' => $fechaProgramada,
            ]);
        }

        if (! $siguienteEtapaEjecucion) {
            // Use firstOrCreate to avoid race conditions
            $siguienteEtapaEjecucion = FlujoEjecucionEtapa::firstOrCreate(
                [
                    'flujo_ejecucion_id' => $this->callbackData['flujo_ejecucion_id'],
                    'node_id' => $targetNodeId,
                ],
                [
                    'etapa_id' => null,
                    'prospectos_ids' => $prospectoIds,
                    'prospectos_count' => count($prospectoIds),
                    'fecha_programada' => $fechaProgramada,
                    'estado' => 'pending',
                ]
            );

            if (! $siguienteEtapaEjecucion->wasRecentlyCreated) {
                $siguienteEtapaEjecucion->prospectos()->syncWithoutDetaching($prospectoIds);
                $mergedCount = $siguienteEtapaEjecucion->prospectos()->count();
                $siguienteEtapaEjecucion->update([
                    'prospectos_ids' => $siguienteEtapaEjecucion->prospectos()->pluck('prospectos.id')->toArray(),
                    'prospectos_count' => $mergedCount,
                ]);
            }
        } else {
            $siguienteEtapaEjecucion->prospectos()->syncWithoutDetaching($prospectoIds);
            $mergedProspectos = $siguienteEtapaEjecucion->prospectos()->pluck('prospectos.id')->toArray();

            Log::info("BatchCompletedCallback: Merging prospects for stage node {$targetNodeId}", [
                'new_count' => count($prospectoIds),
                'merged_count' => count($mergedProspectos),
            ]);

            $siguienteEtapaEjecucion->update([
                'prospectos_ids' => $mergedProspectos,
                'prospectos_count' => count($mergedProspectos),
            ]);
        }

        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaProgramada,
        ]);
    }
}
