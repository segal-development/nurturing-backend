<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Envio extends Model
{
    use HasFactory;

    protected $fillable = [
        'prospecto_id',
        'flujo_id',
        'etapa_flujo_id',
        'flujo_ejecucion_etapa_id',
        'plantilla_mensaje_id',
        'prospecto_en_flujo_id',
        'asunto',
        'contenido_enviado',
        'canal',
        'destinatario',
        'tracking_token',
        'athena_message_id',
        'athena_synced_at',
        'estado',
        'fecha_programada',
        'fecha_enviado',
        'fecha_abierto',
        'fecha_clickeado',
        'total_aperturas',
        'total_clicks',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'fecha_programada' => 'datetime',
            'fecha_enviado' => 'datetime',
            'fecha_abierto' => 'datetime',
            'fecha_clickeado' => 'datetime',
            'athena_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function prospecto(): BelongsTo
    {
        return $this->belongsTo(Prospecto::class);
    }

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }

    public function etapaFlujo(): BelongsTo
    {
        return $this->belongsTo(EtapaFlujo::class);
    }

    public function plantillaMensaje(): BelongsTo
    {
        return $this->belongsTo(PlantillaMensaje::class);
    }

    public function prospectoEnFlujo(): BelongsTo
    {
        return $this->belongsTo(ProspectoEnFlujo::class);
    }

    public function flujoEjecucionEtapa(): BelongsTo
    {
        return $this->belongsTo(FlujoEjecucionEtapa::class);
    }

    public function ofertas(): BelongsToMany
    {
        return $this->belongsToMany(
            OfertaInfocom::class,
            'envio_oferta',
            'envio_id',
            'oferta_infocom_id'
        )->withTimestamps();
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopeEnviados($query)
    {
        return $query->where('estado', 'enviado');
    }

    public function scopeFallidos($query)
    {
        return $query->where('estado', 'fallido');
    }

    public function scopeProgramadosParaHoy($query)
    {
        return $query->pendientes()
            ->whereDate('fecha_programada', '<=', now());
    }

    public function scopePorCanal($query, string $canal)
    {
        return $query->where('canal', $canal);
    }

    public function scopePorEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    public function marcarComoEnviado(): void
    {
        $this->update([
            'estado' => 'enviado',
            'fecha_enviado' => now(),
        ]);
    }

    public function marcarComoFallido(?string $error = null): void
    {
        $metadata = $this->metadata ?? [];
        if ($error) {
            $metadata['error'] = $error;
            $metadata['fecha_error'] = now()->toISOString();
        }

        $this->update([
            'estado' => 'fallido',
            'metadata' => $metadata,
        ]);
    }

    public function marcarComoAbierto(): void
    {
        $this->update([
            'estado' => 'abierto',
            'fecha_abierto' => now(),
        ]);
    }

    public function marcarComoClickeado(): void
    {
        $this->update([
            'estado' => 'clickeado',
            'fecha_clickeado' => now(),
        ]);
    }

    public function isPendiente(): bool
    {
        return $this->estado === 'pendiente';
    }

    public function isEnviado(): bool
    {
        return in_array($this->estado, ['enviado', 'abierto', 'clickeado']);
    }

    public function isFallido(): bool
    {
        return $this->estado === 'fallido';
    }

    /**
     * Relación con aperturas de email
     */
    public function aperturas(): HasMany
    {
        return $this->hasMany(EmailApertura::class);
    }

    /**
     * Relación con clicks de email
     */
    public function clicks(): HasMany
    {
        return $this->hasMany(EmailClick::class);
    }
}
