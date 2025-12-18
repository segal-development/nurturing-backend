<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospectos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_id')->nullable()->constrained('importaciones')->onDelete('set null');
            $table->string('nombre');
            $table->string('email')->unique();
            $table->string('telefono')->nullable();
            $table->foreignId('tipo_prospecto_id')->constrained('tipo_prospecto')->onDelete('restrict');
            $table->enum('estado', ['activo', 'inactivo', 'convertido'])->default('activo')->index();
            $table->unsignedBigInteger('monto_deuda')->default(0)->index();
            $table->timestamp('fecha_ultimo_contacto')->nullable();
            $table->integer('fila_excel')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['importacion_id', 'created_at']);
            $table->index(['tipo_prospecto_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospectos');
    }
};
