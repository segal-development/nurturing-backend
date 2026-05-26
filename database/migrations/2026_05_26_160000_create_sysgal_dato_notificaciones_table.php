<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de avisos enviados a SYSGAL por datos de contacto malos (email inválido / sin email).
 * Sirve para el modo "solo nuevos": no re-avisar al mismo prospecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sysgal_dato_notificaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prospecto_id')->unique();
            $table->string('motivo'); // sin_email | email_invalido
            $table->string('email_malo')->nullable();
            $table->timestamp('notificado_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sysgal_dato_notificaciones');
    }
};
