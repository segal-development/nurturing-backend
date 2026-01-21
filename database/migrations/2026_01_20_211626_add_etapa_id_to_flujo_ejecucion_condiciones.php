<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega etapa_id a flujo_ejecucion_condiciones para relacionar con la etapa de ejecución.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flujo_ejecucion_condiciones', function (Blueprint $table) {
            $table->foreignId('etapa_id')
                ->nullable()
                ->after('flujo_ejecucion_id')
                ->constrained('flujo_ejecucion_etapas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flujo_ejecucion_condiciones', function (Blueprint $table) {
            $table->dropForeign(['etapa_id']);
            $table->dropColumn('etapa_id');
        });
    }
};
