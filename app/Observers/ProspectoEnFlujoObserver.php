<?php

namespace App\Observers;

use App\Models\Flujo;
use App\Models\ProspectoEnFlujo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Observer for ProspectoEnFlujo model.
 *
 * Auto-syncs flujo.origen when prospectos are assigned or removed.
 *
 * Rules:
 * - On created(): If flujo.origen is NULL, set it from prospecto's importacion.origen
 * - On deleted(): Optionally recalculate (currently no-op to avoid race conditions)
 *
 * This ensures new flujos get their origen set automatically when first prospecto is assigned,
 * while not overwriting manually set or previously calculated origenes.
 */
class ProspectoEnFlujoObserver
{
    /**
     * Handle the ProspectoEnFlujo "created" event.
     *
     * Only sets flujo.origen if it's currently NULL (first assignment sets the origen).
     */
    public function created(ProspectoEnFlujo $prospectoEnFlujo): void
    {
        try {
            $flujo = $prospectoEnFlujo->flujo;

            // Only set if origen is NULL (don't override existing)
            if ($flujo->origen !== null) {
                return;
            }

            $origen = $this->getProspectoOrigen($prospectoEnFlujo);

            if ($origen === null) {
                // Prospecto has no importacion or importacion has no origen (legacy data)
                return;
            }

            // Update without triggering another observer event
            Flujo::withoutEvents(function () use ($flujo, $origen) {
                $flujo->update(['origen' => $origen]);
            });

            Log::debug('ProspectoEnFlujoObserver: Set flujo.origen', [
                'flujo_id' => $flujo->id,
                'origen' => $origen,
                'prospecto_id' => $prospectoEnFlujo->prospecto_id,
            ]);
        } catch (\Exception $e) {
            // Log but don't fail the assignment
            Log::error('ProspectoEnFlujoObserver: Error setting flujo.origen', [
                'flujo_id' => $prospectoEnFlujo->flujo_id,
                'prospecto_id' => $prospectoEnFlujo->prospecto_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the ProspectoEnFlujo "deleted" event.
     *
     * Currently a no-op. We don't recalculate on delete because:
     * - It could cause race conditions during batch operations
     * - The origen is mainly for display/convenience
     * - The opcionesFiltrado query infers origen dynamically anyway
     *
     * If recalculation is needed, use: php artisan flujos:sync-origen --flujo={id}
     */
    public function deleted(ProspectoEnFlujo $prospectoEnFlujo): void
    {
        // No-op by design. See docblock above.
    }

    /**
     * Get the origen from prospecto's importacion.
     *
     * Returns NULL if:
     * - Prospecto doesn't exist
     * - Prospecto has no importacion_id (legacy)
     * - Importacion has no origen
     */
    private function getProspectoOrigen(ProspectoEnFlujo $prospectoEnFlujo): ?string
    {
        return DB::table('prospectos')
            ->join('importaciones', 'importaciones.id', '=', 'prospectos.importacion_id')
            ->where('prospectos.id', $prospectoEnFlujo->prospecto_id)
            ->value('importaciones.origen');
    }
}
