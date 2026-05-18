<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateProspectosIdsToPivot extends Command
{
    protected $signature = 'nurturing:migrate-prospectos-ids-to-pivot
                            {--insert-chunk=2000 : Prospect IDs per INSERT statement}
                            {--dry-run : Show counts without inserting}';

    protected $description = 'Backfill ejecucion_prospecto and etapa_prospecto pivot tables from prospectos_ids JSON columns';

    public function handle(): int
    {
        $insertChunk = (int) $this->option('insert-chunk');
        $dryRun = (bool) $this->option('dry-run');

        if ($insertChunk < 1) {
            $this->error('insert-chunk must be >= 1');

            return self::FAILURE;
        }

        DB::statement('SET statement_timeout = 0');

        $this->info('=== Migrating flujo_ejecuciones.prospectos_ids -> ejecucion_prospecto ===');
        $this->migrateTable(
            sourceTable: 'flujo_ejecuciones',
            pivotTable: 'ejecucion_prospecto',
            foreignKey: 'flujo_ejecucion_id',
            insertChunk: $insertChunk,
            dryRun: $dryRun,
        );

        $this->newLine();
        $this->info('=== Migrating flujo_ejecucion_etapas.prospectos_ids -> etapa_prospecto ===');
        $this->migrateTable(
            sourceTable: 'flujo_ejecucion_etapas',
            pivotTable: 'etapa_prospecto',
            foreignKey: 'flujo_ejecucion_etapa_id',
            insertChunk: $insertChunk,
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
        int $insertChunk,
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

        $this->line("Source rows to process: {$totalRows}");

        $bar = $this->output->createProgressBar($totalRows);
        $bar->start();

        $totalProspectsInserted = 0;
        $lastId = 0;

        do {
            $row = DB::table($sourceTable)
                ->select('id', 'prospectos_ids')
                ->whereNotNull('prospectos_ids')
                ->whereRaw("prospectos_ids::text != '[]'")
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->first();

            if (! $row) {
                break;
            }

            $lastId = $row->id;

            $prospectoIds = json_decode($row->prospectos_ids, true) ?? [];
            $prospectoIds = array_values(array_unique(array_filter($prospectoIds, 'is_numeric')));

            if (empty($prospectoIds)) {
                $bar->advance();

                continue;
            }

            if (! $dryRun) {
                foreach (array_chunk($prospectoIds, $insertChunk) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?)'));
                    $bindings = [];
                    foreach ($chunk as $prospectoId) {
                        $bindings[] = $row->id;
                        $bindings[] = (int) $prospectoId;
                    }

                    DB::statement(
                        "INSERT INTO {$pivotTable} ({$foreignKey}, prospecto_id) VALUES {$placeholders} ON CONFLICT DO NOTHING",
                        $bindings,
                    );

                    $totalProspectsInserted += count($chunk);
                }
            }

            $bar->advance();
        } while (true);

        $bar->finish();
        $this->newLine();
        $this->line($dryRun
            ? 'Dry-run: no inserts performed.'
            : "Total prospect rows attempted: {$totalProspectsInserted} (ON CONFLICT skipped if already present)");
    }
}
