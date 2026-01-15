<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        // PostgreSQL: modificar el tipo ENUM agregando el nuevo valor
        DB::statement("ALTER TYPE prospectos_estado ADD VALUE IF NOT EXISTS 'archivado'");
    }

    public function down(): void
    {
        // PostgreSQL no permite eliminar valores de un ENUM fácilmente.
        // El valor 'archivado' quedará en el tipo pero no se usará.
        // Para eliminarlo completamente habría que recrear el tipo.
    }
};
