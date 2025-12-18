<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_mensaje', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('asunto')->nullable();
            $table->text('contenido');
            $table->enum('tipo_canal', ['email', 'sms', 'whatsapp'])->default('email')->index();
            $table->json('variables_disponibles')->nullable();
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();

            $table->index(['tipo_canal', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantillas_mensaje');
    }
};
