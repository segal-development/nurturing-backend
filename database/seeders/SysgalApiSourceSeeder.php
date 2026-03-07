<?php

namespace Database\Seeders;

use App\Models\ExternalApiSource;
use Illuminate\Database\Seeder;

/**
 * Seeder para configurar la API unificada de Sysgal (Defensoría).
 *
 * Ejecutar con: php artisan db:seed --class=SysgalApiSourceSeeder
 *
 * Esta fuente unificada sincroniza de múltiples endpoints:
 * - /ProspectosNoAgendados: Prospectos que entraron pero no agendaron cita
 * - /AgendadosNoCerrados: Prospectos que agendaron pero no contrataron
 *
 * Todos los prospectos van a un único lote "SYSGAL" y se clasifican
 * por nivel de deuda (baja/media/alta) en metadata.
 */
class SysgalApiSourceSeeder extends Seeder
{
    public function run(): void
    {
        $this->createUnifiedSysgalSource();

        $this->command->info('API de Sysgal (unificada) configurada correctamente.');
    }

    /**
     * Fuente unificada para todos los prospectos de Sysgal.
     */
    private function createUnifiedSysgalSource(): void
    {
        ExternalApiSource::updateOrCreate(
            ['name' => 'sysgal'],
            [
                'display_name' => 'Sysgal (Defensoría)',
                // URL base de referencia
                'endpoint_url' => 'https://sysgal.segal.cl/defensoria/Servicio',
                'auth_type' => 'none', // Auth por IP
                'auth_token' => null,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                // Field mapping base (cada endpoint puede tener el suyo)
                'field_mapping' => [
                    'nombre' => 'Nombre',
                    'rut' => 'Rut',
                    'email' => 'Email',
                    'telefono' => 'Telefono',
                    'monto_deuda' => 'TotalDeuda',
                    'url_informe' => null,
                ],
                // No usamos clasificación por lotes separados
                'clasificacion_field' => null,
                'clasificacion_values' => [],
                'sync_filters' => [
                    'dias_atras' => 7,
                    'unificar_lotes' => true,
                    'lote_global' => 'SYSGAL',
                    // Múltiples endpoints en una sola fuente
                    'endpoints' => [
                        [
                            'name' => 'no_agendados',
                            'url' => 'https://sysgal.segal.cl/defensoria/Servicio/ProspectosNoAgendados',
                            'display_name' => 'Prospectos No Agendados',
                            'field_mapping' => [
                                'nombre' => 'Nombre',
                                'rut' => 'Rut',
                                'email' => 'Email',
                                'telefono' => 'Telefono',
                                'monto_deuda' => 'TotalDeuda',
                                'etapa_sysgal' => 'Etapa',
                            ],
                            'date_format' => 'Y-m-d',
                        ],
                        [
                            'name' => 'no_cerrados',
                            'url' => 'https://sysgal.segal.cl/defensoria/Servicio/AgendadosNoCerrados',
                            'display_name' => 'Agendas No Cerradas',
                            'field_mapping' => [
                                'nombre' => 'Cliente.Nombre',
                                'rut' => 'Cliente.Rut',
                                'email' => 'Cliente.Email',
                                'telefono' => 'Cliente.Telefono',
                                'monto_deuda' => 'Cliente.TotalDeuda',
                                'fecha_reunion' => 'Reunion.Tiempo',
                                'estado_reunion' => 'Reunion.Estado_Final',
                                'comercial' => 'Reunion.Comercial',
                            ],
                            'date_format' => 'Y-m-d H:i:s',
                        ],
                    ],
                ],
                'sync_frequency' => 'weekly',
                'lote_prefix' => 'SYSGAL',
                'is_active' => true,
            ]
        );

        $this->command->info('  - sysgal (fuente unificada) configurada');
    }
}
