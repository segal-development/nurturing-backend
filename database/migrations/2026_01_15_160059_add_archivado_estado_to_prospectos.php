<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el estado 'archivado' al ENUM de prospectos.
 *
 * Este estado se usa para prospectos que no han tenido interacción
 * en los últimos 3 meses y son archivados por el job de limpieza mensual.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel crea el ENUM con el nombre: {tabla}_{columna}
        // Primero verificamos si el tipo existe
        $enumExists = DB::select("
            SELECT 1 FROM pg_type WHERE typname = 'prospectos_estado'
        ");

        if ($enumExists) {
            // Si existe el tipo ENUM, agregamos el valor
            DB::statement("ALTER TYPE prospectos_estado ADD VALUE IF NOT EXISTS 'archivado'");
        } else {
            // Si no existe el ENUM (Laravel puede usar CHECK constraint en su lugar),
            // cambiamos la columna a VARCHAR y recreamos con los nuevos valores
            Schema::table('prospectos', function (Blueprint $table) {
                $table->string('estado_new')->default('activo');
            });

            DB::statement("UPDATE prospectos SET estado_new = estado::text");

            Schema::table('prospectos', function (Blueprint $table) {
                $table->dropColumn('estado');
            });

            Schema::table('prospectos', function (Blueprint $table) {
                $table->enum('estado', ['activo', 'inactivo', 'convertido', 'archivado'])
                    ->default('activo')
                    ->index();
            });

            DB::statement("UPDATE prospectos SET estado = estado_new");

            Schema::table('prospectos', function (Blueprint $table) {
                $table->dropColumn('estado_new');
            });
        }
    }

    public function down(): void
    {
        // PostgreSQL no permite eliminar valores de un ENUM fácilmente.
        // El valor 'archivado' quedará en el tipo pero no se usará.
    }
};
