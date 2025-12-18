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
            $table->string('nodo_actual')->nullable()->after('estado');
            $table->string('proximo_nodo')->nullable()->after('nodo_actual');
            $table->dateTime('fecha_proximo_nodo')->nullable()->after('proximo_nodo');

            $table->index('nodo_actual');
            $table->index('fecha_proximo_nodo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->dropIndex(['nodo_actual']);
            $table->dropIndex(['fecha_proximo_nodo']);
            $table->dropColumn(['nodo_actual', 'proximo_nodo', 'fecha_proximo_nodo']);
        });
    }
};
