<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoProspecto extends Model
{
    use HasFactory;

    protected $table = 'tipo_prospecto';

    protected $fillable = [
        'nombre',
        'descripcion',
        'monto_min',
        'monto_max',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'monto_min' => 'float',
            'monto_max' => 'float',
            'activo' => 'boolean',
        ];
    }

    public function prospectos(): HasMany
    {
        return $this->hasMany(Prospecto::class);
    }

    public function flujos(): HasMany
    {
        return $this->hasMany(Flujo::class);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeOrdenados($query)
    {
        return $query->orderBy('orden');
    }

    public function enRango(float $monto): bool
    {
        if ($this->monto_min === null && $this->monto_max === null) {
            return false;
        }

        $dentroMin = $this->monto_min === null || $monto >= $this->monto_min;
        $dentroMax = $this->monto_max === null || $monto <= $this->monto_max;

        return $dentroMin && $dentroMax;
    }

    public static function findByMonto(float $monto): ?self
    {
        return self::activos()
            ->ordenados()
            ->get()
            ->first(fn ($tipo) => $tipo->enRango($monto));
    }
}
