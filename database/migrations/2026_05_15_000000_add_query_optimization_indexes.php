<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Performance indexes for query optimization.
 *
 * These indexes address N+1 and slow lookup patterns:
 * - prospectos.importacion_id: Batch loading for EmailProviderResolver IC checks
 * - envios.tracking_token: Pixel tracking lookups (O(n) → O(1))
 * - envios.prospecto_id: Prospecto email history lookups (standalone, complements composites)
 *
 * All indexes are created CONCURRENTLY to avoid locking tables in production.
 * Uses $withinTransaction = false because CONCURRENTLY cannot run inside a transaction.
 *
 * @see SDD Change: query-optimization
 */
return new class extends Migration
{
    /**
     * Disable transactions - required for CREATE INDEX CONCURRENTLY.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // Index for EmailProviderResolver IC prospect batch loading
        // Used in: EnviarEtapaJob eager loads prospectos with importacion.lote
        if (! $this->indexExists('prospectos', 'idx_prospectos_importacion_id')) {
            DB::statement('CREATE INDEX CONCURRENTLY idx_prospectos_importacion_id ON prospectos (importacion_id)');
        }

        // Index for tracking pixel lookups
        // Used in: TrackingController finding envio by tracking_token
        if (! $this->indexExists('envios', 'idx_envios_tracking_token')) {
            DB::statement('CREATE INDEX CONCURRENTLY idx_envios_tracking_token ON envios (tracking_token)');
        }

        // NOTE: email_aperturas already has index on envio_id (created in original table migration)
        // The tracking_token lives in envios table, not email_aperturas
        // No additional index needed here - lookups go through envio_id FK

        // Standalone index for prospecto email history lookups
        // Composites exist but standalone needed for prospecto-only queries
        if (! $this->indexExists('envios', 'idx_envios_prospecto_id')) {
            DB::statement('CREATE INDEX CONCURRENTLY idx_envios_prospecto_id ON envios (prospecto_id)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_prospectos_importacion_id');
        DB::statement('DROP INDEX IF EXISTS idx_envios_tracking_token');
        DB::statement('DROP INDEX IF EXISTS idx_envios_prospecto_id');
    }

    /**
     * Check if an index already exists (idempotent check).
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $indexName]
        ) !== null;
    }
};
