<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlujoRamificacion extends Model
{
    protected $table = 'flujo_ramificaciones';

    protected $fillable = [
        'flujo_id',
        'edge_id',
        'source_node_id',
        'target_node_id',
        'source_handle',
        'target_handle',
        'condition_branch',
    ];

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }
}
