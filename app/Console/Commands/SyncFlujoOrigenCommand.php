<?php

namespace App\Console\Commands;

use App\Models\Flujo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\progress;

/**
 * Sync flujo.origen based on the dominant origen among its prospectos.
 *
 * This command populates the `origen` column for existing flujos by analyzing
 * which origen is most common among its assigned prospectos via the
 * importaciones → prospectos → prospecto_en_flujo relationship chain.
 *
 * Logic:
 * 1. Get all flujos (or specific flujo if --flujo provided)
 * 2. For each flujo, find the most common origen among its prospectos
 * 3. Update flujo.origen with that value (NULL if no prospectos)
 *
 * Handles:
 * - Flujos with no prospectos → origen becomes NULL
 * - Multiple orígenes with same count → first one wins (alphabetical)
 * - Idempotent: can be run multiple times safely
 * - Chunk processing for large datasets
 */
class SyncFlujoOrigenCommand extends Command
{
    protected $signature = 'flujos:sync-origen
                            {--flujo= : Specific flujo_id to sync}
                            {--dry-run : Show what would be updated without making changes}
                            {--chunk=100 : Batch size for processing}';

    protected $description = 'Sync flujo.origen from dominant prospecto origen';

    private int $processed = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $multiOrigen = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');
        $flujoId = $this->option('flujo');

        if ($dryRun) {
            $this->components->info('Running in DRY-RUN mode — no changes will be made');
        }

        $this->info('Building query for flujos...');

        $query = Flujo::query();

        if ($flujoId) {
            $query->where('id', $flujoId);
            $this->info("Filtering to flujo_id: {$flujoId}");
        }

        $total = $query->count();

        if ($total === 0) {
            $this->components->info('No flujos found to process');

            return Command::SUCCESS;
        }

        $this->info("Found {$total} flujos to process");
        $this->newLine();

        $progress = progress(
            label: 'Syncing flujo.origen',
            steps: $total
        );

        $progress->start();

        $query->chunkById($chunkSize, function ($batch) use ($dryRun, $progress) {
            foreach ($batch as $flujo) {
                $this->procesarFlujo($flujo, $dryRun);
                $progress->advance();
            }
        });

        $progress->finish();

        $this->newLine();
        $this->displaySummary($dryRun);

        return Command::SUCCESS;
    }

    /**
     * Process a single flujo - find dominant origen and update.
     */
    private function procesarFlujo(Flujo $flujo, bool $dryRun): void
    {
        $this->processed++;

        // Get all orígenes with their counts for this flujo
        $origenes = DB::table('prospectos')
            ->join('prospecto_en_flujo', 'prospecto_en_flujo.prospecto_id', '=', 'prospectos.id')
            ->join('importaciones', 'importaciones.id', '=', 'prospectos.importacion_id')
            ->where('prospecto_en_flujo.flujo_id', $flujo->id)
            ->whereNotNull('importaciones.origen')
            ->select('importaciones.origen')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('importaciones.origen')
            ->orderByDesc('cnt')
            ->orderBy('importaciones.origen') // Deterministic: alphabetical tiebreaker
            ->get();

        if ($origenes->isEmpty()) {
            // No prospectos with importaciones → leave/set NULL
            if ($flujo->origen !== null) {
                if (! $dryRun) {
                    $flujo->update(['origen' => null]);
                }
                $this->updated++;
            } else {
                $this->skipped++;
            }

            return;
        }

        // Track multi-origen flujos for reporting
        if ($origenes->count() > 1) {
            $this->multiOrigen++;
        }

        $dominantOrigen = $origenes->first()->origen;

        // Only update if different
        if ($flujo->origen !== $dominantOrigen) {
            if (! $dryRun) {
                $flujo->update(['origen' => $dominantOrigen]);
            }
            $this->updated++;
        } else {
            $this->skipped++;
        }
    }

    /**
     * Display final summary.
     */
    private function displaySummary(bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY RUN] Would have' : 'Successfully';

        $this->components->twoColumnDetail('Processed', (string) $this->processed);
        $this->components->twoColumnDetail("{$prefix} updated", (string) $this->updated);
        $this->components->twoColumnDetail('Skipped (already correct)', (string) $this->skipped);
        $this->components->twoColumnDetail('Flujos with multiple orígenes', (string) $this->multiOrigen);

        if ($dryRun && $this->updated > 0) {
            $this->newLine();
            $this->components->warn('Run without --dry-run to apply changes');
        }

        if (! $dryRun && $this->updated > 0) {
            $this->newLine();
            $this->components->success("Sync complete! {$this->updated} flujos updated.");
        }
    }
}
