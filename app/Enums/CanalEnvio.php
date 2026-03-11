<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Enum que representa los canales de envío disponibles para un flujo.
 *
 * Usar enums en lugar de strings mágicos previene errores tipográficos
 * y hace el código más mantenible y type-safe.
 */
enum CanalEnvio: string
{
    case EMAIL = 'email';
    case SMS = 'sms';
    case AMBOS = 'ambos';

    /**
     * Crea una instancia desde un tipo de mensaje de etapa.
     *
     * @param  string  $tipoMensaje  'email' o 'sms'
     */
    public static function fromTipoMensaje(string $tipoMensaje): self
    {
        $normalized = strtolower(trim($tipoMensaje));

        return match ($normalized) {
            'email' => self::EMAIL,
            'sms' => self::SMS,
            default => self::EMAIL, // Fallback seguro
        };
    }

    /**
     * Obtiene todos los valores válidos como array de strings.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Verifica si un string es un canal válido.
     */
    public static function isValid(string $value): bool
    {
        return in_array(strtolower($value), self::values(), true);
    }

    /**
     * Obtiene el label para mostrar en UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Email',
            self::SMS => 'SMS',
            self::AMBOS => 'Email y SMS',
        };
    }

    /**
     * Obtiene el emoji/icono representativo.
     */
    public function icon(): string
    {
        return match ($this) {
            self::EMAIL => '📧',
            self::SMS => '📱',
            self::AMBOS => '📨',
        };
    }
}
