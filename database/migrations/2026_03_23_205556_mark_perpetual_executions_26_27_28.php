<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration to mark existing perpetual executions.
 *
 * Executions #26, #27, #28 are the perpetual executions for flujos 39, 42, 43.
 * These are the "main" executions where new prospects should be added
 * instead of creating new FlujoEjecucion records.
 */
return new class extends Migration
{
    /**
     * Perpetual execution IDs.
     * - #26: Flujo 39
     * - #27: Flujo 42
     * - #28: Flujo 43
     */
    private const PERPETUAL_EXECUTION_IDS = [26, 27, 28];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('flujo_ejecuciones')
            ->whereIn('id', self::PERPETUAL_EXECUTION_IDS)
            ->update(['es_perpetuo' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('flujo_ejecuciones')
            ->whereIn('id', self::PERPETUAL_EXECUTION_IDS)
            ->update(['es_perpetuo' => false]);
    }
};
