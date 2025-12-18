<?php

namespace Database\Seeders;

use App\Models\Configuracion;
use Illuminate\Database\Seeder;

class ConfiguracionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Configuracion::firstOrCreate(
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
