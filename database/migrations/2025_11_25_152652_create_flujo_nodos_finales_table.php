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
        Schema::create('flujo_nodos_finales', function (Blueprint $table) {
            $table->string('id')->primary(); // end-123
            $table->foreignId('flujo_id')->constrained('flujos')->onDelete('cascade');
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('flujo_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flujo_nodos_finales');
    }
};
