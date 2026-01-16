<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega el estado 'archivado' al ENUM de prospectos.
 *
 * Este estado se usa para prospectos que no han tenido interacción
 * en los últimos 3 meses y son archivados por el job de limpieza mensual.
 * 
 * NOTA: Esta migración es idempotente - puede correr múltiples veces sin error.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Verificar si el tipo ENUM existe
        $enumExists = DB::select("
            SELECT 1 FROM pg_type WHERE typname = 'prospectos_estado'
        ");

        if ($enumExists) {
            // Si existe el tipo ENUM, agregamos el valor (IF NOT EXISTS es seguro)
            DB::statement("ALTER TYPE prospectos_estado ADD VALUE IF NOT EXISTS 'archivado'");
        }
        // Si no existe el ENUM, Laravel usa CHECK constraint y el valor se maneja a nivel de aplicación
        // No modificamos la estructura de la tabla para evitar problemas
    }

    public function down(): void
    {
        // PostgreSQL no permite eliminar valores de un ENUM fácilmente.
        // El valor 'archivado' quedará en el tipo pero no se usará.
    }
};
