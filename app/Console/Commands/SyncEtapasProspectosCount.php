<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sync prospectos_ids in pending etapas with the execution's total prospectos.
 *
 * This fixes etapas that were created before new prospectos were added
 * to a perpetual flow execution.
 *
 * Usage:
 *   php artisan nurturing:sync-etapas-prospectos --dry-run    # Preview
 *   php artisan nurturing:sync-etapas-prospectos              # Execute
 *   php artisan nurturing:sync-etapas-prospectos --ejecucion=52  # Specific execution
 */
class SyncEtapasProspectosCount extends Command
{
    protected $signature = 'nurturing:sync-etapas-prospectos
                            {--dry-run : Only report what would be done}
                            {--ejecucion= : Specific execution ID to sync}';

    protected $description = 'Sync pending etapas prospectos_ids with execution total for perpetual flows';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $ejecucionId = $this->option('ejecucion');

        $this->info('Analyzing perpetual flow executions...');
        $this->newLine();

        // Find perpetual executions with pending etapas
        $query = DB::table('flujo_ejecuciones')
            ->where('es_perpetuo', true)
            ->where('estado', 'in_progress');

        if ($ejecucionId) {
            $query->where('id', $ejecucionId);
        }

        $ejecuciones = $query->get();

        if ($ejecuciones->isEmpty()) {
            $this->info('No perpetual executions found.');

            return Command::SUCCESS;
        }

        $totalFixed = 0;

        foreach ($ejecuciones as $ejecucion) {
            $ejecucionModel = \App\Models\FlujoEjecucion::find($ejecucion->id);
            $prospectoIds = $ejecucionModel?->prospectos()->pluck('prospectos.id')->toArray() ?? [];
            $totalProspectos = count($prospectoIds);

            $this->info("Ejecución #{$ejecucion->id}: {$totalProspectos} prospectos totales");

            // Find pending etapas with fewer prospectos than the execution
            $etapasPendientes = DB::table('flujo_ejecucion_etapas')
                ->where('flujo_ejecucion_id', $ejecucion->id)
                ->where('estado', 'pending')
                ->get();

            foreach ($etapasPendientes as $etapa) {
                $etapaModel = \App\Models\FlujoEjecucionEtapa::find($etapa->id);
                $etapaProspectos = $etapaModel?->prospectos()->pluck('prospectos.id')->toArray() ?? [];
                $etapaCount = count($etapaProspectos);

                if ($etapaCount < $totalProspectos) {
                    $missing = $totalProspectos - $etapaCount;
                    $this->line("  - {$etapa->node_id}: {$etapaCount} prospectos (faltan {$missing})");

                    if (! $dryRun) {
                        $mergedIds = array_values(array_unique(array_merge($etapaProspectos, $prospectoIds)));

                        DB::table('flujo_ejecucion_etapas')
                            ->where('id', $etapa->id)
                            ->update([
                                'prospectos_ids' => json_encode($mergedIds),
                                'prospectos_count' => count($mergedIds),
                            ]);

                        \App\Models\FlujoEjecucionEtapa::find($etapa->id)
                            ?->prospectos()->sync($mergedIds);

                        $this->info("    ✓ Actualizado a {$totalProspectos} prospectos");
                    }

                    $totalFixed++;
                } else {
                    $this->line("  - {$etapa->node_id}: {$etapaCount} prospectos ✓");
                }
            }

            $this->newLine();
        }

        if ($dryRun) {
            $this->warn('DRY RUN - No changes made');
            $this->info("Would fix {$totalFixed} etapas");
        } else {
            $this->info("Fixed {$totalFixed} etapas");
            Log::info('SyncEtapasProspectosCount: Completed', ['etapas_fixed' => $totalFixed]);
        }

        return Command::SUCCESS;
    }
}
