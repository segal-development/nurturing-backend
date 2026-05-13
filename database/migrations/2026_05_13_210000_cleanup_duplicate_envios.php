<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Cleanup duplicate envios keeping the oldest record (MIN id) per combination.
     * 
     * Duplicates occur when the same prospecto receives multiple SMS/emails
     * for the same flujo_ejecucion_etapa. This migration removes the newer
     * duplicates while preserving the original envío.
     */
    public function up(): void
    {
        // Count duplicates before cleanup
        $duplicadosAntes = DB::table('envios')
            ->select('prospecto_id', 'flujo_ejecucion_etapa_id', 'canal', DB::raw('COUNT(*) as total'))
            ->whereNotNull('flujo_ejecucion_etapa_id')
            ->groupBy('prospecto_id', 'flujo_ejecucion_etapa_id', 'canal')
            ->having('total', '>', 1)
            ->get();

        $gruposConDuplicados = $duplicadosAntes->count();
        $totalDuplicados = $duplicadosAntes->sum(fn ($row) => $row->total - 1);

        Log::info("Cleanup duplicados: {$gruposConDuplicados} grupos con duplicados encontrados, {$totalDuplicados} registros duplicados a eliminar");

        if ($gruposConDuplicados === 0) {
            Log::info('Cleanup duplicados: No hay duplicados para limpiar');
            return;
        }

        // Delete duplicates keeping the oldest (MIN id) per (prospecto_id, flujo_ejecucion_etapa_id, canal)
        // MySQL-compatible subquery approach
        $eliminados = DB::delete("
            DELETE FROM envios 
            WHERE id NOT IN (
                SELECT min_id FROM (
                    SELECT MIN(id) as min_id 
                    FROM envios 
                    WHERE flujo_ejecucion_etapa_id IS NOT NULL
                    GROUP BY prospecto_id, flujo_ejecucion_etapa_id, canal
                ) as keeper
            )
            AND flujo_ejecucion_etapa_id IS NOT NULL
            AND EXISTS (
                SELECT 1 FROM (
                    SELECT prospecto_id, flujo_ejecucion_etapa_id, canal
                    FROM envios
                    WHERE flujo_ejecucion_etapa_id IS NOT NULL
                    GROUP BY prospecto_id, flujo_ejecucion_etapa_id, canal
                    HAVING COUNT(*) > 1
                ) as dup
                WHERE dup.prospecto_id = envios.prospecto_id 
                AND dup.flujo_ejecucion_etapa_id = envios.flujo_ejecucion_etapa_id
                AND dup.canal = envios.canal
            )
        ");

        Log::info("Cleanup duplicados completado: {$eliminados} registros eliminados");
    }

    /**
     * Cannot be reverted - duplicates were intentionally removed.
     */
    public function down(): void
    {
        Log::warning('Cleanup duplicados: Esta migración no puede ser revertida - los duplicados fueron eliminados intencionalmente');
    }
};
