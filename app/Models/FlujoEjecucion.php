<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlujoEjecucion extends Model
{
    use HasFactory;

    protected $table = 'flujo_ejecuciones';

    protected $fillable = [
        'flujo_id',
        'origen_id',
        'prospectos_ids',
        'fecha_inicio_programada',
        'fecha_inicio_real',
        'fecha_fin',
        'estado',
        'nodo_actual',
        'proximo_nodo',
        'fecha_proximo_nodo',
        'config',
        'error_message',
        // Cost tracking fields
        'costo_estimado',
        'costo_real',
        'costo_emails',
        'costo_sms',
        'total_emails_enviados',
        'total_sms_enviados',
    ];

    protected function casts(): array
    {
        return [
            'prospectos_ids' => 'array',
            'config' => 'array',
            'fecha_inicio_programada' => 'datetime',
            'fecha_inicio_real' => 'datetime',
            'fecha_fin' => 'datetime',
            'fecha_proximo_nodo' => 'datetime',
            'costo_estimado' => 'decimal:2',
            'costo_real' => 'decimal:2',
            'costo_emails' => 'decimal:2',
            'costo_sms' => 'decimal:2',
            'total_emails_enviados' => 'integer',
            'total_sms_enviados' => 'integer',
        ];
    }

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(FlujoEjecucionEtapa::class);
    }

    public function condiciones(): HasMany
    {
        return $this->hasMany(FlujoEjecucionCondicion::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(FlujoJob::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FlujoLog::class);
    }

    /**
     * Scope para ejecuciones pendientes
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pending');
    }

    /**
     * Scope para ejecuciones en progreso
     */
    public function scopeEnProgreso($query)
    {
        return $query->where('estado', 'in_progress');
    }

    /**
     * Scope para ejecuciones que deberían haber comenzado
     */
    public function scopeDeberianHaberComenzado($query)
    {
        return $query->where('estado', 'pending')
            ->where('fecha_inicio_programada', '<=', now());
    }

    /**
     * Scope para ejecuciones activas (in_progress)
     */
    public function scopeActivas($query)
    {
        return $query->where('estado', 'in_progress');
    }

    /**
     * Scope para ejecuciones con nodos programados listos para ejecutar
     */
    public function scopeConNodosProgramados($query)
    {
        return $query->where('estado', 'in_progress')
            ->whereNotNull('proximo_nodo')
            ->whereNotNull('fecha_proximo_nodo')
            ->where('fecha_proximo_nodo', '<=', now());
    }

    /**
     * Ejecuciones que tienen etapas en 'executing' (posiblemente terminadas).
     * Usado para detectar etapas de volumen grande que terminaron de procesar.
     */
    public function scopeConEtapasEjecutando($query)
    {
        return $query->where('estado', 'in_progress')
            ->whereHas('etapas', function ($q) {
                $q->where('estado', 'executing');
            });
    }
}
