<?php

namespace App\Console\Commands;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\ProspectoEnFlujo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recovers orphan prospects that are assigned to flows but never entered execution.
 *
 * An orphan prospect is one where:
 * - `prospecto_en_flujo.estado = 'pendiente'`
 * - `prospecto_en_flujo.etapa_actual_id = NULL`
 * - NOT in any `FlujoEjecucion.prospectos_ids` (JSON array)
 *
 * This can happen when:
 * - Import process assigned prospects to flows but execution wasn't triggered
 * - Execution creation failed silently
 * - Race conditions during bulk imports
 */
class RecoverOrphanProspects extends Command
{
    protected $signature = 'flujo:recover-orphan-prospects
                            {--dry-run : Preview changes without executing them}
                            {--flujo-id= : Process only a specific flow ID}';

    protected $description = 'Creates FlujoEjecucion for orphan prospects that were assigned to flows but never entered execution';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $flujoIdFilter = $this->option('flujo-id');

        $this->info('Searching for orphan prospects...');

        if ($dryRun) {
            $this->warn('DRY-RUN MODE: No changes will be made');
        }

        // Step 1: Find all prospect IDs that are already in some FlujoEjecucion
        $prospectsInExecution = $this->getProspectsAlreadyInExecution();
        $this->line("Found {$prospectsInExecution->count()} prospects already in execution.");

        // Step 2: Find orphan prospects (pendiente + not in any execution)
        $orphansQuery = ProspectoEnFlujo::query()
            ->where('estado', 'pendiente')
            ->whereNull('etapa_actual_id')
            ->whereNotIn('prospecto_id', $prospectsInExecution);

        if ($flujoIdFilter) {
            $orphansQuery->where('flujo_id', $flujoIdFilter);
            $this->line("Filtering by flujo_id: {$flujoIdFilter}");
        }

        $orphans = $orphansQuery->get();

        if ($orphans->isEmpty()) {
            $this->info('No orphan prospects found.');

            return Command::SUCCESS;
        }

        $this->warn("Found {$orphans->count()} orphan prospects.");

        // Step 3: Group orphans by flujo_id
        $groupedByFlujo = $orphans->groupBy('flujo_id');

        $this->newLine();
        $this->info('Breakdown by flow:');
        $this->table(
            ['Flujo ID', 'Orphan Count'],
            $groupedByFlujo->map(fn ($group, $flujoId) => [$flujoId, $group->count()])->values()->toArray()
        );

        // Step 4: Process each flujo
        $totalRecovered = 0;

        foreach ($groupedByFlujo as $flujoId => $orphanProspects) {
            $this->newLine();
            $result = $this->processFlujo($flujoId, $orphanProspects, $dryRun);

            if ($result['success']) {
                $totalRecovered += $result['count'];
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "DRY-RUN: Would recover {$totalRecovered} prospects"
            : "Recovered {$totalRecovered} prospects");

        return Command::SUCCESS;
    }

