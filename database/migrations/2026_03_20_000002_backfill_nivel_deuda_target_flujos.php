<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill nivel_deuda_target for existing Sysgal flujos.
     *
     * Mapping from AsignarProspectosSysgalJob::NIVEL_DEUDA_FLUJO_MAP:
     * - Flujo 39 (SEGMENTO 1): baja + sin_informacion
     * - Flujo 40 (SEGMENTO 2): media
     * - Flujo 41 (SEGMENTO 3): alta
     */
    public function up(): void
    {
        DB::table('flujos')->where('id', 39)
            ->update(['nivel_deuda_target' => json_encode(['baja', 'sin_informacion'])]);

        DB::table('flujos')->where('id', 40)
            ->update(['nivel_deuda_target' => json_encode(['media'])]);

        DB::table('flujos')->where('id', 41)
            ->update(['nivel_deuda_target' => json_encode(['alta'])]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('flujos')->whereIn('id', [39, 40, 41])
            ->update(['nivel_deuda_target' => null]);
    }
};
