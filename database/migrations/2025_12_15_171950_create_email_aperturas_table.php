<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_aperturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->constrained('envios')->onDelete('cascade');
            $table->foreignId('prospecto_id')->constrained('prospectos')->onDelete('cascade');
            $table->string('ip_address', 45)->nullable(); // IPv6 compatible
            $table->string('user_agent', 500)->nullable();
            $table->string('dispositivo', 50)->nullable(); // desktop, mobile, tablet
            $table->string('cliente_email', 100)->nullable(); // Gmail, Outlook, Apple Mail, etc.
            $table->timestamp('fecha_apertura');
            $table->timestamps();

            // Índices para consultas frecuentes
            $table->index('envio_id');
            $table->index('prospecto_id');
            $table->index('fecha_apertura');
            $table->index(['envio_id', 'fecha_apertura']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_aperturas');
    }
};
