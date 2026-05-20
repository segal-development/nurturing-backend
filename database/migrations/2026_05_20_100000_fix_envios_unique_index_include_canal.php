<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige el partial unique index de `envios` para incluir `canal`.
 *
 * Contexto:
 * - La migration 2026_05_19_220000 creó `envios_prospecto_etapa_unique_new`
 *   sobre (prospecto_id, flujo_ejecucion_etapa_id) para forzar idempotencia.
 * - Pero las etapas con tipo_mensaje='ambos' generan DOS envíos por
 *   (prospecto, etapa): uno email y uno SMS. El índice sin `canal` rechazaba
 *   el segundo INSERT como duplicado, haciendo fallar TODOS los
 *   EnviarSmsEtapaProspectoJob de flujos de doble canal (regresión sistémica
 *   detectada el 2026-05-20: decenas de SMS a failed_jobs con
 *   "duplicate key ... envios_prospecto_etapa_unique_new").
 *
 * Fix:
 * - Reemplazar el índice por uno que incluya `canal`:
 *   (prospecto_id, flujo_ejecucion_etapa_id, canal)
 * - Permite el par legítimo email + SMS por (prospecto, etapa).
 * - Sigue bloqueando duplicados reales (dos emails o dos SMS idénticos).
 * - Mantiene el alcance parcial: solo registros nuevos, preserva baseline histórica.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS envios_prospecto_etapa_unique_new');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS envios_prospecto_etapa_canal_unique
            ON envios (prospecto_id, flujo_ejecucion_etapa_id, canal)
            WHERE created_at > '2026-05-20'::date
              AND flujo_ejecucion_etapa_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS envios_prospecto_etapa_canal_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS envios_prospecto_etapa_unique_new
            ON envios (prospecto_id, flujo_ejecucion_etapa_id)
            WHERE created_at > '2026-05-20'::date
              AND flujo_ejecucion_etapa_id IS NOT NULL
        SQL);
    }
};
