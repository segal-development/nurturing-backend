<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds 'pendiente' to the estado enum for background processing support.
     */
    public function up(): void
    {
        // PostgreSQL: Drop and recreate the check constraint with the new value
        DB::statement("ALTER TABLE importaciones DROP CONSTRAINT IF EXISTS importaciones_estado_check");
        DB::statement("ALTER TABLE importaciones ADD CONSTRAINT importaciones_estado_check CHECK (estado IN ('pendiente', 'procesando', 'completado', 'fallido'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original constraint (without 'pendiente')
        DB::statement("ALTER TABLE importaciones DROP CONSTRAINT IF EXISTS importaciones_estado_check");
        DB::statement("ALTER TABLE importaciones ADD CONSTRAINT importaciones_estado_check CHECK (estado IN ('procesando', 'completado', 'fallido'))");
    }
};
