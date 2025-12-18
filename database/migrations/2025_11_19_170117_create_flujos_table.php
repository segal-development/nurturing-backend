<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flujos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_prospecto_id')->constrained('tipo_prospecto')->onDelete('restrict');
            $table->string('origen')->index();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->enum('canal_envio', ['email', 'sms', 'ambos'])->default('ambos');
            $table->boolean('activo')->default(true)->index();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index(['tipo_prospecto_id', 'origen']);
            $table->unique(['tipo_prospecto_id', 'origen', 'canal_envio'], 'flujos_unique_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flujos');
    }
};
