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
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre'); // "Carga masiva enero-diciembre 2025"
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('total_archivos')->default(0);
            $table->integer('total_registros')->default(0);
            $table->integer('registros_exitosos')->default(0);
            $table->integer('registros_fallidos')->default(0);
            $table->enum('estado', ['abierto', 'procesando', 'completado', 'fallido'])->default('abierto')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'estado']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};
