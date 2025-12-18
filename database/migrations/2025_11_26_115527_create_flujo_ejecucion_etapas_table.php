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
        Schema::create('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flujo_ejecucion_id')->constrained('flujo_ejecuciones')->onDelete('cascade');
            $table->unsignedBigInteger('etapa_id')->nullable(); // ID de la etapa en flujo_etapas
            $table->string('node_id'); // ID del nodo en ReactFlow (ej: "stage-abc123")
            $table->dateTime('fecha_programada');
            $table->dateTime('fecha_ejecucion')->nullable();
            $table->enum('estado', ['pending', 'executing', 'completed', 'failed'])->default('pending');
            $table->unsignedBigInteger('message_id')->nullable(); // messageID de AthenaCampaign
            $table->json('response_athenacampaign')->nullable(); // Respuesta completa de la API
            $table->text('error_mensaje')->nullable();
            $table->timestamps();

            $table->index(['flujo_ejecucion_id', 'estado']);
            $table->index('fecha_programada');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flujo_ejecucion_etapas');
    }
};
