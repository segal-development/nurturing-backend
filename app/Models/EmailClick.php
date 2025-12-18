<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para registrar clicks en enlaces de emails
 * 
 * @property int $id
 * @property int $envio_id
 * @property int $prospecto_id
 * @property string $url_original
 * @property string|null $url_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $dispositivo
 * @property string|null $navegador
 * @property \Carbon\Carbon $fecha_click
 */
class EmailClick extends Model
{
    use HasFactory;

    protected $table = 'email_clicks';

    protected $fillable = [
        'envio_id',
        'prospecto_id',
        'url_original',
        'url_id',
        'ip_address',
        'user_agent',
        'dispositivo',
        'navegador',
        'fecha_click',
    ];

    protected $casts = [
        'fecha_click' => 'datetime',
    ];

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
     * Detecta el tipo de dispositivo desde el User-Agent
     */
    public static function detectarDispositivo(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);

        // Detectar móviles
        $mobileKeywords = ['mobile', 'android', 'iphone', 'ipod', 'windows phone', 'blackberry'];
        foreach ($mobileKeywords as $keyword) {
            if (str_contains($userAgent, $keyword)) {
                return 'mobile';
            }
        }

        // Detectar tablets
        $tabletKeywords = ['tablet', 'ipad', 'kindle', 'playbook'];
        foreach ($tabletKeywords as $keyword) {
            if (str_contains($userAgent, $keyword)) {
                return 'tablet';
            }
        }

        // Por defecto es desktop
        return 'desktop';
    }

    /**
     * Detecta el navegador desde el User-Agent
     */
    public static function detectarNavegador(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);

        // Orden importante: algunos navegadores incluyen "chrome" o "safari" en su UA
        if (str_contains($userAgent, 'edg/')) {
            return 'Microsoft Edge';
        }
        if (str_contains($userAgent, 'opr/') || str_contains($userAgent, 'opera')) {
            return 'Opera';
        }
        if (str_contains($userAgent, 'firefox')) {
            return 'Firefox';
        }
        if (str_contains($userAgent, 'chrome') || str_contains($userAgent, 'crios')) {
            return 'Chrome';
        }
        if (str_contains($userAgent, 'safari')) {
            return 'Safari';
        }
        if (str_contains($userAgent, 'msie') || str_contains($userAgent, 'trident')) {
            return 'Internet Explorer';
        }

        return 'other';
    }

    /**
     * Genera un token único para tracking de click
     * 
     * @param int $envioId
     * @param string $urlOriginal
     * @return string Token único de 32 caracteres
     */
    public static function generarToken(int $envioId, string $urlOriginal): string
    {
        $data = $envioId . '|' . $urlOriginal . '|' . microtime(true);
        return md5($data);
    }
}
