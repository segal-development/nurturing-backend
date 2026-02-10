<?php

namespace Database\Seeders;

use App\Models\ExternalApiSource;
use Illuminate\Database\Seeder;

/**
 * Seeder para configurar la API de Informes Comerciales.
 *
 * Ejecutar con: php artisan db:seed --class=InformesComercialApiSourceSeeder
 */
class InformesComercialApiSourceSeeder extends Seeder
{
    public function run(): void
    {
        ExternalApiSource::updateOrCreate(
            ['name' => 'informes_comerciales'],
            [
                'display_name' => 'Informes Comerciales',
                'endpoint_url' => 'https://api-commercialreport.segal.cl/api/v1/admins/payments',
                'auth_type' => 'api_key',
                'auth_token' => 'FfLiop5nVDJW7NW5qCnW6EGgMrsBfSg9',
                'headers' => [
                    'Accept' => 'application/json',
                ],
                // Mapeo de campos de la API a campos de Prospecto
                // La API devuelve: { user: { name, lastname, email, phone, rut }, status, product, amount, ... }
                'field_mapping' => [
                    'nombre' => 'user.name|user.lastname', // Concatenar name + lastname
                    'email' => 'user.email',
                    'telefono' => 'user.phone',
                    'rut' => 'user.rut',
                    'monto_deuda' => 'amount',
                    'url_informe' => null, // No tiene URL de informe
                ],
                // Campo para clasificar prospectos en lotes separados
                'clasificacion_field' => 'status',
                // Valores conocidos de clasificación
                'clasificacion_values' => [
                    'form',        // No pasó del formulario
                    'iniciado',    // Clickeó pagar pero no completó
                    'abortado',    // Canceló activamente
                    'timeout',     // Sesión vencida
                    'rechazado',   // Tarjeta rechazada
                    'cancelado',   // Canceló en Transbank
                    'pagado',      // Completó el pago (excluir del sync)
                ],
                // Filtrar para NO traer los que ya pagaron
                'sync_filters' => [
                    // La API no soporta filtros negativos, así que traemos todo
                    // y filtramos después en el servicio
                    'limit' => 10000,
                ],
                'sync_frequency' => 'weekly',
                'lote_prefix' => 'IC', // Informes Comerciales -> IC_form_2026-02-10
                'is_active' => true,
            ]
        );

        $this->command->info('API de Informes Comerciales configurada correctamente.');
    }
}
