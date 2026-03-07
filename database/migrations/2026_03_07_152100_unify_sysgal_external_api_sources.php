<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migración para unificar las fuentes de Sysgal en un único ExternalApiSource.
 *
 * ANTES: Dos fuentes separadas (sysgal_no_agendados, sysgal_no_cerrados)
 * DESPUÉS: Una sola fuente "sysgal" con múltiples endpoints en su configuración
 *
 * Esta migración:
 * 1. Crea la fuente unificada "sysgal" con ambos endpoints
 * 2. Migra todas las relaciones (lotes, importaciones) a la nueva fuente
 * 3. Desactiva las fuentes viejas (no se eliminan para mantener historial)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Obtener IDs de las fuentes actuales
        $sourceNoAgendados = DB::table('external_api_sources')
            ->where('name', 'sysgal_no_agendados')
            ->first();

        $sourceNoCerrados = DB::table('external_api_sources')
            ->where('name', 'sysgal_no_cerrados')
            ->first();

        // Si no existen las fuentes viejas, no hay nada que migrar
        if (! $sourceNoAgendados && ! $sourceNoCerrados) {
            return;
        }

        // 2. Crear la fuente unificada "sysgal"
        $now = now();
        $sysgalId = DB::table('external_api_sources')->insertGetId([
            'name' => 'sysgal',
            'display_name' => 'Sysgal (Defensoría)',
            // endpoint_url se usa como referencia, pero el servicio usará los endpoints del array
            'endpoint_url' => 'https://sysgal.segal.cl/defensoria/Servicio',
            'auth_type' => 'none',
            'auth_token' => null,
            'headers' => json_encode([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            // Field mapping unificado (usamos el de no_agendados como base)
            'field_mapping' => json_encode([
                'nombre' => 'Nombre',
                'rut' => 'Rut',
                'email' => 'Email',
                'telefono' => 'Telefono',
                'monto_deuda' => 'TotalDeuda',
                'url_informe' => null,
            ]),
            'clasificacion_field' => null, // Ya no usamos clasificación por lotes separados
            'clasificacion_values' => json_encode([]),
            'sync_filters' => json_encode([
                'dias_atras' => 7,
                'unificar_lotes' => true,
                'lote_global' => 'SYSGAL',
                // ✅ NUEVO: Array de endpoints a sincronizar
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
            ]),
            'sync_frequency' => 'weekly',
            'lote_prefix' => 'SYSGAL',
            'is_active' => true,
            'last_synced_at' => $sourceNoAgendados?->last_synced_at ?? $sourceNoCerrados?->last_synced_at,
            'last_sync_count' => ($sourceNoAgendados?->last_sync_count ?? 0) + ($sourceNoCerrados?->last_sync_count ?? 0),
            'last_sync_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 3. Migrar lotes a la nueva fuente
        // El lote "SYSGAL" ya existe y es compartido, solo actualizamos su referencia
        DB::table('lotes')
            ->where('nombre', 'SYSGAL')
            ->update(['external_api_source_id' => $sysgalId, 'updated_at' => $now]);

        // También migramos lotes históricos que pudieran tener las fuentes viejas
        if ($sourceNoAgendados) {
            DB::table('lotes')
                ->where('external_api_source_id', $sourceNoAgendados->id)
                ->update(['external_api_source_id' => $sysgalId, 'updated_at' => $now]);
        }

        if ($sourceNoCerrados) {
            DB::table('lotes')
                ->where('external_api_source_id', $sourceNoCerrados->id)
                ->update(['external_api_source_id' => $sysgalId, 'updated_at' => $now]);
        }

        // 4. Migrar importaciones a la nueva fuente
        if ($sourceNoAgendados) {
            DB::table('importaciones')
                ->where('external_api_source_id', $sourceNoAgendados->id)
                ->update(['external_api_source_id' => $sysgalId, 'updated_at' => $now]);
        }

        if ($sourceNoCerrados) {
            DB::table('importaciones')
                ->where('external_api_source_id', $sourceNoCerrados->id)
                ->update(['external_api_source_id' => $sysgalId, 'updated_at' => $now]);
        }

        // 5. Desactivar las fuentes viejas (no eliminamos para mantener historial)
        if ($sourceNoAgendados) {
            DB::table('external_api_sources')
                ->where('id', $sourceNoAgendados->id)
                ->update([
                    'is_active' => false,
                    'name' => 'sysgal_no_agendados_deprecated',
                    'display_name' => '[DEPRECATED] Sysgal - No Agendados',
                    'updated_at' => $now,
                ]);
        }

        if ($sourceNoCerrados) {
            DB::table('external_api_sources')
                ->where('id', $sourceNoCerrados->id)
                ->update([
                    'is_active' => false,
                    'name' => 'sysgal_no_cerrados_deprecated',
                    'display_name' => '[DEPRECATED] Sysgal - No Cerrados',
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Revertir: reactivar fuentes viejas y eliminar la unificada
        $sysgal = DB::table('external_api_sources')
            ->where('name', 'sysgal')
            ->first();

        if (! $sysgal) {
            return;
        }

        $now = now();

        // Obtener fuentes deprecated
        $sourceNoAgendados = DB::table('external_api_sources')
            ->where('name', 'sysgal_no_agendados_deprecated')
            ->first();

        $sourceNoCerrados = DB::table('external_api_sources')
            ->where('name', 'sysgal_no_cerrados_deprecated')
            ->first();

        // Reactivar fuentes viejas
        if ($sourceNoAgendados) {
            DB::table('external_api_sources')
                ->where('id', $sourceNoAgendados->id)
                ->update([
                    'is_active' => true,
                    'name' => 'sysgal_no_agendados',
                    'display_name' => 'Sysgal - No Agendados',
                    'updated_at' => $now,
                ]);

            // Devolver lotes e importaciones
            DB::table('lotes')
                ->where('external_api_source_id', $sysgal->id)
                ->whereJsonContains('metadata->original_source', 'no_agendados')
                ->update(['external_api_source_id' => $sourceNoAgendados->id, 'updated_at' => $now]);

            DB::table('importaciones')
                ->where('external_api_source_id', $sysgal->id)
                ->whereRaw("origen LIKE '%No Agendados%'")
                ->update(['external_api_source_id' => $sourceNoAgendados->id, 'updated_at' => $now]);
        }

        if ($sourceNoCerrados) {
            DB::table('external_api_sources')
                ->where('id', $sourceNoCerrados->id)
                ->update([
                    'is_active' => true,
                    'name' => 'sysgal_no_cerrados',
                    'display_name' => 'Sysgal - No Cerrados',
                    'updated_at' => $now,
                ]);

            DB::table('lotes')
                ->where('external_api_source_id', $sysgal->id)
                ->whereJsonContains('metadata->original_source', 'no_cerrados')
                ->update(['external_api_source_id' => $sourceNoCerrados->id, 'updated_at' => $now]);

            DB::table('importaciones')
                ->where('external_api_source_id', $sysgal->id)
                ->whereRaw("origen LIKE '%No Cerrados%'")
                ->update(['external_api_source_id' => $sourceNoCerrados->id, 'updated_at' => $now]);
        }

        // Eliminar la fuente unificada
        DB::table('external_api_sources')
            ->where('id', $sysgal->id)
            ->delete();
    }
};
