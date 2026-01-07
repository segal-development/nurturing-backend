<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Importacion extends Model
{
    use HasFactory;

    protected $table = 'importaciones';

    protected $fillable = [
        'lote_id',
        'nombre_archivo',
        'ruta_archivo',
        'origen',
        'total_registros',
        'registros_exitosos',
        'registros_fallidos',
        'user_id',
        'estado',
        'fecha_importacion',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'fecha_importacion' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function prospectos(): HasMany
    {
        return $this->hasMany(Prospecto::class);
    }

    public function isCompletado(): bool
    {
        return $this->estado === 'completado';
    }

    public function isFallido(): bool
    {
        return $this->estado === 'fallido';
    }

    public function isProcesando(): bool
    {
        return $this->estado === 'procesando';
    }
}
