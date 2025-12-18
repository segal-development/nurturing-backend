<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlujoEjecucionEtapa extends Model
{
    use HasFactory;

    protected $table = 'flujo_ejecucion_etapas';

    protected $fillable = [
        'flujo_ejecucion_id',
        'etapa_id',
        'node_id',
        'fecha_programada',
        'fecha_ejecucion',
        'estado',
        'ejecutado',
        'message_id',
        'response_athenacampaign',
        'error_mensaje',
    ];

    protected function casts(): array
    {
        return [
            'fecha_programada' => 'datetime',
            'fecha_ejecucion' => 'datetime',
            'ejecutado' => 'boolean',
            'response_athenacampaign' => 'array',
        ];
    }

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(FlujoEjecucion::class, 'flujo_ejecucion_id');
    }

    /**
     * Scope para etapas pendientes
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pending');
    }

    /**
     * Scope para etapas que deberían ejecutarse
     */
    public function scopeDeberianEjecutarse($query)
    {
        return $query->where('estado', 'pending')
            ->where('fecha_programada', '<=', now());
    }

    /**
     * Scope para etapas no ejecutadas
     */
    public function scopeNoEjecutadas($query)
    {
        return $query->where('ejecutado', false);
    }

    /**
     * Scope para etapas ejecutadas
     */
    public function scopeEjecutadas($query)
    {
        return $query->where('ejecutado', true);
    }
}
