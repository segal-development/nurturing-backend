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
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->boolean('es_perpetuo')->default(false)
                ->after('estado')
                ->comment('If true, new prospects are added to this execution instead of creating new ones');
            $table->index(['flujo_id', 'es_perpetuo'], 'fe_flujo_perpetuo_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->dropIndex('fe_flujo_perpetuo_idx');
            $table->dropColumn('es_perpetuo');
        });
    }
};
