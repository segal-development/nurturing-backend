<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Configuracion extends Model
{
    use HasFactory;

    protected $table = 'configuracion';

    protected $fillable = [
        'email_costo',
        'sms_costo',
        'max_prospectos_por_flujo',
        'max_emails_por_dia',
        'max_sms_por_dia',
        'reintentos_envio',
        'notificar_flujo_completado',
        'notificar_errores_envio',
        'email_notificaciones',
    ];

    protected function casts(): array
    {
        return [
            'email_costo' => 'decimal:2',
            'sms_costo' => 'decimal:2',
            'max_prospectos_por_flujo' => 'integer',
            'max_emails_por_dia' => 'integer',
            'max_sms_por_dia' => 'integer',
            'reintentos_envio' => 'integer',
            'notificar_flujo_completado' => 'boolean',
            'notificar_errores_envio' => 'boolean',
        ];
    }

    /**
     * Get the singleton configuration instance.
     */
    public static function get(): self
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'email_costo' => 1.00,
                'sms_costo' => 11.00,
                'max_prospectos_por_flujo' => 10000,
                'max_emails_por_dia' => 5000,
                'max_sms_por_dia' => 500,
                'reintentos_envio' => 3,
                'notificar_flujo_completado' => true,
                'notificar_errores_envio' => true,
                'email_notificaciones' => 'admin@segal.cl',
            ]
        );
    }
}
