<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OfertaInfocom extends Model
{
    use HasFactory;

    protected $table = 'ofertas_infocom';

    protected $fillable = [
        'nombre',
        'descripcion',
        'contenido',
        'fecha_inicio',
        'fecha_fin',
        'activo',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'activo' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function etapas(): BelongsToMany
    {
        return $this->belongsToMany(
            EtapaFlujo::class,
            'etapa_oferta',
            'oferta_infocom_id',
            'etapa_flujo_id'
        )
            ->withPivot('orden', 'activo')
            ->withTimestamps();
    }

    public function envios(): BelongsToMany
    {
        return $this->belongsToMany(
            Envio::class,
            'envio_oferta',
            'oferta_infocom_id',
            'envio_id'
        )->withTimestamps();
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeVigentes($query)
    {
        $hoy = now()->toDateString();

        return $query->where(function ($q) use ($hoy) {
            $q->where(function ($subQ) use ($hoy) {
                $subQ->whereNull('fecha_inicio')
                    ->orWhere('fecha_inicio', '<=', $hoy);
            })
                ->where(function ($subQ) use ($hoy) {
                    $subQ->whereNull('fecha_fin')
                        ->orWhere('fecha_fin', '>=', $hoy);
                });
        });
    }

    public function scopeDisponibles($query)
    {
        return $query->activos()->vigentes();
    }

    public function isVigente(): bool
    {
        $hoy = now();

        $inicioValido = ! $this->fecha_inicio || $this->fecha_inicio->lte($hoy);
        $finValido = ! $this->fecha_fin || $this->fecha_fin->gte($hoy);

        return $inicioValido && $finValido;
    }

    public function isDisponible(): bool
    {
        return $this->activo && $this->isVigente();
    }
}
