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
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->boolean('ejecutado')->default(false)->after('estado');

            $table->index('ejecutado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->dropIndex(['ejecutado']);
            $table->dropColumn('ejecutado');
        });
    }
};
