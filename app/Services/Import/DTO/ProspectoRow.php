<?php

declare(strict_types=1);

namespace App\Services\Import\DTO;

/**
 * Data Transfer Object para una fila de prospecto del Excel.
 * Inmutable y tipado para seguridad en tiempo de compilación.
 */
final readonly class ProspectoRow
{
    public function __construct(
        public int $rowIndex,
        public ?string $nombre,
        public ?string $email,
        public ?string $telefono,
        public ?string $rut,
        public ?string $urlInforme,
        public int $montoDeuda,
    ) {}

    /**
     * Crea un ProspectoRow desde un array asociativo del Excel.
     */
    public static function fromArray(array $data, int $rowIndex): self
    {
        return new self(
            rowIndex: $rowIndex,
            nombre: self::cleanString($data['nombre'] ?? null),
            email: self::cleanEmail($data['email'] ?? null),
            telefono: self::cleanString($data['telefono'] ?? null),
            rut: self::cleanString($data['rut'] ?? null),
            urlInforme: self::cleanString($data['url_informe'] ?? null),
            montoDeuda: self::cleanMonto($data['monto_deuda'] ?? 0),
        );
    }

    public function hasValidContact(): bool
    {
        return $this->email !== null || $this->telefono !== null;
    }

    public function hasValidName(): bool
    {
        return $this->nombre !== null && strlen($this->nombre) > 0 && strlen($this->nombre) <= 255;
    }

    private static function cleanString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        $cleaned = trim((string) $value);
        return $cleaned === '' ? null : $cleaned;
    }

    private static function cleanEmail(?string $value): ?string
    {
        $cleaned = self::cleanString($value);
        
        if ($cleaned === null) {
            return null;
        }
        
        // Validación básica de email (más rápida que filter_var)
        if (!str_contains($cleaned, '@') || !str_contains($cleaned, '.')) {
            return null;
        }
        
        return strtolower($cleaned);
    }

    private static function cleanMonto(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        $cleaned = preg_replace('/[^0-9]/', '', (string) $value);
        return (int) ($cleaned ?: 0);
    }
}
