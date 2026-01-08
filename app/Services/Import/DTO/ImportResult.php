<?php

declare(strict_types=1);

namespace App\Services\Import\DTO;

/**
 * DTO que encapsula el resultado final de una importación.
 * 
 * Inmutable y completo para evitar pasar múltiples parámetros.
 */
final readonly class ImportResult
{
    public function __construct(
        public int $totalRows,
        public int $registrosExitosos,
        public int $registrosFallidos,
        public int $sinEmail,
        public int $sinTelefono,
        public int $creados,
        public int $actualizados,
        public array $errores,
        public float $tiempoSegundos,
        public float $memoriaPeakMb,
    ) {}

    // =========================================================================
    // FACTORY METHODS
    // =========================================================================

    /**
     * Crea un resultado desde los contadores del servicio de importación.
     */
    public static function create(
        int $totalRows,
        int $exitosos,
        int $fallidos,
        int $sinEmail,
        int $sinTelefono,
        int $creados,
        int $actualizados,
        array $errores,
        float $tiempoSegundos,
    ): self {
        return new self(
            totalRows: $totalRows,
            registrosExitosos: $exitosos,
            registrosFallidos: $fallidos,
            sinEmail: $sinEmail,
            sinTelefono: $sinTelefono,
            creados: $creados,
            actualizados: $actualizados,
            errores: $errores,
            tiempoSegundos: $tiempoSegundos,
            memoriaPeakMb: round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        );
    }

    // =========================================================================
    // QUERIES
    // =========================================================================

    /**
     * Indica si la importación fue exitosa (al menos algunos registros).
     */
    public function isSuccessful(): bool
    {
        return $this->registrosExitosos > 0;
    }

    /**
     * Indica si la importación falló completamente.
     */
    public function isFailed(): bool
    {
        return $this->registrosExitosos === 0 && $this->registrosFallidos > 0;
    }

    /**
     * Indica si la importación está vacía.
     */
    public function isEmpty(): bool
    {
        return $this->totalRows === 0;
    }

    /**
     * Calcula el porcentaje de éxito.
     */
    public function getSuccessRate(): float
    {
        if ($this->totalRows === 0) {
            return 0.0;
        }

        return round(($this->registrosExitosos / $this->totalRows) * 100, 2);
    }

    /**
     * Calcula la velocidad de procesamiento (registros por segundo).
     */
    public function getProcessingSpeed(): float
    {
        if ($this->tiempoSegundos === 0.0) {
            return 0.0;
        }

        return round($this->totalRows / $this->tiempoSegundos, 1);
    }

    /**
     * Determina el estado final de la importación.
     */
    public function getEstadoFinal(): string
    {
        if ($this->isFailed()) {
            return 'fallido';
        }

        return 'completado';
    }

    // =========================================================================
    // CONVERSIÓN
    // =========================================================================

    /**
     * Convierte a array para logging.
     */
    public function toLogArray(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'exitosos' => $this->registrosExitosos,
            'fallidos' => $this->registrosFallidos,
            'sin_email' => $this->sinEmail,
            'sin_telefono' => $this->sinTelefono,
            'creados' => $this->creados,
            'actualizados' => $this->actualizados,
            'errores_count' => count($this->errores),
            'tiempo_segundos' => $this->tiempoSegundos,
            'memoria_peak_mb' => $this->memoriaPeakMb,
            'success_rate' => $this->getSuccessRate(),
            'speed_rps' => $this->getProcessingSpeed(),
        ];
    }

    /**
     * Convierte a array para metadata de importación.
     */
    public function toMetadata(): array
    {
        return [
            'errores' => $this->errores,
            'registros_sin_email' => $this->sinEmail,
            'registros_sin_telefono' => $this->sinTelefono,
            'creados' => $this->creados,
            'actualizados' => $this->actualizados,
            'tiempo_procesamiento_segundos' => $this->tiempoSegundos,
            'memoria_peak_mb' => $this->memoriaPeakMb,
            'completado_en' => now()->toISOString(),
        ];
    }
}
