<?php

declare(strict_types=1);

namespace App\Services\Import\DTO;

/**
 * Data Transfer Object para una fila de prospecto del Excel.
 * 
 * Inmutable y tipado para seguridad. Encapsula toda la lógica
 * de limpieza y validación de datos del Excel.
 */
final readonly class ProspectoRow
{
    private const MAX_NOMBRE_LENGTH = 255;
    private const MAX_RUT_LENGTH = 12;
    private const MAX_TELEFONO_LENGTH = 20;

    public function __construct(
        public int $rowIndex,
        public ?string $nombre,
        public ?string $email,
        public ?string $telefono,
        public ?string $rut,
        public ?string $urlInforme,
        public int $montoDeuda,
    ) {}

    // =========================================================================
    // FACTORY METHODS
    // =========================================================================

    /**
     * Crea un ProspectoRow desde un array asociativo del Excel.
     */
    public static function fromArray(array $data, int $rowIndex): self
    {
        return new self(
            rowIndex: $rowIndex,
            nombre: self::sanitizeNombre($data['nombre'] ?? null),
            email: self::sanitizeEmail($data['email'] ?? null),
            telefono: self::sanitizeTelefono($data['telefono'] ?? null),
            rut: self::sanitizeRut($data['rut'] ?? null),
            urlInforme: self::sanitizeString($data['url_informe'] ?? null),
            montoDeuda: self::sanitizeMonto($data['monto_deuda'] ?? 0),
        );
    }

    // =========================================================================
    // VALIDACIÓN
    // =========================================================================

    /**
     * Verifica si tiene al menos un método de contacto válido.
     */
    public function hasValidContact(): bool
    {
        return $this->hasEmail() || $this->hasTelefono();
    }

    /**
     * Verifica si el nombre es válido.
     */
    public function hasValidName(): bool
    {
        return $this->nombre !== null 
            && strlen($this->nombre) > 0 
            && strlen($this->nombre) <= self::MAX_NOMBRE_LENGTH;
    }

    /**
     * Verifica si tiene email.
     */
    public function hasEmail(): bool
    {
        return $this->email !== null;
    }

    /**
     * Verifica si tiene teléfono.
     */
    public function hasTelefono(): bool
    {
        return $this->telefono !== null;
    }

    /**
     * Retorna array de errores de validación (vacío si es válido).
     */
    public function validate(): array
    {
        $errors = [];

        if (!$this->hasValidName()) {
            $errors['nombre'] = 'El nombre es requerido y debe tener máximo 255 caracteres';
        }

        return $errors;
    }

    /**
     * Verifica si la fila es válida para importar.
     */
    public function isValid(): bool
    {
        return $this->hasValidName();
    }

    // =========================================================================
    // CONVERSIÓN
    // =========================================================================

    /**
     * Convierte a array para inserción en BD.
     */
    public function toDatabase(int $importacionId, int $tipoProspectoId): array
    {
        return [
            'nombre' => $this->nombre,
            'email' => $this->email,
            'telefono' => $this->telefono,
            'rut' => $this->rut,
            'url_informe' => $this->urlInforme,
            'monto_deuda' => $this->montoDeuda,
            'importacion_id' => $importacionId,
            'tipo_prospecto_id' => $tipoProspectoId,
            'estado' => 'nuevo',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // =========================================================================
    // SANITIZACIÓN (métodos privados)
    // =========================================================================

    private static function sanitizeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        $cleaned = trim((string) $value);
        
        return $cleaned === '' ? null : $cleaned;
    }

    private static function sanitizeNombre(mixed $value): ?string
    {
        $cleaned = self::sanitizeString($value);
        
        if ($cleaned === null) {
            return null;
        }

        // Limitar longitud
        if (strlen($cleaned) > self::MAX_NOMBRE_LENGTH) {
            $cleaned = substr($cleaned, 0, self::MAX_NOMBRE_LENGTH);
        }

        return $cleaned;
    }

    private static function sanitizeEmail(mixed $value): ?string
    {
        $cleaned = self::sanitizeString($value);
        
        if ($cleaned === null) {
            return null;
        }
        
        // Validación básica de email (más rápida que filter_var para alto volumen)
        if (!str_contains($cleaned, '@') || !str_contains($cleaned, '.')) {
            return null;
        }
        
        return strtolower($cleaned);
    }

    private static function sanitizeTelefono(mixed $value): ?string
    {
        $cleaned = self::sanitizeString($value);
        
        if ($cleaned === null) {
            return null;
        }

        // Remover caracteres no numéricos excepto + al inicio
        $cleaned = preg_replace('/[^0-9+]/', '', $cleaned);
        
        // Limitar longitud
        if (strlen($cleaned) > self::MAX_TELEFONO_LENGTH) {
            $cleaned = substr($cleaned, 0, self::MAX_TELEFONO_LENGTH);
        }

        return $cleaned === '' ? null : $cleaned;
    }

    private static function sanitizeRut(mixed $value): ?string
    {
        $cleaned = self::sanitizeString($value);
        
        if ($cleaned === null) {
            return null;
        }

        // Remover puntos y guiones, normalizar K mayúscula
        $cleaned = strtoupper(str_replace(['.', '-'], '', $cleaned));
        
        // Limitar longitud
        if (strlen($cleaned) > self::MAX_RUT_LENGTH) {
            $cleaned = substr($cleaned, 0, self::MAX_RUT_LENGTH);
        }

        return $cleaned === '' ? null : $cleaned;
    }

    private static function sanitizeMonto(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        // Remover caracteres no numéricos
        $cleaned = preg_replace('/[^0-9]/', '', (string) $value);
        
        return (int) ($cleaned ?: 0);
    }
}
