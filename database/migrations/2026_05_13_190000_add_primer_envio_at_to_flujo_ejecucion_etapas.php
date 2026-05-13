<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campo primer_envio_at para trackear cuándo una etapa procesó envíos por primera vez.
 * 
 * Esto permite contar etapas "que han trabajado" independiente de su estado técnico,
 * especialmente útil para flujos perpetuos donde el estado cambia constantemente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->timestamp('primer_envio_at')->nullable()->after('fecha_ejecucion');
        });

        // Popular datos existentes: si la etapa tiene envíos, usar la fecha del primer envío
        DB::statement("
            UPDATE flujo_ejecucion_etapas 
            SET primer_envio_at = (
                SELECT MIN(created_at) 
                FROM envios 
                WHERE envios.flujo_ejecucion_etapa_id = flujo_ejecucion_etapas.id
            )
            WHERE EXISTS (
                SELECT 1 
                FROM envios 
                WHERE envios.flujo_ejecucion_etapa_id = flujo_ejecucion_etapas.id
            )
        ");
    }

    public function down(): void
    {
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->dropColumn('primer_envio_at');
        });
    }
};
