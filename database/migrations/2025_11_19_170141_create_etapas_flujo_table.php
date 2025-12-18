<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapas_flujo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flujo_id')->constrained('flujos')->onDelete('cascade');
            $table->string('nombre');
            $table->integer('dias_desde_inicio');
            $table->integer('orden')->default(0);
            $table->foreignId('plantilla_mensaje_id')->nullable()->constrained('plantillas_mensaje')->onDelete('set null');
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();

            $table->index(['flujo_id', 'orden']);
            $table->index(['flujo_id', 'dias_desde_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapas_flujo');
    }
};
