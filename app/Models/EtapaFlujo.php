<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EtapaFlujo extends Model
{
    use HasFactory;

    protected $table = 'etapas_flujo';

    protected $fillable = [
        'flujo_id',
        'nombre',
        'dias_desde_inicio',
        'orden',
        'plantilla_mensaje_id',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }

    public function plantillaMensaje(): BelongsTo
    {
        return $this->belongsTo(PlantillaMensaje::class);
    }

    public function ofertas(): BelongsToMany
    {
        return $this->belongsToMany(
            OfertaInfocom::class,
            'etapa_oferta',
            'etapa_flujo_id',
            'oferta_infocom_id'
        )
            ->withPivot('orden', 'activo')
            ->withTimestamps()
            ->orderByPivot('orden');
    }

    public function ofertasActivas(): BelongsToMany
    {
        return $this->ofertas()->wherePivot('activo', true);
    }

    public function envios(): HasMany
    {
        return $this->hasMany(Envio::class);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeOrdenados($query)
    {
        return $query->orderBy('orden');
    }

    public function scopePorFlujo($query, int $flujoId)
    {
        return $query->where('flujo_id', $flujoId);
    }

    public function calcularFechaProgramada(\DateTime $fechaInicio): \DateTime
    {
        return (clone $fechaInicio)->modify("+{$this->dias_desde_inicio} days");
    }
}
