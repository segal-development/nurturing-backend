<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospecto_en_flujo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospecto_id')->constrained('prospectos')->onDelete('cascade');
            $table->foreignId('flujo_id')->constrained('flujos')->onDelete('cascade');
            $table->foreignId('etapa_actual_id')->nullable()->constrained('etapas_flujo')->onDelete('set null');
            $table->timestamp('fecha_inicio');
            $table->timestamp('fecha_proxima_etapa')->nullable()->index();
            $table->boolean('completado')->default(false)->index();
            $table->boolean('cancelado')->default(false)->index();
            $table->timestamps();

            $table->index(['prospecto_id', 'flujo_id'], 'pef_prospecto_flujo_idx');
            $table->index(['flujo_id', 'completado', 'cancelado'], 'pef_flujo_estado_idx');
            $table->index(['fecha_proxima_etapa', 'completado', 'cancelado'], 'pef_fecha_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospecto_en_flujo');
    }
};
