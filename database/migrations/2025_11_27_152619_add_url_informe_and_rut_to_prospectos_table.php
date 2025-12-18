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
        Schema::table('prospectos', function (Blueprint $table) {
            // Agregar campo para URL del informe (opcional)
            $table->string('url_informe')->nullable()->after('telefono');

            // Agregar campo RUT si no existe (ya existe pero por si acaso)
            if (! Schema::hasColumn('prospectos', 'rut')) {
                $table->string('rut')->nullable()->after('nombre');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospectos', function (Blueprint $table) {
            $table->dropColumn('url_informe');
            // No eliminamos rut porque puede existir de antes
        });
    }
};
