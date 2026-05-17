<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Advanced performance indexes: BRIN, Partial, and Covering indexes.
 *
 * Strategy:
 * - BRIN: For large time-series tables (envios, email_aperturas, email_clicks)
 *   where range queries on created_at dominate. ~1000x smaller than B-tree.
 * - Partial: For hot-path queries that only care about a tiny subset of rows
 *   (active executions, executing stages). Index stays tiny as rows transition.
 * - Covering (INCLUDE): Enables index-only scans on the most repeated
 *   aggregation pattern (envío stats by etapa + estado + canal).
 *
 * All indexes created CONCURRENTLY to avoid locking production tables.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // =================================================================
        // BRIN INDEXES — Time-series range queries (Dashboard, Métricas)
        // =================================================================

        // envios.created_at — MetricasService::getResumenGeneral, getTendencias
        // DashboardController::getTasaEntrega, getEnviosPorDia
        if (! $this->indexExists('envios', 'idx_envios_created_at_brin')) {
            DB::statement('CREATE INDEX CONCURRENTLY idx_envios_created_at_brin ON envios USING brin (created_at)');
        }

        // email_aperturas.created_at — MetricasService aperturas por día/hora
        if (! $this->indexExists('email_aperturas', 'idx_email_aperturas_created_at_brin')) {
            DB::statement('CREATE INDEX CONCURRENTLY idx_email_aperturas_created_at_brin ON email_aperturas USING brin (created_at)');
        }

        // email_clicks.created_at — MetricasService clicks por día
        if (! $this->indexExists('email_clicks', 'idx_email_clicks_created_at_brin')) {
            DB::statement('CREATE INDEX CONCURRENTLY idx_email_clicks_created_at_brin ON email_clicks USING brin (created_at)');
        }

        // envios.fecha_enviado — DashboardController::getEnviosPorDia, getTasaEntrega
        if (! $this->indexExists('envios', 'idx_envios_fecha_enviado_brin')) {
            DB::statement('CREATE INDEX CONCURRENTLY idx_envios_fecha_enviado_brin ON envios USING brin (fecha_enviado)');
        }

        // =================================================================
        // PARTIAL INDEXES — Hot-path cron queries (every minute)
        // =================================================================

        // EjecutarNodosProgramados::scopeConNodosProgramados
        // Only indexes the ~5% of rows that are active and ready to execute
        if (! $this->indexExists('flujo_ejecuciones', 'idx_ejecuciones_programadas_activas')) {
            DB::statement("
                CREATE INDEX CONCURRENTLY idx_ejecuciones_programadas_activas
                ON flujo_ejecuciones (fecha_proximo_nodo)
                WHERE estado = 'in_progress' AND proximo_nodo IS NOT NULL
            ");
        }

        // EjecutarNodosProgramados::verificarEtapasEjecutando
        // Only indexes stages currently in 'executing' state (tiny subset)
        if (! $this->indexExists('flujo_ejecucion_etapas', 'idx_etapas_executing_partial')) {
            DB::statement("
                CREATE INDEX CONCURRENTLY idx_etapas_executing_partial
                ON flujo_ejecucion_etapas (flujo_ejecucion_id)
                WHERE estado = 'executing'
            ");
        }

        // RecoverStuckEtapas — finds executing stages older than threshold
        // Reuses idx_etapas_executing_partial above (same WHERE clause)

        // =================================================================
        // COVERING INDEX — Index-only scans for envío stats
        // =================================================================

        // Most repeated aggregation: stats by etapa grouped by estado + canal
        // Used in: FlujoController::show, RecoverStuckEtapas, FlowExecutionViewer
        // INCLUDE(canal) avoids heap fetch for the canal column
        if (! $this->indexExists('envios', 'idx_envios_etapa_estado_covering')) {
            DB::statement('
                CREATE INDEX CONCURRENTLY idx_envios_etapa_estado_covering
                ON envios (flujo_ejecucion_etapa_id, estado)
                INCLUDE (canal)
            ');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_envios_created_at_brin');
        DB::statement('DROP INDEX IF EXISTS idx_email_aperturas_created_at_brin');
        DB::statement('DROP INDEX IF EXISTS idx_email_clicks_created_at_brin');
        DB::statement('DROP INDEX IF EXISTS idx_envios_fecha_enviado_brin');
        DB::statement('DROP INDEX IF EXISTS idx_ejecuciones_programadas_activas');
        DB::statement('DROP INDEX IF EXISTS idx_etapas_executing_partial');
        DB::statement('DROP INDEX IF EXISTS idx_envios_etapa_estado_covering');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $indexName]
        ) !== null;
    }
};
