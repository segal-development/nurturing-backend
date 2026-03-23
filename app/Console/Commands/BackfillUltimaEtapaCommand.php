<?php

namespace App\Console\Commands;

use App\Models\Envio;
use App\Models\ProspectoEnFlujo;
use Illuminate\Console\Command;

use function Laravel\Prompts\progress;

/**
 * Backfill ultima_etapa_node_id from envios history.
 *
 * This command populates the progress tracking column for existing prospects
 * based on their historical envios records. Must run BEFORE enabling filtering
 * in EnviarEtapaJob to avoid breaking existing prospects.
 *
 * Logic:
 * 1. Get all prospecto_en_flujo records with NULL ultima_etapa_node_id
 * 2. For each, find their latest successful envio
 * 3. Get the node_id from flujo_ejecucion_etapa
 * 4. Update ultima_etapa_node_id
 *
 * Handles:
 * - prospecto_en_flujo with no envios → leave NULL (new prospect)
 * - Multiple envios → use the one with latest flujo_ejecucion_etapa
 * - Failed envios → ignore (estado != 'fallido')
 * - Chunk processing for 100k+ records
 * - Progress output
 */
class BackfillUltimaEtapaCommand extends Command
{
    protected $signature = 'prospect:backfill-ultima-etapa
                            {--flujo= : Specific flujo_id to backfill}
                            {--dry-run : Show what would be updated without making changes}
                            {--chunk=5000 : Batch size for processing}';

    protected $description = 'Backfill ultima_etapa_node_id from envios history';

    private int $processed = 0;

    private int $updated = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');
        $flujoId = $this->option('flujo');

        if ($dryRun) {
            $this->components->info('Running in DRY-RUN mode — no changes will be made');
        }

        $this->info('Building query for prospecto_en_flujo records...');

        $query = ProspectoEnFlujo::query()
            ->whereNull('ultima_etapa_node_id')
            ->where('completado', false)
            ->where('cancelado', false);

        if ($flujoId) {
            $query->where('flujo_id', $flujoId);
            $this->info("Filtering to flujo_id: {$flujoId}");
        }

        $total = $query->count();

        if ($total === 0) {
            $this->components->info('No prospecto_en_flujo records need backfilling');

            return Command::SUCCESS;
        }

        $this->info("Found {$total} prospecto_en_flujo records to process");
        $this->newLine();

        $progress = progress(
            label: 'Backfilling ultima_etapa_node_id',
            steps: $total
        );

        $progress->start();

        $query->chunkById($chunkSize, function ($batch) use ($dryRun, $progress) {
            foreach ($batch as $pef) {
                $this->procesarProspectoEnFlujo($pef, $dryRun);
                $progress->advance();
            }
        });

        $progress->finish();

        $this->newLine();
        $this->displaySummary($dryRun);

        return Command::SUCCESS;
    }

    /**
     * Process a single prospecto_en_flujo record.
     */
    private function procesarProspectoEnFlujo(ProspectoEnFlujo $pef, bool $dryRun): void
    {
        $this->processed++;

        $lastEnvio = $this->findLastSuccessfulEnvio($pef);

        if (! $lastEnvio) {
            // No successful envios — leave NULL (new prospect, hasn't received anything)
            $this->skipped++;

            return;
        }

        $nodeId = $lastEnvio->flujoEjecucionEtapa?->node_id;

        if (! $nodeId) {
            // Envio exists but no node_id (legacy data, manual send, etc.)
            $this->skipped++;

            return;
        }

        if (! $dryRun) {
            $pef->update([
                'ultima_etapa_node_id' => $nodeId,
            ]);
        }

        $this->updated++;
    }

    /**
     * Find the last successful envio for a prospecto_en_flujo.
     *
     * "Successful" means estado in: enviado, abierto, clickeado
     * (anything except 'pendiente' or 'fallido')
     *
     * Uses fecha_enviado as the primary ordering, with flujo_ejecucion_etapa_id
     * as secondary to handle same-second sends.
     */
    private function findLastSuccessfulEnvio(ProspectoEnFlujo $pef): ?Envio
    {
        return Envio::where('prospecto_id', $pef->prospecto_id)
            ->where('flujo_id', $pef->flujo_id)
            ->whereIn('estado', ['enviado', 'abierto', 'clickeado'])
            ->whereNotNull('flujo_ejecucion_etapa_id')
            ->orderByDesc('fecha_enviado')
            ->orderByDesc('flujo_ejecucion_etapa_id') // Fallback: higher ID = later stage
            ->first();
    }

    /**
     * Display final summary.
     */
    private function displaySummary(bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY RUN] Would have' : 'Successfully';

        $this->components->twoColumnDetail('Processed', (string) $this->processed);
        $this->components->twoColumnDetail("{$prefix} updated", (string) $this->updated);
        $this->components->twoColumnDetail('Skipped (no envios or node_id)', (string) $this->skipped);

        if ($dryRun && $this->updated > 0) {
            $this->newLine();
            $this->components->warn('Run without --dry-run to apply changes');
        }

        if (! $dryRun && $this->updated > 0) {
            $this->newLine();
            $this->components->success("Backfill complete! {$this->updated} records updated.");
        }
    }
}
