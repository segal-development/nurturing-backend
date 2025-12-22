<?php

declare(strict_types=1);

namespace App\Contracts\Batching;

/**
 * Interface para estrategias de batching de envíos.
 *
 * Permite implementar diferentes estrategias de división de envíos
 * (por cantidad fija, por tiempo, adaptativa, etc.)
 *
 * Principio SOLID: Interface Segregation - interfaz pequeña y específica.
 */
interface BatchingStrategyInterface
{
    /**
     * Determina si se debe aplicar batching a un conjunto de envíos.
     *
     * @param int $totalCount Cantidad total de envíos
     */
    public function shouldBatch(int $totalCount): bool;

    /**
     * Divide un array de IDs en lotes según la estrategia.
     *
     * @param array<int> $ids Array de IDs a dividir
     * @return array<int, array<int>> Array de lotes, cada uno con sus IDs
     */
    public function createBatches(array $ids): array;

    /**
     * Obtiene el delay en minutos para un lote específico.
     *
     * @param int $batchIndex Índice del lote (0-based)
     * @param int $totalBatches Total de lotes
     */
    public function getDelayForBatch(int $batchIndex, int $totalBatches): int;

    /**
     * Obtiene información de configuración de la estrategia.
     *
     * @return array{threshold: int, batch_count: int, delay_minutes: int}
     */
    public function getConfig(): array;
}
