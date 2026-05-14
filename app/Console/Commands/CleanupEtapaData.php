<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cleanup NULL values in flujo_ejecucion_etapas.
 *
 * This command provides a safe way to backfill data before adding
 * NOT NULL constraints. Run with --dry-run first to see the impact.
 *
 * Usage:
 *   php artisan nurturing:cleanup-etapas --dry-run    # Preview changes
 *   php artisan nurturing:cleanup-etapas              # Execute backfill
 */
class CleanupEtapaData extends Command
{
    protected $signature = 'nurturing:cleanup-etapas
                            {--dry-run : Only report what would be done, no changes}
                            {--verbose-list : Show IDs of affected records}';

    protected $description = 'Cleanup NULL values in flujo_ejecucion_etapas before adding constraints';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $verboseList = $this->option('verbose-list');

        $this->info('Analyzing flujo_ejecucion_etapas for NULL values...');
        $this->newLine();

        // Report current state
        $nullNodeIdQuery = DB::table('flujo_ejecucion_etapas')->whereNull('node_id');
        $nullFechaQuery = DB::table('flujo_ejecucion_etapas')->whereNull('fecha_programada');

        $nullNodeIdCount = $nullNodeIdQuery->count();
        $nullFechaCount = $nullFechaQuery->count();

        $this->table(
            ['Field', 'NULL Count', 'Status'],
            [
                ['node_id', $nullNodeIdCount, $nullNodeIdCount > 0 ? '<fg=yellow>Needs backfill</>' : '<fg=green>OK</>'],
                ['fecha_programada', $nullFechaCount, $nullFechaCount > 0 ? '<fg=yellow>Needs backfill</>' : '<fg=green>OK</>'],
            ]
        );
        $this->newLine();

        // Show affected IDs if requested
        if ($verboseList && ($nullNodeIdCount > 0 || $nullFechaCount > 0)) {
            if ($nullNodeIdCount > 0) {
                $ids = $nullNodeIdQuery->limit(50)->pluck('id')->toArray();
                $this->warn('Records with NULL node_id (first 50):');
                $this->line(implode(', ', $ids));
                $this->newLine();
            }
            if ($nullFechaCount > 0) {
                $ids = $nullFechaQuery->limit(50)->pluck('id')->toArray();
                $this->warn('Records with NULL fecha_programada (first 50):');
                $this->line(implode(', ', $ids));
                $this->newLine();
            }
        }

        // Nothing to do
        if ($nullNodeIdCount === 0 && $nullFechaCount === 0) {
            $this->info('No NULL values found. Safe to add NOT NULL constraints.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
            $this->newLine();
            $this->info('Planned actions:');

            if ($nullNodeIdCount > 0) {
                $this->line("  - Backfill {$nullNodeIdCount} records: node_id = 'legacy-unknown-{id}'");
            }
            if ($nullFechaCount > 0) {
                $this->line("  - Backfill {$nullFechaCount} records: fecha_programada = created_at");
            }

            $this->newLine();
            $this->info('Run without --dry-run to execute these changes.');

            return Command::SUCCESS;
        }

        // Execute backfill
        $this->info('Executing backfill...');

        $updatedNodeId = 0;
        $updatedFecha = 0;

        if ($nullNodeIdCount > 0) {
            $updatedNodeId = DB::table('flujo_ejecucion_etapas')
                ->whereNull('node_id')
                ->update(['node_id' => DB::raw("CONCAT('legacy-unknown-', id)")]);

            $this->info("  Backfilled {$updatedNodeId} node_id values");
        }

        if ($nullFechaCount > 0) {
            $updatedFecha = DB::table('flujo_ejecucion_etapas')
                ->whereNull('fecha_programada')
                ->update(['fecha_programada' => DB::raw('created_at')]);

            $this->info("  Backfilled {$updatedFecha} fecha_programada values");
        }

        Log::info('CleanupEtapaData: Backfill completed', [
            'node_id_backfilled' => $updatedNodeId,
            'fecha_programada_backfilled' => $updatedFecha,
        ]);

        $this->newLine();

        // Verify results
        $remainingNulls = DB::table('flujo_ejecucion_etapas')
            ->where(function ($q) {
                $q->whereNull('node_id')
                    ->orWhereNull('fecha_programada');
            })
            ->count();

        if ($remainingNulls === 0) {
            $this->info('Backfill complete. Safe to run constraint migration:');
            $this->line('  php artisan migrate --path=database/migrations/2026_05_14_190001_add_not_null_constraints_to_etapas.php');
        } else {
            $this->error("Warning: {$remainingNulls} records still have NULL values.");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
