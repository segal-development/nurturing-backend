<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CONSTRAINT MIGRATION - Run ONLY after backfill migration is complete and verified.
 *
 * This migration adds NOT NULL constraints to flujo_ejecucion_etapas.
 * It includes a safety check to prevent running if NULL values still exist.
 *
 * CAUTION: This will FAIL if any NULL values remain in node_id.
 *
 * Run manually with:
 * php artisan migrate --path=database/migrations/2026_05_14_190001_add_not_null_constraints_to_etapas.php
 */
return new class extends Migration
{
    public function up(): void
    {
        // Safety check: verify no NULLs remain before adding constraints
        $nullCount = DB::table('flujo_ejecucion_etapas')
            ->whereNull('node_id')
            ->count();

        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Cannot add NOT NULL constraints: {$nullCount} rows still have NULL node_id. ".
                'Run backfill migration first: php artisan migrate --path=database/migrations/2026_05_14_190000_backfill_etapa_required_fields.php'
            );
        }

        // Note: fecha_programada was already NOT NULL in original migration
        // Only node_id needs constraint change if it was made nullable at some point

        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            // In MySQL/MariaDB, changing nullable requires specifying the full column definition
            // Using change() with nullable(false) enforces NOT NULL
            $table->string('node_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->string('node_id')->nullable()->change();
        });
    }
};
