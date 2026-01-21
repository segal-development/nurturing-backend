<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlujoEjecucionCondicion extends Model
{
    protected $table = 'flujo_ejecucion_condiciones';

    protected $fillable = [
        'flujo_ejecucion_id',
        'etapa_id',
        'condition_node_id',
        'check_param',
        'check_operator',
        'check_value',
        'fecha_verificacion',
        'resultado',
        'response_athenacampaign',
        'check_result_value',
        // Campos para filtrado por prospecto
        'prospectos_rama_si',
        'prospectos_rama_no',
        'total_evaluados',
        'total_rama_si',
        'total_rama_no',
    ];

    protected function casts(): array
    {
        return [
            'fecha_verificacion' => 'datetime',
            'response_athenacampaign' => 'array',
            'check_result_value' => 'integer',
            'prospectos_rama_si' => 'array',
            'prospectos_rama_no' => 'array',
        ];
    }

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(FlujoEjecucion::class, 'flujo_ejecucion_id');
    }
}
