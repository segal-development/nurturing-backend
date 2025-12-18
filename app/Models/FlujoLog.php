<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlujoLog extends Model
{
    protected $table = 'flujo_logs';

    protected $fillable = [
        'flujo_ejecucion_id',
        'etapa_id',
        'accion',
        'resultado',
        'mensaje',
        'fecha',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(FlujoEjecucion::class, 'flujo_ejecucion_id');
    }
}
