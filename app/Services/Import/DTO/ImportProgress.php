<?php

declare(strict_types=1);

namespace App\Services\Import\DTO;

/**
 * DTO para el progreso de una importación.
 * 
 * Permite resumir desde donde se quedó si el proceso se interrumpe.
 * Almacena checkpoints de filas procesadas y contadores.
 */
final class ImportProgress
{
    public function __construct(
        public int $lastProcessedRow = 0,
        public int $registrosExitosos = 0,
        public int $registrosFallidos = 0,
        public int $sinEmail = 0,
        public int $sinTelefono = 0,
        public array $errores = [],
    ) {}

    // =========================================================================
    // FACTORY METHODS
    // =========================================================================

    /**
     * Crea un ImportProgress desde los metadata de una importación.
     */
    public static function fromMetadata(?array $metadata): self
    {
        if ($metadata === null) {
            return new self();
        }

        return new self(
            lastProcessedRow: (int) ($metadata['last_processed_row'] ?? 0),
            registrosExitosos: (int) ($metadata['checkpoint_exitosos'] ?? 0),
            registrosFallidos: (int) ($metadata['checkpoint_fallidos'] ?? 0),
            sinEmail: (int) ($metadata['checkpoint_sin_email'] ?? 0),
            sinTelefono: (int) ($metadata['checkpoint_sin_telefono'] ?? 0),
            errores: $metadata['errores'] ?? [],
        );
    }

    /**
     * Crea una instancia vacía para empezar de cero.
     */
    public static function fresh(): self
    {
        return new self();
    }

    // =========================================================================
    // CONVERSIÓN
    // =========================================================================

    /**
     * Convierte a array para guardar en metadata de la importación.
     */
    public function toMetadata(): array
    {
        return [
            'last_processed_row' => $this->lastProcessedRow,
            'checkpoint_exitosos' => $this->registrosExitosos,
            'checkpoint_fallidos' => $this->registrosFallidos,
            'checkpoint_sin_email' => $this->sinEmail,
            'checkpoint_sin_telefono' => $this->sinTelefono,
        ];
    }

    // =========================================================================
    // QUERIES
    // =========================================================================

    /**
     * Verifica si una fila debe saltarse (ya fue procesada).
     */
    public function shouldSkipRow(int $rowIndex): bool
    {
        return $rowIndex <= $this->lastProcessedRow;
    }

    /**
     * Verifica si hay progreso previo (es un resume).
     */
    public function hasProgress(): bool
    {
        return $this->lastProcessedRow > 0;
    }

    /**
     * Obtiene el total de registros procesados.
     */
    public function getTotalProcesados(): int
    {
        return $this->registrosExitosos + $this->registrosFallidos;
    }

    /**
     * Calcula el porcentaje de éxito.
     */
    public function getSuccessRate(): float
    {
        $total = $this->getTotalProcesados();
        
        if ($total === 0) {
            return 0.0;
        }

        return round(($this->registrosExitosos / $total) * 100, 2);
    }

    // =========================================================================
    // BUILDERS (para crear nuevas instancias con valores actualizados)
    // =========================================================================

    /**
     * Crea una nueva instancia con los contadores actualizados.
     */
    public function withUpdatedCounters(
        int $currentRow,
        int $exitosos,
        int $fallidos,
        int $sinEmail,
        int $sinTelefono,
    ): self {
        return new self(
            lastProcessedRow: $currentRow,
            registrosExitosos: $exitosos,
            registrosFallidos: $fallidos,
            sinEmail: $sinEmail,
            sinTelefono: $sinTelefono,
            errores: $this->errores,
        );
    }

    /**
     * Crea una nueva instancia agregando un error.
     */
    public function withError(int $fila, array $errores): self
    {
        $newErrores = $this->errores;
        $newErrores[] = [
            'fila' => $fila,
            'errores' => $errores,
        ];

        return new self(
            lastProcessedRow: $this->lastProcessedRow,
            registrosExitosos: $this->registrosExitosos,
            registrosFallidos: $this->registrosFallidos,
            sinEmail: $this->sinEmail,
            sinTelefono: $this->sinTelefono,
            errores: $newErrores,
        );
    }
}
