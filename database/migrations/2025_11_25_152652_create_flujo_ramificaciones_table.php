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
        Schema::create('flujo_ramificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flujo_id')->constrained('flujos')->onDelete('cascade');
            $table->string('edge_id')->unique();
            $table->string('source_node_id'); // ID del nodo origen
            $table->string('target_node_id'); // ID del nodo destino
            $table->string('source_handle')->nullable();
            $table->string('target_handle')->nullable();
            $table->enum('condition_branch', ['yes', 'no'])->nullable(); // null si no es condicional
            $table->timestamps();

            $table->index(['flujo_id', 'source_node_id']);
            $table->index(['flujo_id', 'target_node_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flujo_ramificaciones');
    }
};
