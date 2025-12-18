<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlujoNodoFinal extends Model
{
    protected $table = 'flujo_nodos_finales';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'flujo_id',
        'label',
        'description',
    ];

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }
}
