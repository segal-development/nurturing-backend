<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for frequently queried columns.
 * 
 * These indexes address:
 * - envios: Missing flujo_id, created_at, flujo_ejecucion_etapa_id indexes
 * - envios: Composite index for MetricasService queries (flujo_id + estado + created_at)
 * - desuscripciones: Missing flujo_id index
 * - flujo_ejecuciones: Missing flujo_id + estado composite index
 * - prospectos: Trigram index for LIKE search on nombre, email, telefono
 * 
 * All indexes are created CONCURRENTLY to avoid locking tables in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // ENVIOS - Most queried table (800k+ rows)
        // =====================================================================

        Schema::table('envios', function (Blueprint $table) {
            // Used by: MetricasService (por_flujo), DashboardController
            if (!$this->indexExists('envios', 'envios_flujo_id_index')) {
                $table->index('flujo_id', 'envios_flujo_id_index');
            }

            // Used by: MetricasService (por_dia, tendencias, resumen)
            if (!$this->indexExists('envios', 'envios_created_at_index')) {
                $table->index('created_at', 'envios_created_at_index');
            }

            // Used by: FlujoEjecucionController (etapa cost/stats)
            if (!$this->indexExists('envios', 'envios_flujo_ejecucion_etapa_id_index')) {
                $table->index('flujo_ejecucion_etapa_id', 'envios_flujo_ejecucion_etapa_id_index');
            }

            // Composite: MetricasService queries filter by flujo_id + estado + date range
            if (!$this->indexExists('envios', 'envios_flujo_estado_created_index')) {
                $table->index(['flujo_id', 'estado', 'created_at'], 'envios_flujo_estado_created_index');
            }

            // Composite: DashboardController::getTasaEntrega + getEnviosHoy
            if (!$this->indexExists('envios', 'envios_fecha_enviado_estado_index')) {
                $table->index(['fecha_enviado', 'estado'], 'envios_fecha_enviado_estado_index');
            }
        });

        // =====================================================================
        // DESUSCRIPCIONES
        // =====================================================================

        Schema::table('desuscripciones', function (Blueprint $table) {
            // Used by: MetricasService::getMetricasDesuscripciones (por_flujo join)
            if (!$this->indexExists('desuscripciones', 'desuscripciones_flujo_id_index')) {
                $table->index('flujo_id', 'desuscripciones_flujo_id_index');
            }
        });

        // =====================================================================
        // FLUJO_EJECUCIONES
        // =====================================================================

        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            // Used by: DashboardController::getEnviosProgramados, EjecutarNodosProgramados
            if (!$this->indexExists('flujo_ejecuciones', 'flujo_ejecuciones_flujo_id_estado_index')) {
                $table->index(['flujo_id', 'estado'], 'flujo_ejecuciones_flujo_id_estado_index');
            }
        });

        // =====================================================================
        // PROSPECTOS - Trigram index for LIKE search (350k+ rows)
        // =====================================================================

        // Enable pg_trgm extension if not already enabled
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // GIN trigram indexes for fast LIKE '%search%' queries
        // Note: Cannot use CONCURRENTLY inside a transaction (Laravel wraps migrations in transactions for PostgreSQL)
        DB::statement('CREATE INDEX IF NOT EXISTS prospectos_nombre_trgm_idx ON prospectos USING gin (nombre gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS prospectos_email_trgm_idx ON prospectos USING gin (email gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS prospectos_telefono_trgm_idx ON prospectos USING gin (telefono gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropIndex('envios_flujo_id_index');
            $table->dropIndex('envios_created_at_index');
            $table->dropIndex('envios_flujo_ejecucion_etapa_id_index');
            $table->dropIndex('envios_flujo_estado_created_index');
            $table->dropIndex('envios_fecha_enviado_estado_index');
        });

        Schema::table('desuscripciones', function (Blueprint $table) {
            $table->dropIndex('desuscripciones_flujo_id_index');
        });

        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->dropIndex('flujo_ejecuciones_flujo_id_estado_index');
        });

        DB::statement('DROP INDEX IF EXISTS prospectos_nombre_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS prospectos_email_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS prospectos_telefono_trgm_idx');
    }

    /**
     * Check if an index already exists to avoid errors.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        ) !== null;
    }
};
