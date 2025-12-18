<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ofertas_infocom', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->text('contenido');
            $table->date('fecha_inicio')->nullable()->index();
            $table->date('fecha_fin')->nullable()->index();
            $table->boolean('activo')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['fecha_inicio', 'fecha_fin', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ofertas_infocom');
    }
};
