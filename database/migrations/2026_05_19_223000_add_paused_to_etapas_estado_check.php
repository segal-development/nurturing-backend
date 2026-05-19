<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega 'paused' como valor permitido en el CHECK constraint de `flujo_ejecucion_etapas.estado`.
 *
 * Contexto:
 * - El método `FlujoEjecucionEtapa::pausarPorCircuitBreaker()` setea estado='paused'.
 * - El listener `PauseEtapasOnCircuitBreaker` invoca ese método cuando el breaker abre.
 * - Sin embargo, el CHECK constraint original solo permitía:
 *   (pending, executing, completed, failed)
 * - Esto causaba que cualquier intento de pausa fallara silenciosamente con
 *   `SQLSTATE[23514]: Check violation` en producción.
 * - Esta migration agrega 'paused' al enum permitido, completando el ciclo
 *   pause-resume del Circuit Breaker.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE flujo_ejecucion_etapas DROP CONSTRAINT IF EXISTS flujo_ejecucion_etapas_estado_check');
        DB::statement(<<<'SQL'
            ALTER TABLE flujo_ejecucion_etapas
            ADD CONSTRAINT flujo_ejecucion_etapas_estado_check
            CHECK (estado IN ('pending', 'executing', 'completed', 'failed', 'paused'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE flujo_ejecucion_etapas DROP CONSTRAINT IF EXISTS flujo_ejecucion_etapas_estado_check');
        DB::statement(<<<'SQL'
            ALTER TABLE flujo_ejecucion_etapas
            ADD CONSTRAINT flujo_ejecucion_etapas_estado_check
            CHECK (estado IN ('pending', 'executing', 'completed', 'failed'))
        SQL);
    }
};
