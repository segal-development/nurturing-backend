<?php

declare(strict_types=1);

namespace App\Services\Batching;

/**
 * Data Transfer Object para el resultado de la operación de batching.
 *
 * Inmutable y auto-documentado.
 */
final readonly class BatchResult
{
    /**
     * @param bool $requiresBatching Si se aplicó batching
     * @param array<int, array{batch_number: int, size: int, delay_minutes: int, is_last: bool}> $batches Info de cada lote
     * @param int $totalBatches Total de lotes creados
     * @param int $totalProspectos Total de prospectos procesados
     * @param array<int> $directProspectoIds IDs para envío directo (sin batching)
     */
    private function __construct(
        public bool $requiresBatching,
        public array $batches,
        public int $totalBatches,
        public int $totalProspectos,
        public array $directProspectoIds
    ) {}

    /**
     * Crea resultado para envío directo (sin batching).
     *
     * @param array<int> $prospectoIds
     */
    public static function direct(array $prospectoIds): self
    {
        return new self(
            requiresBatching: false,
            batches: [],
            totalBatches: 0,
            totalProspectos: count($prospectoIds),
            directProspectoIds: $prospectoIds
        );
    }

    /**
     * Crea resultado para envío en lotes.
     *
     * @param array<int, array{batch_number: int, size: int, delay_minutes: int, is_last: bool}> $batches
     */
    public static function batched(array $batches, int $totalBatches, int $totalProspectos): self
    {
        return new self(
            requiresBatching: true,
            batches: $batches,
            totalBatches: $totalBatches,
            totalProspectos: $totalProspectos,
            directProspectoIds: []
        );
    }

    /**
     * Crea resultado vacío (sin prospectos).
     */
    public static function empty(): self
    {
        return new self(
            requiresBatching: false,
            batches: [],
            totalBatches: 0,
            totalProspectos: 0,
            directProspectoIds: []
        );
    }

    /**
     * Verifica si hay prospectos para procesar.
     */
    public function hasProspectos(): bool
    {
        return $this->totalProspectos > 0;
    }

    /**
     * Verifica si es envío directo (sin batching).
     */
    public function isDirectSend(): bool
    {
        return ! $this->requiresBatching && $this->hasProspectos();
    }

    /**
     * Obtiene tiempo estimado de finalización en minutos.
     */
    public function getEstimatedCompletionMinutes(): int
    {
        if (! $this->requiresBatching) {
            return 0;
        }

        $lastBatch = end($this->batches);

        return $lastBatch ? $lastBatch['delay_minutes'] : 0;
    }

    /**
     * Convierte a array para logging o respuestas.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requires_batching' => $this->requiresBatching,
            'total_batches' => $this->totalBatches,
            'total_prospectos' => $this->totalProspectos,
            'estimated_completion_minutes' => $this->getEstimatedCompletionMinutes(),
            'batches' => $this->batches,
        ];
    }
}
