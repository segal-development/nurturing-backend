<?php

namespace Database\Seeders;

use App\Models\ExternalApiSource;
use Illuminate\Database\Seeder;

/**
 * Seeder para configurar la API de Grupo Deudas - Contratos Nuevos.
 *
 * Ejecutar con: php artisan db:seed --class=GrupoDeudaApiSourceSeeder
 *
 * Esta fuente sincroniza contratos nuevos (clientes que ya contrataron)
 * para nurturing post-venta.
 *
 * Características:
 * - Endpoint: /ContratosNuevos
 * - Auth por IP (no requiere token)
 * - Sync incremental cada hora
 * - Todos los contratos van a un único lote "CONTRATOS_NUEVOS"
 */
class GrupoDeudaApiSourceSeeder extends Seeder
{
    public function run(): void
    {
        $this->createGrupoDeudaSource();

        $this->command->info('API de Grupo Deudas configurada correctamente.');
    }

    /**
     * Fuente para contratos nuevos de Grupo Deudas.
     */
    private function createGrupoDeudaSource(): void
    {
        ExternalApiSource::updateOrCreate(
            ['name' => 'grupo_deuda_contratos'],
            [
                'display_name' => 'Grupo Deudas - Contratos Nuevos',
                'endpoint_url' => 'https://sysgal.segal.cl/defensoria/Servicio/ContratosNuevos',
                'auth_type' => 'none', // Auth por IP
                'auth_token' => null,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                // Field mapping: API Campo => Prospecto Campo
                'field_mapping' => [
                    'nombre' => 'Cliente',
                    'rut' => 'Rut',
                    'email' => 'Email',
                    'telefono' => 'Telefono',
                    'monto_deuda' => 'Monto',
                ],
                // No usamos clasificación por lotes separados
                'clasificacion_field' => null,
                'clasificacion_values' => [],
                'sync_filters' => [
                    'unificar_lotes' => true,
                    'lote_global' => 'CONTRATOS_NUEVOS',
                ],
                'sync_frequency' => 'hourly',
                'lote_prefix' => 'GD',
                'is_active' => true,
            ]
        );

        $this->command->info('  - grupo_deuda_contratos configurada');
    }
}
