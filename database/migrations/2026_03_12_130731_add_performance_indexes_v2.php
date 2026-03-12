<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additional performance indexes based on query analysis.
 *
 * These indexes address:
 * - importaciones: origen queries in opcionesLotes, opcionesFiltrado
 * - flujos: origen, auto_asignar queries
 * - flujo_ejecucion_etapas: estado + fecha_programada for EjecutarNodosProgramados
 * - prospecto_en_flujo: flujo_id + prospecto_id for existence checks
 * - envios: etapa + estado composite for stats queries
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // IMPORTACIONES - Used by opcionesLotes, opcionesFiltrado
        // =====================================================================
        Schema::table('importaciones', function (Blueprint $table) {
            if (! $this->indexExists('importaciones', 'idx_importaciones_origen')) {
                $table->index('origen', 'idx_importaciones_origen');
            }

            // Composite for subqueries in opcionesLotes
            if (! $this->indexExists('importaciones', 'idx_importaciones_lote_origen')) {
                $table->index(['lote_id', 'origen'], 'idx_importaciones_lote_origen');
            }
        });

        // =====================================================================
        // FLUJOS - Used by auto-assign jobs, filtering
        // =====================================================================
        Schema::table('flujos', function (Blueprint $table) {
            if (! $this->indexExists('flujos', 'idx_flujos_origen')) {
                $table->index('origen', 'idx_flujos_origen');
            }

            // For AsignarNuevosProspectosAFlujoJob
            if (! $this->indexExists('flujos', 'idx_flujos_auto_asignar')) {
                $table->index(['activo', 'auto_asignar_nuevos'], 'idx_flujos_auto_asignar');
            }
        });

        // =====================================================================
        // FLUJO_EJECUCION_ETAPAS - Critical for EjecutarNodosProgramados cron
        // =====================================================================
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            // For finding etapas to execute: estado = pending AND fecha_programada <= now
            if (! $this->indexExists('flujo_ejecucion_etapas', 'idx_etapas_programadas')) {
                $table->index(['estado', 'fecha_programada'], 'idx_etapas_programadas');
            }

            // For finding etapas by ejecucion + estado
            if (! $this->indexExists('flujo_ejecucion_etapas', 'idx_etapas_ejecucion_estado')) {
                $table->index(['flujo_ejecucion_id', 'estado'], 'idx_etapas_ejecucion_estado');
            }
        });

        // =====================================================================
        // PROSPECTO_EN_FLUJO - Critical table (300k+ rows)
        // =====================================================================
        Schema::table('prospecto_en_flujo', function (Blueprint $table) {
            // For existence checks: whereDoesntHave in AsignarNuevosProspectosAFlujoJob
            if (! $this->indexExists('prospecto_en_flujo', 'idx_pef_flujo_prospecto')) {
                $table->index(['flujo_id', 'prospecto_id'], 'idx_pef_flujo_prospecto');
            }

            // For filtering by estado
            if (! $this->indexExists('prospecto_en_flujo', 'idx_pef_flujo_estado')) {
                $table->index(['flujo_id', 'estado'], 'idx_pef_flujo_estado');
            }
        });

        // =====================================================================
        // ENVIOS - Additional composite index
        // =====================================================================
        Schema::table('envios', function (Blueprint $table) {
            // For stats queries: etapa + estado
            if (! $this->indexExists('envios', 'idx_envios_etapa_estado')) {
                $table->index(['flujo_ejecucion_etapa_id', 'estado'], 'idx_envios_etapa_estado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            $table->dropIndex('idx_importaciones_origen');
            $table->dropIndex('idx_importaciones_lote_origen');
        });

        Schema::table('flujos', function (Blueprint $table) {
            $table->dropIndex('idx_flujos_origen');
            $table->dropIndex('idx_flujos_auto_asignar');
        });

        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->dropIndex('idx_etapas_programadas');
            $table->dropIndex('idx_etapas_ejecucion_estado');
        });

        Schema::table('prospecto_en_flujo', function (Blueprint $table) {
            $table->dropIndex('idx_pef_flujo_prospecto');
            $table->dropIndex('idx_pef_flujo_estado');
        });

        Schema::table('envios', function (Blueprint $table) {
            $table->dropIndex('idx_envios_etapa_estado');
        });
    }

    /**
     * Check if an index already exists.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $indexName]
        ) !== null;
    }
};
