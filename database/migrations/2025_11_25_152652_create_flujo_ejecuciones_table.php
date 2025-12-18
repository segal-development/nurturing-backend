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
        Schema::create('flujo_ejecuciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flujo_id')->constrained('flujos')->onDelete('cascade');
            $table->foreignId('prospecto_id')->constrained('prospectos')->onDelete('cascade');
            $table->enum('estado', ['pendiente', 'en_progreso', 'completado', 'fallido'])->default('pendiente');
            $table->string('nodo_actual')->nullable(); // ID del nodo actual
            $table->timestamp('fecha_inicio')->nullable();
            $table->timestamp('fecha_fin')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['flujo_id', 'prospecto_id']);
            $table->index(['estado', 'fecha_inicio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flujo_ejecuciones');
    }
};
