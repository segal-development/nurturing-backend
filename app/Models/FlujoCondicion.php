<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlujoCondicion extends Model
{
    protected $table = 'flujo_condiciones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'flujo_id',
        'label',
        'description',
        'condition_type',
        'condition_label',
        'yes_label',
        'no_label',
        // Campos de evaluación para VerificarCondicionJob
        'check_param',    // Views, Clicks, Bounces, Unsubscribes
        'check_operator', // >, >=, ==, !=, <, <=
        'check_value',    // Valor esperado
    ];

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }
}
