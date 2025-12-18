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
        Schema::create('importaciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_archivo');
            $table->string('ruta_archivo');
            $table->string('origen')->index();
            $table->integer('total_registros')->default(0);
            $table->integer('registros_exitosos')->default(0);
            $table->integer('registros_fallidos')->default(0);
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('estado', ['procesando', 'completado', 'fallido'])->default('procesando')->index();
            $table->timestamp('fecha_importacion');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['estado', 'fecha_importacion']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('importaciones');
    }
};
