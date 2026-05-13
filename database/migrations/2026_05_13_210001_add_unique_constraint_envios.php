<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add unique constraint to prevent duplicate envios.
     * 
     * This ensures only one envío per (prospecto_id, flujo_ejecucion_etapa_id, canal).
     * 
     * Note: MySQL allows multiple NULL values in a unique constraint, so envíos
     * without flujo_ejecucion_etapa_id (e.g., from simple Jobs) are not affected.
     */
    public function up(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->unique(
                ['prospecto_id', 'flujo_ejecucion_etapa_id', 'canal'],
                'envios_prospecto_etapa_canal_unique'
            );
        });
    }

    /**
     * Remove the unique constraint.
     */
    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropUnique('envios_prospecto_etapa_canal_unique');
        });
    }
};
