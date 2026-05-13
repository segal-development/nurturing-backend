<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This adds a unique constraint to prevent duplicate etapas for the same
     * node_id within a flujo_ejecucion. This fixes race conditions where
     * multiple jobs (CatchUpProspectosJob, EjecutarNodosProgramados, etc.)
     * could create duplicate etapas.
     */
    public function up(): void
    {
        // First, clean up existing duplicates (keep the oldest one)
        DB::statement("
            DELETE FROM flujo_ejecucion_etapas 
            WHERE id NOT IN (
                SELECT MIN(id) 
                FROM flujo_ejecucion_etapas 
                GROUP BY flujo_ejecucion_id, node_id
            )
        ");

        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->unique(['flujo_ejecucion_id', 'node_id'], 'unique_ejecucion_node');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->dropUnique('unique_ejecucion_node');
        });
    }
};
