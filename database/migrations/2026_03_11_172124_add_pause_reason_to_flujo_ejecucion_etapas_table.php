<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campo pause_reason para almacenar información sobre pausas automáticas.
 *
 * Este campo se usa cuando el circuit breaker pausa una etapa automáticamente
 * por errores de la API (email/SMS). Almacena:
 * - reason: Motivo del pause (circuit_breaker_opened, api_error, etc)
 * - channel: Canal afectado (email, sms)
 * - failures: Cantidad de fallos que dispararon el pause
 * - paused_at: Timestamp del pause
 * - auto_resume_at: Timestamp estimado de reanudación
 * - error_message: Último mensaje de error (opcional)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->jsonb('pause_reason')->nullable()->after('response_athenacampaign');
            $table->timestamp('paused_at')->nullable()->after('pause_reason');
            $table->timestamp('auto_resume_at')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->dropColumn(['pause_reason', 'paused_at', 'auto_resume_at']);
        });
    }
};
