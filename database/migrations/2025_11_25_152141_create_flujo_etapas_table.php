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
        Schema::create('flujo_etapas', function (Blueprint $table) {
            $table->string('id')->primary(); // stage-abc123
            $table->foreignId('flujo_id')->constrained('flujos')->onDelete('cascade');
            $table->integer('orden');
            $table->string('label');
            $table->integer('dia_envio')->default(0);
            $table->enum('tipo_mensaje', ['email', 'sms', 'ambos']);
            $table->text('plantilla_mensaje');
            $table->timestamp('fecha_inicio_personalizada')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['flujo_id', 'orden']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flujo_etapas');
    }
};
