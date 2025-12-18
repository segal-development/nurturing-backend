<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlujoEjecucionCondicion extends Model
{
    protected $table = 'flujo_ejecucion_condiciones';

    protected $fillable = [
        'flujo_ejecucion_id',
        'condition_node_id',
        'check_param',
        'check_operator',
        'check_value',
        'fecha_verificacion',
        'resultado',
        'response_athenacampaign',
        'check_result_value',
    ];

    protected function casts(): array
    {
        return [
            'fecha_verificacion' => 'datetime',
            'response_athenacampaign' => 'array',
            'check_result_value' => 'integer',
        ];
    }

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(FlujoEjecucion::class, 'flujo_ejecucion_id');
    }
}
