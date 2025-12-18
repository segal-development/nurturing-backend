<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->foreignId('flujo_ejecucion_etapa_id')
                ->nullable()
                ->after('etapa_flujo_id')
                ->constrained('flujo_ejecucion_etapas')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropForeign(['flujo_ejecucion_etapa_id']);
            $table->dropColumn('flujo_ejecucion_etapa_id');
        });
    }
};
