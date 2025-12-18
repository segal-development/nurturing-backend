<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospecto_id')->constrained('prospectos')->onDelete('cascade');
            $table->foreignId('flujo_id')->constrained('flujos')->onDelete('cascade');
            $table->foreignId('etapa_flujo_id')->constrained('etapas_flujo')->onDelete('cascade');
            $table->foreignId('plantilla_mensaje_id')->nullable()->constrained('plantillas_mensaje')->onDelete('set null');
            $table->foreignId('prospecto_en_flujo_id')->constrained('prospecto_en_flujo')->onDelete('cascade');
            $table->string('asunto')->nullable();
            $table->text('contenido_enviado');
            $table->enum('canal', ['email', 'sms', 'whatsapp'])->default('email')->index();
            $table->string('destinatario');
            $table->enum('estado', ['pendiente', 'enviado', 'fallido', 'abierto', 'clickeado'])->default('pendiente')->index();
            $table->timestamp('fecha_programada')->index();
            $table->timestamp('fecha_enviado')->nullable()->index();
            $table->timestamp('fecha_abierto')->nullable();
            $table->timestamp('fecha_clickeado')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['prospecto_id', 'estado']);
            $table->index(['etapa_flujo_id', 'estado']);
            $table->index(['fecha_programada', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios');
    }
};
