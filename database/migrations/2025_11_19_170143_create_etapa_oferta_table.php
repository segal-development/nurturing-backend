<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapa_oferta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etapa_flujo_id')->constrained('etapas_flujo')->onDelete('cascade');
            $table->foreignId('oferta_infocom_id')->constrained('ofertas_infocom')->onDelete('cascade');
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();

            $table->unique(['etapa_flujo_id', 'oferta_infocom_id']);
            $table->index(['etapa_flujo_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapa_oferta');
    }
};
