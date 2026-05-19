<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega un PARTIAL UNIQUE INDEX sobre (prospecto_id, flujo_ejecucion_etapa_id)
 * en la tabla `envios` para registros futuros únicamente.
 *
 * Contexto:
 * - Históricamente la tabla `envios` carecía de constraint de idempotencia.
 * - Un bug en FlujoEjecucionEtapaObserver (corregido en commit d38a257)
 *   causaba que CatchUpProspectosJob dispatchara el mismo batch múltiples veces,
 *   generando ~70.699 filas duplicadas sobre 26.149 pares (prospecto, etapa).
 * - Aplicar un constraint global requeriría eliminar duplicados históricos,
 *   alterando la baseline operacional. En su lugar, usamos un partial unique
 *   index que solo aplica a registros nuevos.
 *
 * Comportamiento:
 * - Filas con created_at > '2026-05-20': deben ser únicas por (prospecto_id, flujo_ejecucion_etapa_id)
 * - Filas con created_at <= '2026-05-20': sin restricción (data histórica preservada)
 * - Filas sin flujo_ejecucion_etapa_id: sin restricción (envios huérfanos o legacy)
 *
 * Garantía: cualquier intento de INSERT duplicado a partir de esta migration
 * fallará con UniqueConstraintViolation, forzando idempotencia a nivel BD.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS envios_prospecto_etapa_unique_new
            ON envios (prospecto_id, flujo_ejecucion_etapa_id)
            WHERE created_at > '2026-05-20'::date
              AND flujo_ejecucion_etapa_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS envios_prospecto_etapa_unique_new');
    }
};
