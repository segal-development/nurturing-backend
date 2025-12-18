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
            // Eliminar prospecto_id individual (ahora será un array)
            $table->dropForeign(['prospecto_id']);
            $table->dropColumn('prospecto_id');

            // Agregar nuevos campos
            $table->string('origen_id')->after('flujo_id');
            $table->json('prospectos_ids')->after('origen_id'); // Array de IDs
            $table->dateTime('fecha_inicio_programada')->after('prospectos_ids');
            $table->dateTime('fecha_inicio_real')->nullable()->after('fecha_inicio_programada');
            $table->renameColumn('fecha_inicio', 'fecha_fin_temp');

            // Actualizar enum de estados
            $table->dropColumn('estado');
            $table->enum('estado', ['pending', 'in_progress', 'completed', 'failed', 'paused'])
                ->default('pending')
                ->after('origen_id');

            // Agregar config
            $table->json('config')->nullable()->after('prospectos_ids');

            // Renombrar campos
            $table->dropColumn('fecha_fin_temp');
            $table->dropColumn('nodo_actual');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->dropColumn([
                'origen_id',
                'prospectos_ids',
                'fecha_inicio_programada',
                'fecha_inicio_real',
                'config',
            ]);

            $table->foreignId('prospecto_id')->constrained('prospectos')->onDelete('cascade');
            $table->string('nodo_actual')->nullable();
        });
    }
};
