<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlujoJob extends Model
{
    protected $table = 'flujo_jobs';

    protected $fillable = [
        'flujo_ejecucion_id',
        'job_type',
        'job_id',
        'job_data',
        'estado',
        'fecha_queued',
        'fecha_procesado',
        'error_details',
        'intentos',
    ];

    protected function casts(): array
    {
        return [
            'job_data' => 'array',
            'fecha_queued' => 'datetime',
            'fecha_procesado' => 'datetime',
            'intentos' => 'integer',
        ];
    }

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(FlujoEjecucion::class, 'flujo_ejecucion_id');
    }

    /**
     * Scope para jobs en cola
     */
    public function scopeEnCola($query)
    {
        return $query->where('estado', 'queued');
    }

    /**
     * Scope para jobs fallidos
     */
    public function scopeFallidos($query)
    {
        return $query->where('estado', 'failed');
    }
}
