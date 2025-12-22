<?php

declare(strict_types=1);

namespace App\Services\Batching;

use App\Contracts\Batching\BatchingStrategyInterface;
use App\Jobs\EnviarLoteJob;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para orquestar el envío de mensajes en lotes.
 *
 * Decide si aplicar batching y coordina el dispatch de los jobs.
 *
 * Principio SOLID: Single Responsibility - solo orquesta, no envía.
 * Principio SOLID: Dependency Inversion - depende de abstracción (interface).
 */
final class EnvioBatchService
{
    public function __construct(
        private readonly BatchingStrategyInterface $batchingStrategy
    ) {}

    /**
     * Determina si se debe usar batching para la cantidad dada.
     */
    public function shouldUseBatching(int $totalProspectos): bool
    {
        return $this->batchingStrategy->shouldBatch($totalProspectos);
    }

    /**
     * Prepara y encola los lotes de envío.
     *
     * @param int $flujoEjecucionId ID de la ejecución del flujo
     * @param int $etapaEjecucionId ID de la etapa de ejecución
     * @param array<int> $prospectoIds IDs de prospectos a enviar
     * @param array $stage Datos del nodo/etapa
     * @param array $branches Conexiones del flujo
     * @return BatchResult Resultado con información de los lotes creados
     */
    public function dispatchBatches(
        int $flujoEjecucionId,
        int $etapaEjecucionId,
        array $prospectoIds,
        array $stage,
        array $branches
    ): BatchResult {
        $totalProspectos = count($prospectoIds);

        // Early return: si no hay prospectos
        if ($totalProspectos === 0) {
            return BatchResult::empty();
        }

        // Early return: si no requiere batching, indicar envío directo
        if (! $this->shouldUseBatching($totalProspectos)) {
            Log::info('EnvioBatchService: No requiere batching, envío directo', [
                'total_prospectos' => $totalProspectos,
                'threshold' => $this->batchingStrategy->getConfig()['threshold'],
            ]);

            return BatchResult::direct($prospectoIds);
        }

        // Crear lotes
        $batches = $this->batchingStrategy->createBatches($prospectoIds);
        $totalBatches = count($batches);

        Log::info('EnvioBatchService: Iniciando batching', [
            'total_prospectos' => $totalProspectos,
            'total_lotes' => $totalBatches,
            'config' => $this->batchingStrategy->getConfig(),
        ]);

        // Actualizar etapa con información de batching
        $this->updateEtapaWithBatchInfo($etapaEjecucionId, $totalBatches, $totalProspectos);

        // Dispatch de cada lote
        $dispatchedBatches = $this->dispatchAllBatches(
            $batches,
            $flujoEjecucionId,
            $etapaEjecucionId,
            $stage,
            $branches,
            $totalBatches
        );

        return BatchResult::batched($dispatchedBatches, $totalBatches, $totalProspectos);
    }

    /**
     * Dispatch de todos los lotes con sus delays correspondientes.
     *
     * @return array<int, array{batch_number: int, size: int, delay_minutes: int}>
     */
    private function dispatchAllBatches(
        array $batches,
        int $flujoEjecucionId,
        int $etapaEjecucionId,
        array $stage,
        array $branches,
        int $totalBatches
    ): array {
        $dispatchedBatches = [];

        foreach ($batches as $index => $batchIds) {
            $batchNumber = $index + 1;
            $delayMinutes = $this->batchingStrategy->getDelayForBatch($index, $totalBatches);
            $isLastBatch = $batchNumber === $totalBatches;

            $this->dispatchSingleBatch(
                $flujoEjecucionId,
                $etapaEjecucionId,
                $batchIds,
                $stage,
                $branches,
                $batchNumber,
                $totalBatches,
                $delayMinutes,
                $isLastBatch
            );

            $dispatchedBatches[] = [
                'batch_number' => $batchNumber,
                'size' => count($batchIds),
                'delay_minutes' => $delayMinutes,
                'is_last' => $isLastBatch,
            ];

            Log::debug('EnvioBatchService: Lote encolado', [
                'lote' => $batchNumber,
                'de' => $totalBatches,
                'prospectos' => count($batchIds),
                'delay_minutos' => $delayMinutes,
                'es_ultimo' => $isLastBatch,
            ]);
        }

        return $dispatchedBatches;
    }

    /**
     * Dispatch de un lote individual.
     */
    private function dispatchSingleBatch(
        int $flujoEjecucionId,
        int $etapaEjecucionId,
        array $prospectoIds,
        array $stage,
        array $branches,
        int $batchNumber,
        int $totalBatches,
        int $delayMinutes,
        bool $isLastBatch
    ): void {
        $job = new EnviarLoteJob(
            flujoEjecucionId: $flujoEjecucionId,
            etapaEjecucionId: $etapaEjecucionId,
            prospectoIds: $prospectoIds,
            stage: $stage,
            branches: $branches,
            batchNumber: $batchNumber,
            totalBatches: $totalBatches,
            isLastBatch: $isLastBatch
        );

        // Aplicar delay si corresponde
        if ($delayMinutes > 0) {
            $job->delay(now()->addMinutes($delayMinutes));
        }

        // Usar cola específica para batching
        $queue = config('batching.queue', 'envios-batch');
        dispatch($job->onQueue($queue));
    }

    /**
     * Actualiza la etapa con información de batching.
     */
    private function updateEtapaWithBatchInfo(
        int $etapaEjecucionId,
        int $totalBatches,
        int $totalProspectos
    ): void {
        $etapa = FlujoEjecucionEtapa::find($etapaEjecucionId);

        if (! $etapa) {
            return;
        }

        // Guardar info de batching en response_athenacampaign (campo JSON existente)
        $currentResponse = $etapa->response_athenacampaign ?? [];
        $currentResponse['batching'] = [
            'enabled' => true,
            'total_batches' => $totalBatches,
            'total_prospectos' => $totalProspectos,
            'batches_completed' => 0,
            'started_at' => now()->toIso8601String(),
        ];

        $etapa->update([
            'estado' => 'batching',
            'response_athenacampaign' => $currentResponse,
        ]);
    }

    /**
     * Obtiene la configuración actual de batching.
     *
     * @return array{threshold: int, batch_count: int, delay_minutes: int}
     */
    public function getConfig(): array
    {
        return $this->batchingStrategy->getConfig();
    }
}
