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
            // Eliminar el constraint único que impedía crear múltiples flujos
            // para la misma combinación de tipo_prospecto_id + origen + canal_envio
            $table->dropUnique('flujos_unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujos', function (Blueprint $table) {
            // Recrear el constraint único si se hace rollback
            $table->unique(['tipo_prospecto_id', 'origen', 'canal_envio'], 'flujos_unique_index');
        });
    }
};
