<?php

declare(strict_types=1);

namespace App\Services\Batching;

use App\Contracts\Batching\BatchingStrategyInterface;

/**
 * Estrategia de batching con número fijo de lotes.
 *
 * Divide los envíos en un número fijo de lotes (por defecto 24),
 * con un delay configurable entre cada lote.
 *
 * Principio SOLID: Single Responsibility - solo maneja la lógica de división.
 */
final class FixedBatchingStrategy implements BatchingStrategyInterface
{
    private readonly int $threshold;

    private readonly int $batchCount;

    private readonly int $delayMinutes;

    private readonly int $maxBatchSize;

    public function __construct()
    {
        $this->threshold = (int) config('batching.threshold', 20000);
        $this->batchCount = (int) config('batching.batch_count', 24);
        $this->delayMinutes = (int) config('batching.delay_between_batches_minutes', 10);
        $this->maxBatchSize = (int) config('batching.max_batch_size', 5000);
    }

    /**
     * {@inheritdoc}
     */
    public function shouldBatch(int $totalCount): bool
    {
        return $totalCount > $this->threshold;
    }

    /**
     * {@inheritdoc}
     *
     * Divide los IDs en lotes de tamaño uniforme.
     * Si el tamaño calculado excede max_batch_size, crea más lotes.
     */
    public function createBatches(array $ids): array
    {
        $totalCount = count($ids);

        // Early return: si no hay IDs, devolver array vacío
        if ($totalCount === 0) {
            return [];
        }

        // Early return: si no requiere batching, devolver todo en un lote
        if (! $this->shouldBatch($totalCount)) {
            return [$ids];
        }

        // Calcular tamaño de lote
        $batchSize = $this->calculateOptimalBatchSize($totalCount);

        // Dividir en chunks
        return array_chunk($ids, $batchSize);
    }

    /**
     * {@inheritdoc}
     *
     * El primer lote (index 0) se procesa inmediatamente.
     * Los siguientes tienen un delay acumulativo.
     */
    public function getDelayForBatch(int $batchIndex, int $totalBatches): int
    {
        // Early return: primer lote sin delay
        if ($batchIndex === 0) {
            return 0;
        }

        return $batchIndex * $this->delayMinutes;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        return [
            'threshold' => $this->threshold,
            'batch_count' => $this->batchCount,
            'delay_minutes' => $this->delayMinutes,
            'max_batch_size' => $this->maxBatchSize,
        ];
    }

    /**
     * Calcula el tamaño óptimo de lote respetando el máximo.
     */
    private function calculateOptimalBatchSize(int $totalCount): int
    {
        // Calcular tamaño basado en número de lotes deseado
        $calculatedSize = (int) ceil($totalCount / $this->batchCount);

        // No exceder el tamaño máximo
        return min($calculatedSize, $this->maxBatchSize);
    }
}
