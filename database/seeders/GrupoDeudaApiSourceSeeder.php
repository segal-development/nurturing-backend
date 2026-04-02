<?php

namespace Database\Seeders;

use App\Models\ExternalApiSource;
use Illuminate\Database\Seeder;

/**
 * Seeder para configurar las APIs de Grupo Deudas.
 *
 * Ejecutar con: php artisan db:seed --class=GrupoDeudaApiSourceSeeder
 *
 * Endpoints configurados:
 * - /ContratosNuevos: Contratos nuevos (sync incremental cada hora)
 * - /CuotasPorVencer: Cuotas que vencen hoy (diario 7am)
 * - /CuotasVencidas: Cuotas vencidas - morosos (diario 7am)
 * - /ClientesPorFechaIngreso: Clientes que firmaron hoy (diario 7am)
 *
 * Todas las fuentes:
 * - Auth por IP (no requiere token)
 * - Cada una tiene su propio lote
 */
class GrupoDeudaApiSourceSeeder extends Seeder
{
    private const BASE_URL = 'https://sysgal.segal.cl/defensoria/Servicio';

    public function run(): void
    {
        $this->createContratosNuevosSource();
        $this->createCuotasPorVencerSource();
        $this->createCuotasVencidasSource();
        $this->createClientesIngresoSource();

        $this->command->info('APIs de Grupo Deudas configuradas correctamente.');
    }

    /**
     * Fuente para contratos nuevos de Grupo Deudas.
     */
    private function createContratosNuevosSource(): void
    {
        ExternalApiSource::updateOrCreate(
            ['name' => 'grupo_deuda_contratos'],
            [
                'display_name' => 'Grupo Deudas - Contratos Nuevos',
                'endpoint_url' => self::BASE_URL.'/ContratosNuevos',
                'auth_type' => 'none',
                'auth_token' => null,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'field_mapping' => [
                    'nombre' => 'Cliente',
                    'rut' => 'Rut',
                    'email' => 'Email',
                    'telefono' => 'Telefono',
                    'monto_deuda' => 'Monto',
                ],
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

    /**
     * Fuente para cuotas por vencer de Grupo Deudas.
     */
    private function createCuotasPorVencerSource(): void
    {
        ExternalApiSource::updateOrCreate(
            ['name' => 'grupo_deuda_cuotas_por_vencer'],
            [
                'display_name' => 'Grupo Deudas - Cuotas Por Vencer',
                'endpoint_url' => self::BASE_URL.'/CuotasPorVencer',
                'auth_type' => 'none',
                'auth_token' => null,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'field_mapping' => [
                    'nombre' => 'computed', // Se construye de Nombre + Apellidos
                    'email' => 'Email',
                    'telefono' => 'Telefono',
                    'monto_deuda' => 'computed', // Suma de Cuotas[].Monto
                ],
                'clasificacion_field' => null,
                'clasificacion_values' => [],
                'sync_filters' => [
                    'unificar_lotes' => true,
                    'lote_global' => 'CUOTAS_POR_VENCER',
                ],
                'sync_frequency' => 'daily',
                'lote_prefix' => 'GD',
                'is_active' => true,
            ]
        );

        $this->command->info('  - grupo_deuda_cuotas_por_vencer configurada');
    }

    /**
     * Fuente para cuotas vencidas de Grupo Deudas.
     */
    private function createCuotasVencidasSource(): void
    {
        ExternalApiSource::updateOrCreate(
            ['name' => 'grupo_deuda_cuotas_vencidas'],
            [
                'display_name' => 'Grupo Deudas - Cuotas Vencidas',
                'endpoint_url' => self::BASE_URL.'/CuotasVencidas',
                'auth_type' => 'none',
                'auth_token' => null,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'field_mapping' => [
                    'nombre' => 'computed', // Se construye de Nombre + Apellidos
                    'email' => 'Email',
                    'telefono' => 'Telefono',
                    'monto_deuda' => 'computed', // Suma de Cuotas[].Monto
                ],
                'clasificacion_field' => null,
                'clasificacion_values' => [],
                'sync_filters' => [
                    'unificar_lotes' => true,
                    'lote_global' => 'CUOTAS_VENCIDAS',
                ],
                'sync_frequency' => 'daily',
                'lote_prefix' => 'GD',
                'is_active' => true,
            ]
        );

        $this->command->info('  - grupo_deuda_cuotas_vencidas configurada');
    }

    /**
     * Fuente para clientes por fecha de ingreso de Grupo Deudas.
     */
    private function createClientesIngresoSource(): void
    {
        ExternalApiSource::updateOrCreate(
            ['name' => 'grupo_deuda_clientes_ingreso'],
            [
                'display_name' => 'Grupo Deudas - Clientes Ingreso',
                'endpoint_url' => self::BASE_URL.'/ClientesPorFechaIngreso',
                'auth_type' => 'none',
                'auth_token' => null,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'field_mapping' => [
                    'nombre' => 'computed', // Se construye de Nombre + Apellidos
                    'email' => 'Email',
                    'telefono' => 'Telefono',
                ],
                'clasificacion_field' => null,
                'clasificacion_values' => [],
                'sync_filters' => [
                    'unificar_lotes' => true,
                    'lote_global' => 'CLIENTES_ACTIVOS',
                ],
                'sync_frequency' => 'daily',
                'lote_prefix' => 'GD',
                'is_active' => true,
            ]
        );

        $this->command->info('  - grupo_deuda_clientes_ingreso configurada');
    }
}
