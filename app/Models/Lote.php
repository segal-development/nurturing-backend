<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Lote extends Model
{
    use HasFactory;

    protected $table = 'lotes';

    protected $fillable = [
        'nombre',
        'user_id',
        'total_archivos',
        'total_registros',
        'registros_exitosos',
        'registros_fallidos',
        'estado',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    // =========================================
    // RELACIONES
    // =========================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function importaciones(): HasMany
    {
        return $this->hasMany(Importacion::class);
    }

    public function prospectos(): HasManyThrough
    {
        return $this->hasManyThrough(Prospecto::class, Importacion::class);
    }

    // =========================================
    // HELPERS
    // =========================================

    public function isAbierto(): bool
    {
        return $this->estado === 'abierto';
    }

    public function isProcesando(): bool
    {
        return $this->estado === 'procesando';
    }

    public function isCompletado(): bool
    {
        return $this->estado === 'completado';
    }

    /**
     * Recalcula los totales del lote basado en sus importaciones
     */
    public function recalcularTotales(): void
    {
        $this->total_archivos = $this->importaciones()->count();
        $this->total_registros = $this->importaciones()->sum('total_registros');
        $this->registros_exitosos = $this->importaciones()->sum('registros_exitosos');
        $this->registros_fallidos = $this->importaciones()->sum('registros_fallidos');
        
        // Actualizar estado basado en importaciones
        $this->actualizarEstado();
        
        $this->save();
    }

    /**
     * Actualiza el estado del lote basado en sus importaciones
     */
    public function actualizarEstado(): void
    {
        $importaciones = $this->importaciones;
        
        if ($importaciones->isEmpty()) {
            $this->estado = 'abierto';
            return;
        }

        $todosProcesando = $importaciones->every(fn($i) => $i->estado === 'procesando');
        $todosCompletados = $importaciones->every(fn($i) => $i->estado === 'completado');
        $algunoFallido = $importaciones->contains(fn($i) => $i->estado === 'fallido');
        $algunoProcesando = $importaciones->contains(fn($i) => $i->estado === 'procesando' || $i->estado === 'pendiente');

        if ($algunoFallido && !$algunoProcesando) {
            $this->estado = 'fallido';
        } elseif ($todosCompletados) {
            $this->estado = 'completado';
        } elseif ($algunoProcesando || $todosProcesando) {
            $this->estado = 'procesando';
        } else {
            $this->estado = 'abierto';
        }
    }
}