    /**
     * Get all prospect IDs that are already part of a FlujoEjecucion.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function getProspectsAlreadyInExecution(): \Illuminate\Support\Collection
    {
        // PostgreSQL: cast json to jsonb first, then use jsonb_array_elements_text
        $result = DB::select("
            SELECT DISTINCT jsonb_array_elements_text(prospectos_ids::jsonb)::bigint as prospecto_id
            FROM flujo_ejecuciones
            WHERE prospectos_ids IS NOT NULL
              AND prospectos_ids::text != '[]'
        ");

        return collect($result)->pluck('prospecto_id');
    }

    /**
     * Process orphan prospects for a single flow.
     *
     * @param  \Illuminate\Support\Collection  $orphanProspects
     * @return array{success: bool, count: int, error?: string}
     */
    private function processFlujo(int $flujoId, $orphanProspects, bool $dryRun): array
    {
        $flujo = Flujo::find($flujoId);

        if (! $flujo) {
            $this->error("  Flujo #{$flujoId} not found!");

            return ['success' => false, 'count' => 0, 'error' => 'Flujo not found'];
        }

        $this->info("Processing Flujo #{$flujoId}: {$flujo->nombre}");
        $this->line("  Orphan prospects: {$orphanProspects->count()}");

        // Validate flow has config_structure with stages
        $configStructure = $flujo->config_structure;

        if (empty($configStructure) || empty($configStructure['stages'])) {
            $this->error('  ERROR: Flujo has no config_structure or stages defined!');

            return ['success' => false, 'count' => 0, 'error' => 'No config_structure'];
        }

        // Get first stage (sorted by orden)
        $stages = collect($configStructure['stages'])->sortBy('orden');
        $firstStage = $stages->first();

        if (! $firstStage || empty($firstStage['id'])) {
            $this->error('  ERROR: Could not determine first stage!');

            return ['success' => false, 'count' => 0, 'error' => 'No first stage'];
        }

        $this->line("  First stage: {$firstStage['label']} ({$firstStage['id']})");

        // Check for idempotency: don't create if execution already exists for this batch
        $prospectIds = $orphanProspects->pluck('prospecto_id')->toArray();

        // Check if ANY of these prospects are already in an execution for this flujo
        $existingExecution = FlujoEjecucion::where('flujo_id', $flujoId)
            ->where('estado', '!=', 'failed')
            ->get()
            ->filter(function ($ejecucion) use ($prospectIds) {
                $ejecucionProspectos = $ejecucion->prospectos_ids ?? [];

                return count(array_intersect($ejecucionProspectos, $prospectIds)) > 0;
            })
            ->first();

        if ($existingExecution) {
            $this->warn("  SKIPPED: Some prospects already have an execution (#{$existingExecution->id})");

            return ['success' => false, 'count' => 0, 'error' => 'Execution already exists'];
        }

        if ($dryRun) {
            $this->info("  [DRY-RUN] Would create FlujoEjecucion with {$orphanProspects->count()} prospects");
            $this->info("  [DRY-RUN] Would create FlujoEjecucionEtapa for stage: {$firstStage['id']}");
            $this->info("  [DRY-RUN] Would update {$orphanProspects->count()} prospecto_en_flujo to 'en_proceso'");

            return ['success' => true, 'count' => $orphanProspects->count()];
        }

        // Execute in transaction
        try {
            DB::beginTransaction();

            // Create FlujoEjecucion
            $ejecucion = FlujoEjecucion::create([
                'flujo_id' => $flujoId,
                'origen_id' => 'recovery-orphans-'.now()->format('Ymd-His'),
                'prospectos_ids' => $prospectIds,
                'prospectos_count' => count($prospectIds),
                'estado' => 'in_progress',
                'fecha_inicio_programada' => now(),
                'fecha_inicio_real' => now(),
                'proximo_nodo' => $firstStage['id'],
                'fecha_proximo_nodo' => now(),
                'config' => $configStructure,
            ]);

            $this->line("  Created FlujoEjecucion #{$ejecucion->id}");

            // Create FlujoEjecucionEtapa for the first stage
            $etapa = FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $ejecucion->id,
                'etapa_id' => null,
                'node_id' => $firstStage['id'],
                'prospectos_ids' => $prospectIds,
                'prospectos_count' => count($prospectIds),
                'fecha_programada' => now(),
                'estado' => 'pending',
            ]);

            $this->line("  Created FlujoEjecucionEtapa #{$etapa->id} (node: {$firstStage['id']})");

            // Update prospecto_en_flujo records
            $updated = ProspectoEnFlujo::whereIn('prospecto_id', $prospectIds)
                ->where('flujo_id', $flujoId)
                ->update([
                    'estado' => 'en_proceso',
                    'fecha_inicio' => now(),
                ]);

            $this->line("  Updated {$updated} prospecto_en_flujo records to 'en_proceso'");

            DB::commit();

            Log::info('RecoverOrphanProspects: Successfully recovered orphan prospects', [
                'flujo_id' => $flujoId,
                'flujo_nombre' => $flujo->nombre,
                'prospectos_count' => count($prospectIds),
                'flujo_ejecucion_id' => $ejecucion->id,
                'flujo_ejecucion_etapa_id' => $etapa->id,
                'first_stage_id' => $firstStage['id'],
            ]);

            $this->info("  SUCCESS: Recovered {$orphanProspects->count()} prospects");

            return ['success' => true, 'count' => $orphanProspects->count()];
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('RecoverOrphanProspects: Failed to recover orphan prospects', [
                'flujo_id' => $flujoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error("  ERROR: {$e->getMessage()}");

            return ['success' => false, 'count' => 0, 'error' => $e->getMessage()];
        }
    }
}
