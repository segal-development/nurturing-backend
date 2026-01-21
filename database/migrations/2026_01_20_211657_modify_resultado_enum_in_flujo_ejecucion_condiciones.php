<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Modifica el enum 'resultado' para incluir 'mixed' (cuando hay prospectos en ambas ramas).
 */
return new class extends Migration
{
    public function up(): void
    {
        // En PostgreSQL, necesitamos recrear el constraint
        DB::statement("ALTER TABLE flujo_ejecucion_condiciones DROP CONSTRAINT IF EXISTS flujo_ejecucion_condiciones_resultado_check");
        DB::statement("ALTER TABLE flujo_ejecucion_condiciones ALTER COLUMN resultado TYPE VARCHAR(10)");
        DB::statement("ALTER TABLE flujo_ejecucion_condiciones ADD CONSTRAINT flujo_ejecucion_condiciones_resultado_check CHECK (resultado IN ('yes', 'no', 'mixed'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE flujo_ejecucion_condiciones DROP CONSTRAINT IF EXISTS flujo_ejecucion_condiciones_resultado_check");
        DB::statement("ALTER TABLE flujo_ejecucion_condiciones ALTER COLUMN resultado TYPE VARCHAR(10)");
        DB::statement("ALTER TABLE flujo_ejecucion_condiciones ADD CONSTRAINT flujo_ejecucion_condiciones_resultado_check CHECK (resultado IN ('yes', 'no'))");
    }
};
