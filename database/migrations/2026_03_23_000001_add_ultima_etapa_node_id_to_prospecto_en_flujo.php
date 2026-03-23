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
        Schema::table('prospecto_en_flujo', function (Blueprint $table) {
            $table->string('ultima_etapa_node_id', 100)->nullable()
                ->after('estado')
                ->comment('Last completed stage node_id for catch-up tracking');
            $table->index(['flujo_id', 'ultima_etapa_node_id'], 'pef_flujo_ultima_etapa_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospecto_en_flujo', function (Blueprint $table) {
            $table->dropIndex('pef_flujo_ultima_etapa_idx');
            $table->dropColumn('ultima_etapa_node_id');
        });
    }
};
