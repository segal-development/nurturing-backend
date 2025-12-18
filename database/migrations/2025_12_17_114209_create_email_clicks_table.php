<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla para registrar clicks en enlaces de emails
     * Permite tracking propio sin depender de AthenaCampaign
     */
    public function up(): void
    {
        Schema::create('email_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->constrained('envios')->onDelete('cascade');
            $table->foreignId('prospecto_id')->constrained('prospectos')->onDelete('cascade');
            $table->string('url_original', 2048); // URL original del enlace
            $table->string('url_id', 32)->nullable(); // ID único del enlace en el email
            $table->string('ip_address', 45)->nullable(); // IPv6 compatible
            $table->string('user_agent', 500)->nullable();
            $table->string('dispositivo', 50)->nullable(); // desktop, mobile, tablet
            $table->string('navegador', 100)->nullable(); // Chrome, Firefox, Safari, etc.
            $table->timestamp('fecha_click');
            $table->timestamps();

            // Índices para consultas frecuentes
            $table->index('envio_id');
            $table->index('prospecto_id');
            $table->index('fecha_click');
            $table->index(['envio_id', 'fecha_click']);
            $table->index('url_id');
        });

        // Agregar columna total_clicks a envios
        Schema::table('envios', function (Blueprint $table) {
            $table->unsignedInteger('total_clicks')->default(0)->after('total_aperturas');
        });
    }

    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropColumn('total_clicks');
        });

        Schema::dropIfExists('email_clicks');
    }
};
