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
        // Tiempo de espera antes de evaluar la condición
        'tiempo_evaluacion',        // Cantidad (ej: 3)
        'tiempo_evaluacion_unidad', // 'hours' o 'days'
    ];

    /**
     * Calcula la fecha en que se debe evaluar la condición
     * basándose en tiempo_evaluacion y tiempo_evaluacion_unidad
     */
    public function calcularFechaEvaluacion(?\Carbon\Carbon $fechaBase = null): \Carbon\Carbon
    {
        $fecha = $fechaBase ? $fechaBase->copy() : now();
        $cantidad = $this->tiempo_evaluacion ?? 1;
        $unidad = $this->tiempo_evaluacion_unidad ?? 'days';

        return match ($unidad) {
            'hours' => $fecha->addHours($cantidad),
            'days' => $fecha->addDays($cantidad),
            default => $fecha->addDays($cantidad),
        };
    }

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }
}
