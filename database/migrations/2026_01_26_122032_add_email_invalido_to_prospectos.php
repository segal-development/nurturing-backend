<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campo para marcar emails inválidos.
 * 
 * Emails se marcan como inválidos automáticamente cuando:
 * - No cumplen con RFC 2822 (formato inválido)
 * - Retornan error 554 (rechazado por servidor destino)
 * - Dominio no existe o está mal escrito
 * 
 * Prospectos con email_invalido=true son excluidos de futuros envíos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospectos', function (Blueprint $table) {
            $table->boolean('email_invalido')->default(false)->after('email');
            $table->string('email_invalido_motivo')->nullable()->after('email_invalido');
            $table->timestamp('email_invalido_at')->nullable()->after('email_invalido_motivo');
            
            // Índice para filtrar rápidamente prospectos con email válido
            $table->index('email_invalido');
        });
    }

    public function down(): void
    {
        Schema::table('prospectos', function (Blueprint $table) {
            $table->dropIndex(['email_invalido']);
            $table->dropColumn(['email_invalido', 'email_invalido_motivo', 'email_invalido_at']);
        });
    }
};
