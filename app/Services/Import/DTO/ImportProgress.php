<?php

declare(strict_types=1);

namespace App\Services\Import\DTO;

/**
 * DTO para el progreso de una importación.
 * Permite resumir desde donde se quedó.
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

    /**
     * Crea un ImportProgress desde los metadata de una importación.
     */
    public static function fromMetadata(?array $metadata): self
    {
        if ($metadata === null) {
            return new self();
        }

        return new self(
            lastProcessedRow: $metadata['last_processed_row'] ?? 0,
            registrosExitosos: $metadata['checkpoint_exitosos'] ?? 0,
            registrosFallidos: $metadata['checkpoint_fallidos'] ?? 0,
            sinEmail: $metadata['checkpoint_sin_email'] ?? 0,
            sinTelefono: $metadata['checkpoint_sin_telefono'] ?? 0,
            errores: $metadata['errores'] ?? [],
        );
    }

    /**
     * Convierte a array para guardar en metadata.
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

    public function shouldSkipRow(int $rowIndex): bool
    {
        return $rowIndex <= $this->lastProcessedRow;
    }
}
