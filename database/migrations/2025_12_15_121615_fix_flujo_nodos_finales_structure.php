<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Crear tabla temporal con la estructura correcta
        Schema::create('flujo_nodos_finales_new', function (Blueprint $table) {
            $table->id(); // ID auto-incremental como primary key
            $table->foreignId('flujo_id')->constrained('flujos')->cascadeOnDelete();
            $table->string('node_id'); // El ID del nodo visual (ej: "end-1")
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            // Constraint único: un flujo no puede tener dos nodos finales con el mismo node_id
            $table->unique(['flujo_id', 'node_id'], 'flujo_nodos_finales_unique');
            $table->index('flujo_id');
        });

        // 2. Copiar datos existentes (renombrando 'id' a 'node_id')
        DB::statement('
            INSERT INTO flujo_nodos_finales_new (flujo_id, node_id, label, description, created_at, updated_at)
            SELECT flujo_id, id as node_id, label, description, created_at, updated_at
            FROM flujo_nodos_finales
        ');

        // 3. Eliminar tabla antigua
        Schema::dropIfExists('flujo_nodos_finales');

        // 4. Renombrar tabla nueva
        Schema::rename('flujo_nodos_finales_new', 'flujo_nodos_finales');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir a la estructura anterior
        Schema::create('flujo_nodos_finales_old', function (Blueprint $table) {
            $table->string('id')->primary(); // node_id como primary key
            $table->foreignId('flujo_id')->constrained('flujos')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('flujo_id');
        });

        // Copiar datos (renombrando 'node_id' a 'id')
        DB::statement('
            INSERT INTO flujo_nodos_finales_old (id, flujo_id, label, description, created_at, updated_at)
            SELECT node_id as id, flujo_id, label, description, created_at, updated_at
            FROM flujo_nodos_finales
        ');

        Schema::dropIfExists('flujo_nodos_finales');
        Schema::rename('flujo_nodos_finales_old', 'flujo_nodos_finales');
    }
};
