<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Agrega columna prospectos_count a flujo_ejecuciones y flujo_ejecucion_etapas
     * para evitar cargar el JSON completo de prospectos_ids solo para contar.
     * Esto previene memory exhaustion con 300k+ prospectos.
     */
    public function up(): void
    {
        // 1. Agregar a flujo_ejecuciones
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->unsignedInteger('prospectos_count')->default(0)->after('prospectos_ids');
        });

        // Backfill flujo_ejecuciones
        DB::statement('
            UPDATE flujo_ejecuciones 
            SET prospectos_count = JSON_LENGTH(prospectos_ids) 
            WHERE prospectos_ids IS NOT NULL
        ');

        // 2. Agregar a flujo_ejecucion_etapas
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->unsignedInteger('prospectos_count')->default(0)->after('prospectos_ids');
        });

        // Backfill flujo_ejecucion_etapas
        DB::statement('
            UPDATE flujo_ejecucion_etapas 
            SET prospectos_count = JSON_LENGTH(prospectos_ids) 
            WHERE prospectos_ids IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->dropColumn('prospectos_count');
        });

        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->dropColumn('prospectos_count');
        });
    }
};
