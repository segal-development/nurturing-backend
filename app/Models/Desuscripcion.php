<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para registro de desuscripciones.
 * 
 * Mantiene un historial auditable de todas las desuscripciones
 * para compliance legal (GDPR, etc.)
 */
class Desuscripcion extends Model
{
    use HasFactory;

    protected $table = 'desuscripciones';

    protected $fillable = [
        'prospecto_id',
        'canal',
        'motivo',
        'token',
        'ip_address',
        'user_agent',
        'envio_id',
        'flujo_id',
    ];

    /**
     * Canales disponibles para desuscripción
     */
    public const CANAL_EMAIL = 'email';
    public const CANAL_SMS = 'sms';
    public const CANAL_TODOS = 'todos';

    /**
     * Motivos predefinidos para desuscripción
     */
    public const MOTIVOS = [
        'no_interesado' => 'No me interesa este servicio',
        'muy_frecuente' => 'Recibo demasiados mensajes',
        'no_relevante' => 'El contenido no es relevante para mí',
        'ya_cliente' => 'Ya soy cliente',
        'otro' => 'Otro motivo',
    ];

    public function prospecto(): BelongsTo
    {
        return $this->belongsTo(Prospecto::class);
    }

    public function envio(): BelongsTo
    {
        return $this->belongsTo(Envio::class);
    }

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }

    /**
     * Scope para filtrar por canal
     */
    public function scopePorCanal($query, string $canal)
    {
        return $query->where('canal', $canal);
    }

    /**
     * Scope para desuscripciones recientes (últimos N días)
     */
    public function scopeRecientes($query, int $dias = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($dias));
    }
}
