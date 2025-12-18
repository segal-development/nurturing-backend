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
        Schema::create('flujo_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flujo_ejecucion_id')->constrained('flujo_ejecuciones')->onDelete('cascade');
            $table->string('etapa_id')->nullable(); // ID del nodo/etapa
            $table->string('accion'); // email_enviado, sms_enviado, condicion_evaluada_si, etc
            $table->enum('resultado', ['exitoso', 'fallido', 'pendiente'])->default('pendiente');
            $table->text('mensaje')->nullable();
            $table->timestamp('fecha')->useCurrent();
            $table->timestamps();

            $table->index(['flujo_ejecucion_id', 'fecha']);
            $table->index(['accion', 'resultado']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flujo_logs');
    }
};
