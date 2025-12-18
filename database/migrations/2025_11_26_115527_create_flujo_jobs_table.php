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
        Schema::create('flujo_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flujo_ejecucion_id')->constrained('flujo_ejecuciones')->onDelete('cascade');
            $table->string('job_type'); // 'enviar_etapa', 'verificar_condicion'
            $table->string('job_id')->nullable(); // Laravel job ID para rastreo
            $table->json('job_data'); // Payload del job
            $table->enum('estado', ['queued', 'processing', 'completed', 'failed', 'retried'])->default('queued');
            $table->dateTime('fecha_queued');
            $table->dateTime('fecha_procesado')->nullable();
            $table->text('error_details')->nullable();
            $table->integer('intentos')->default(0);
            $table->timestamps();

            $table->index(['flujo_ejecucion_id', 'job_type']);
            $table->index(['estado', 'fecha_queued']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flujo_jobs');
    }
};
