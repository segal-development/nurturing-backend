<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remove duplicate envios (same etapa_flujo_id + prospecto_id).
 *
 * Keeps the first envio (lowest id) and deletes the rest.
 * Processes in batches to avoid locking the database.
 *
 * Usage:
 *   php artisan nurturing:cleanup-duplicate-envios --dry-run    # Preview
 *   php artisan nurturing:cleanup-duplicate-envios              # Execute
 *   php artisan nurturing:cleanup-duplicate-envios --batch=500  # Custom batch size
 */
class CleanupDuplicateEnvios extends Command
{
    protected $signature = 'nurturing:cleanup-duplicate-envios
                            {--dry-run : Only report what would be done, no changes}
                            {--batch=1000 : Number of records to delete per batch}
                            {--limit=0 : Maximum duplicates to process (0 = all)}';

    protected $description = 'Remove duplicate envios keeping the first one per etapa_flujo_id + prospecto_id';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch');
        $limit = (int) $this->option('limit');

        $this->info('Analyzing envios for duplicates...');
        $this->newLine();

        // Set longer timeout for the analysis query
        DB::statement("SET statement_timeout = '10min'");

        // Find all duplicate groups
        $this->info('Finding duplicate groups (this may take a few minutes)...');
        
        $duplicateGroups = DB::table('envios')
            ->select('etapa_flujo_id', 'prospecto_id', DB::raw('COUNT(*) as total'), DB::raw('MIN(id) as keep_id'))
            ->groupBy('etapa_flujo_id', 'prospecto_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $totalGroups = $duplicateGroups->count();
        $totalDuplicates = $duplicateGroups->sum(fn($d) => $d->total - 1);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Duplicate groups', number_format($totalGroups)],
                ['Total duplicates to delete', number_format($totalDuplicates)],
                ['Batch size', number_format($batchSize)],
            ]
        );
        $this->newLine();

        if ($totalDuplicates === 0) {
            $this->info('No duplicates found. Database is clean!');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
            $this->newLine();
            $this->info("Would delete {$totalDuplicates} duplicate envios in " . ceil($totalDuplicates / $batchSize) . " batches.");
            $this->newLine();
            
            // Show sample of what would be deleted
            $sample = $duplicateGroups->take(5);
            $this->info('Sample duplicate groups:');
            foreach ($sample as $group) {
                $this->line("  etapa_flujo_id={$group->etapa_flujo_id}, prospecto_id={$group->prospecto_id}: {$group->total} envios (keeping id={$group->keep_id})");
            }
            
            $this->newLine();
            $this->info('Run without --dry-run to execute deletion.');
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm("This will permanently delete {$totalDuplicates} duplicate envios. Continue?")) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        $this->info('Starting deletion...');
        $this->newLine();

        $totalDeleted = 0;
        $processedGroups = 0;
        $bar = $this->output->createProgressBar($totalGroups);
        $bar->start();

        // Process in batches
        $idsToDelete = [];
        
        foreach ($duplicateGroups as $group) {
            if ($limit > 0 && $totalDeleted >= $limit) {
                break;
            }

            // Get all IDs for this group except the one to keep
            $duplicateIds = DB::table('envios')
                ->where('etapa_flujo_id', $group->etapa_flujo_id)
                ->where('prospecto_id', $group->prospecto_id)
                ->where('id', '!=', $group->keep_id)
                ->pluck('id')
                ->toArray();

            $idsToDelete = array_merge($idsToDelete, $duplicateIds);

            // Delete in batches
            while (count($idsToDelete) >= $batchSize) {
                $batch = array_splice($idsToDelete, 0, $batchSize);
                $deleted = DB::table('envios')->whereIn('id', $batch)->delete();
                $totalDeleted += $deleted;
            }

            $processedGroups++;
            $bar->advance();
        }

        // Delete remaining
        if (!empty($idsToDelete)) {
            $deleted = DB::table('envios')->whereIn('id', $idsToDelete)->delete();
            $totalDeleted += $deleted;
        }

        $bar->finish();
        $this->newLine(2);

        Log::info('CleanupDuplicateEnvios: Completed', [
            'groups_processed' => $processedGroups,
            'total_deleted' => $totalDeleted,
        ]);

        $this->info("Deletion complete!");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Groups processed', number_format($processedGroups)],
                ['Envios deleted', number_format($totalDeleted)],
            ]
        );

        return Command::SUCCESS;
    }
}
