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
        Schema::table('flujos', function (Blueprint $table) {
            $table->boolean('es_perpetuo')->default(false)
                ->after('auto_asignar_nuevos')
                ->comment('Si true, el flujo queda en estado waiting en vez de completed cuando no hay prospectos pendientes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujos', function (Blueprint $table) {
            $table->dropColumn('es_perpetuo');
        });
    }
};
