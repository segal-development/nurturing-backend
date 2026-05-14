<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DATA MIGRATION - Run manually after verifying Phase 2 code works in production.
 *
 * This migration backfills NULL values in flujo_ejecucion_etapas before
 * adding NOT NULL constraints. It handles legacy data that may have been
 * created before validation was added.
 *
 * Run manually with:
 * php artisan migrate --path=database/migrations/2026_05_14_190000_backfill_etapa_required_fields.php
 *
 * Or via artisan command:
 * php artisan nurturing:cleanup-etapas
 */
return new class extends Migration
{
    public function up(): void
    {
        // Count current NULL values for logging
        $nullNodeIdCount = DB::table('flujo_ejecucion_etapas')
            ->whereNull('node_id')
            ->count();

        $nullFechaCount = DB::table('flujo_ejecucion_etapas')
            ->whereNull('fecha_programada')
            ->count();

        Log::info('Backfill migration starting', [
            'null_node_id_count' => $nullNodeIdCount,
            'null_fecha_programada_count' => $nullFechaCount,
        ]);

        // Backfill node_id where NULL
        // Generate a unique identifier using the row ID for traceability
        $updatedNodeId = DB::table('flujo_ejecucion_etapas')
            ->whereNull('node_id')
            ->update(['node_id' => DB::raw("CONCAT('legacy-unknown-', id)")]);

        // Backfill fecha_programada where NULL - use created_at as fallback
        $updatedFecha = DB::table('flujo_ejecucion_etapas')
            ->whereNull('fecha_programada')
            ->update(['fecha_programada' => DB::raw('created_at')]);

        Log::info('Backfill migration completed for flujo_ejecucion_etapas', [
            'node_id_backfilled' => $updatedNodeId,
            'fecha_programada_backfilled' => $updatedFecha,
        ]);
    }

    /**
     * No rollback - data backfill is not reversible in a meaningful way.
     * The 'legacy-unknown-*' prefix allows identification of backfilled records.
     */
    public function down(): void
    {
        // Intentionally empty - cannot restore NULL values safely
        Log::warning('Backfill migration rollback: no action taken (data backfill is not reversible)');
    }
};
