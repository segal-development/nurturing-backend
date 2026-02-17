<?php

namespace Database\Seeders;

use App\Models\ExternalApiSource;
use Illuminate\Database\Seeder;

/**
 * Seeder para configurar las APIs de Sysgal (Defensoría).
 *
 * Ejecutar con: php artisan db:seed --class=SysgalApiSourceSeeder
 *
 * Endpoints disponibles:
 * - /ProspectosNoAgendados: Prospectos que entraron pero no agendaron cita
 * - /AgendadosNoCerrados: Prospectos que agendaron pero no contrataron
 */
class SysgalApiSourceSeeder extends Seeder
{
    public function run(): void
    {
        $this->createNoAgendadosSource();
        $this->createNoCerradosSource();

        $this->command->info('APIs de Sysgal configuradas correctamente.');
    }

    /**
     * Fuente para prospectos que no agendaron cita.
     */
    private function createNoAgendadosSource(): void
    {
        ExternalApiSource::updateOrCreate(
            ['name' => 'sysgal_no_agendados'],
            [
                'display_name' => 'Sysgal - No Agendados',
                'endpoint_url' => 'https://sysgal.segal.cl/defensoria/Servicio/ProspectosNoAgendados',
                'auth_type' => 'none', // Auth por IP
                'auth_token' => null,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                // Mapeo de campos de la API a campos de Prospecto
                // La API devuelve: { Nombre, Rut, Email, Telefono, Etapa }
                'field_mapping' => [
                    'nombre' => 'Nombre',
                    'rut' => 'Rut',
                    'email' => 'Email',
                    'telefono' => 'Telefono',
                    'monto_deuda' => null, // No viene en esta API
                    'url_informe' => null,
                    // Campos extra para metadata
                    'etapa_sysgal' => 'Etapa',
                ],
                // Campo para clasificar prospectos en lotes separados
                'clasificacion_field' => 'Etapa',
                // Valores conocidos de clasificación (etapas del CRM Sysgal)
                'clasificacion_values' => [
                    'Pendiente de Contactar',
                    'Solo Consulta',
                ],
                'sync_filters' => [
                    // Rango de fechas se calcula dinámicamente en el servicio
                    'dias_atras' => 7, // Última semana por defecto
                ],
                'sync_frequency' => 'weekly',
                'lote_prefix' => 'SG_NA', // Sysgal No Agendados
                'is_active' => true,
            ]
        );

        $this->command->info('  - sysgal_no_agendados configurada');
    }

    /**
     * Fuente para agendas que no cerraron (no contrataron).
     */
    private function createNoCerradosSource(): void
    {
        ExternalApiSource::updateOrCreate(
            ['name' => 'sysgal_no_cerrados'],
            [
                'display_name' => 'Sysgal - No Cerrados',
                'endpoint_url' => 'https://sysgal.segal.cl/defensoria/Servicio/AgendadosNoCerrados',
                'auth_type' => 'none', // Auth por IP
                'auth_token' => null,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                // Mapeo de campos de la API a campos de Prospecto
                // La API devuelve: { Reunion: { Tiempo, Estado_Final, Comercial }, Cliente: { Nombre, Rut, Email, Telefono } }
                'field_mapping' => [
                    'nombre' => 'Cliente.Nombre',
                    'rut' => 'Cliente.Rut',
                    'email' => 'Cliente.Email',
                    'telefono' => 'Cliente.Telefono',
                    'monto_deuda' => null,
                    'url_informe' => null,
                    // Campos extra para metadata
                    'fecha_reunion' => 'Reunion.Tiempo',
                    'estado_reunion' => 'Reunion.Estado_Final',
                    'comercial' => 'Reunion.Comercial',
                ],
                // Campo para clasificar prospectos en lotes separados
                'clasificacion_field' => 'Reunion.Estado_Final',
                // Valores conocidos de clasificación (estados de reunión)
                'clasificacion_values' => [
                    'NO CONTRATA - NO LE INTERESA',
                    'NO CONTRATA - CESANTE / SIN DINERO',
                    'NO CONTRATA - SOLO CONSULTA Y ANDA COTIZANDO',
                    'NO CALIFICA - TIENE ABOGADO',
                    'NO CALIFICA - NO EXISTE SERVICIO QUE OFRECER',
                    'NO CALIFICA - SOLO QUIERE DICOM',
                    'NO QUIERE ASESORIA',
                    'SIN GESTIONAR',
                ],
                'sync_filters' => [
                    'dias_atras' => 7,
                ],
                'sync_frequency' => 'weekly',
                'lote_prefix' => 'SG_NC', // Sysgal No Cerrados
                'is_active' => true,
            ]
        );

        $this->command->info('  - sysgal_no_cerrados configurada');
    }
}
