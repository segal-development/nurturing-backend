<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailApertura extends Model
{
    protected $table = 'email_aperturas';

    protected $fillable = [
        'envio_id',
        'prospecto_id',
        'ip_address',
        'user_agent',
        'dispositivo',
        'cliente_email',
        'fecha_apertura',
    ];

    protected function casts(): array
    {
        return [
            'fecha_apertura' => 'datetime',
        ];
    }

    /**
     * Relación con el envío
     */
    public function envio(): BelongsTo
    {
        return $this->belongsTo(Envio::class);
    }

    /**
     * Relación con el prospecto
     */
    public function prospecto(): BelongsTo
    {
        return $this->belongsTo(Prospecto::class);
    }

    /**
     * Detecta el dispositivo desde el User-Agent
     */
    public static function detectarDispositivo(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'desconocido';
        }

        $userAgent = strtolower($userAgent);

        if (str_contains($userAgent, 'mobile') || str_contains($userAgent, 'android') || str_contains($userAgent, 'iphone')) {
            return 'mobile';
        }

        if (str_contains($userAgent, 'tablet') || str_contains($userAgent, 'ipad')) {
            return 'tablet';
        }

        return 'desktop';
    }

    /**
     * Detecta el cliente de email desde el User-Agent
     */
    public static function detectarClienteEmail(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'desconocido';
        }

        $userAgent = strtolower($userAgent);

        // Gmail (Google Image Proxy)
        if (str_contains($userAgent, 'googleimageproxy') || str_contains($userAgent, 'gmail')) {
            return 'Gmail';
        }

        // Apple Mail
        if (str_contains($userAgent, 'applewebkit') && str_contains($userAgent, 'macintosh')) {
            return 'Apple Mail';
        }

        // iOS Mail
        if (str_contains($userAgent, 'applewebkit') && (str_contains($userAgent, 'iphone') || str_contains($userAgent, 'ipad'))) {
            return 'iOS Mail';
        }

        // Outlook
        if (str_contains($userAgent, 'microsoft') || str_contains($userAgent, 'outlook')) {
            return 'Outlook';
        }

        // Yahoo
        if (str_contains($userAgent, 'yahoo')) {
            return 'Yahoo Mail';
        }

        // Thunderbird
        if (str_contains($userAgent, 'thunderbird')) {
            return 'Thunderbird';
        }

        return 'Otro';
    }
}
