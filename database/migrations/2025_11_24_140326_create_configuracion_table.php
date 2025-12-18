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
        Schema::create('configuracion', function (Blueprint $table) {
            $table->id();

            // Costos (obligatorios)
            $table->decimal('email_costo', 10, 2)->default(1.00);
            $table->decimal('sms_costo', 10, 2)->default(11.00);

            // Límites (opcionales)
            $table->integer('max_prospectos_por_flujo')->nullable();
            $table->integer('max_emails_por_dia')->nullable();
            $table->integer('max_sms_por_dia')->nullable();
            $table->integer('reintentos_envio')->nullable()->default(3);

            // Notificaciones (opcionales)
            $table->boolean('notificar_flujo_completado')->nullable()->default(true);
            $table->boolean('notificar_errores_envio')->nullable()->default(true);
            $table->string('email_notificaciones')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuracion');
    }
};
