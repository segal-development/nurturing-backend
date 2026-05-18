<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateProspectosIdsToPivot extends Command
{
    protected $signature = 'nurturing:migrate-prospectos-ids-to-pivot
                            {--chunk=50 : Number of source rows to process per batch}
                            {--dry-run : Show counts without inserting}';

    protected $description = 'Backfill ejecucion_prospecto and etapa_prospecto pivot tables from prospectos_ids JSON columns';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        if ($chunk < 1) {
            $this->error('Chunk size must be >= 1');

            return self::FAILURE;
        }

        $this->info('=== Migrating flujo_ejecuciones.prospectos_ids -> ejecucion_prospecto ===');
        $this->migrateTable(
            sourceTable: 'flujo_ejecuciones',
            pivotTable: 'ejecucion_prospecto',
            foreignKey: 'flujo_ejecucion_id',
            chunk: $chunk,
            dryRun: $dryRun,
        );

        $this->newLine();
        $this->info('=== Migrating flujo_ejecucion_etapas.prospectos_ids -> etapa_prospecto ===');
        $this->migrateTable(
            sourceTable: 'flujo_ejecucion_etapas',
            pivotTable: 'etapa_prospecto',
            foreignKey: 'flujo_ejecucion_etapa_id',
            chunk: $chunk,
            dryRun: $dryRun,
        );

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function migrateTable(
        string $sourceTable,
        string $pivotTable,
        string $foreignKey,
        int $chunk,
        bool $dryRun,
    ): void {
        $totalRows = DB::table($sourceTable)
            ->whereNotNull('prospectos_ids')
            ->whereRaw("prospectos_ids::text != '[]'")
            ->count();

        if ($totalRows === 0) {
            $this->line("No rows with prospectos_ids in {$sourceTable}.");

            return;
        }

        $this->line("Rows to process: {$totalRows}");

        $bar = $this->output->createProgressBar($totalRows);
        $bar->start();

        $totalInserted = 0;
        $lastId = 0;

        do {
            $rows = DB::table($sourceTable)
                ->select('id')
                ->whereNotNull('prospectos_ids')
                ->whereRaw("prospectos_ids::text != '[]'")
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $ids = $rows->pluck('id')->all();
            $lastId = end($ids);

            if (! $dryRun) {
                $inserted = DB::statement(
                    "INSERT INTO {$pivotTable} ({$foreignKey}, prospecto_id)
                     SELECT s.id, p.prospecto_id::bigint
                     FROM {$sourceTable} s,
                          LATERAL jsonb_array_elements_text(s.prospectos_ids::jsonb) AS p(prospecto_id)
                     WHERE s.id = ANY(?)
                       AND s.prospectos_ids IS NOT NULL
                       AND s.prospectos_ids::text != '[]'
                     ON CONFLICT DO NOTHING",
                    ['{'.implode(',', $ids).'}'],
                );

                if ($inserted) {
                    $totalInserted += count($ids);
                }
            }

            $bar->advance(count($ids));
        } while ($rows->count() === $chunk);

        $bar->finish();
        $this->newLine();
        $this->line($dryRun ? 'Dry-run: no inserts performed.' : "Processed source rows: {$totalInserted}");
    }
}
