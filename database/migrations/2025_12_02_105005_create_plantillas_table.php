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
        Schema::create('plantillas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('descripcion', 500)->nullable();
            $table->enum('tipo', ['sms', 'email']);
            $table->longText('contenido')->nullable(); // Para SMS y otros datos
            $table->string('asunto', 200)->nullable(); // Para Email
            $table->json('componentes')->nullable(); // Para Email (array de componentes)
            $table->boolean('activo')->default(true);
            $table->timestamps();

            // Índices
            $table->index('tipo');
            $table->index('activo');
            $table->index(['tipo', 'activo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plantillas');
    }
};
