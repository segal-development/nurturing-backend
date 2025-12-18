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
        Schema::create('flujo_ejecucion_condiciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flujo_ejecucion_id')->constrained('flujo_ejecuciones')->onDelete('cascade');
            $table->string('condition_node_id'); // ID del nodo condicional
            $table->string('check_param'); // 'Views', 'Clicks', 'Bounces'
            $table->string('check_operator'); // '>', '==', '>=', 'in'
            $table->string('check_value'); // '0', '1', 'abc,def'
            $table->dateTime('fecha_verificacion')->nullable();
            $table->enum('resultado', ['yes', 'no'])->nullable();
            $table->json('response_athenacampaign')->nullable();
            $table->integer('check_result_value')->nullable(); // Valor real obtenido
            $table->timestamps();

            $table->index(['flujo_ejecucion_id', 'condition_node_id'], 'idx_ejecucion_condicion_node');
            $table->index('fecha_verificacion', 'idx_fecha_verificacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flujo_ejecucion_condiciones');
    }
};
