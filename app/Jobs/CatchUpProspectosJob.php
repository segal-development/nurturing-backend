<?php

namespace App\Jobs;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\ProspectoEnFlujo;
use App\Services\StageOrderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Job to process "behind" prospects through their missed stages.
 *
 * This job runs periodically to find prospects in perpetual executions who are:
 * 1. NEW: ultima_etapa_node_id = NULL (never received any stage)
 * 2. BEHIND: Their ultima_etapa_node_id is earlier than the current execution stage
 *
 * For each group, it dispatches EnviarEtapaJob to send them their NEXT stage.
 * This enables new prospects added mid-flow to catch up to the current position.
 *
 * IMPORTANT: This job does NOT skip stages. Each prospect advances one stage at a time.
 * Multiple runs of this job will progressively advance behind-prospects.
 */
class CatchUpProspectosJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of prospects to process per chunk to avoid memory issues.
     */
    private const CHUNK_SIZE = 1000;

    public function __construct()
    {
        $this->onQueue('catchup');
    }

    public function handle(StageOrderResolver $resolver): void
    {
        Log::info('CatchUpProspectosJob: Iniciando procesamiento de prospectos rezagados');

        // Get all perpetual executions that are in progress
        $ejecuciones = FlujoEjecucion::where('es_perpetuo', true)
            ->where('estado', 'in_progress')
            ->get();

        if ($ejecuciones->isEmpty()) {
            Log::info('CatchUpProspectosJob: No hay ejecuciones perpetuas en progreso');

            return;
        }

        Log::info('CatchUpProspectosJob: Encontradas ejecuciones perpetuas', [
            'count' => $ejecuciones->count(),
            'ejecucion_ids' => $ejecuciones->pluck('id')->toArray(),
        ]);

        $totalProcessed = 0;
        $totalDispatched = 0;

        foreach ($ejecuciones as $ejecucion) {
            $result = $this->procesarEjecucion($ejecucion, $resolver);
            $totalProcessed += $result['processed'];
            $totalDispatched += $result['dispatched'];
        }

        Log::info('CatchUpProspectosJob: Completado', [
            'total_processed' => $totalProcessed,
            'total_dispatched' => $totalDispatched,
        ]);
    }

    /**
     * Process a single perpetual execution to find and advance behind-prospects.
     *
     * @return array{processed: int, dispatched: int}
     */
    private function procesarEjecucion(FlujoEjecucion $ejecucion, StageOrderResolver $resolver): array
    {
        $flujo = $ejecucion->flujo;

        if (! $flujo) {
            Log::warning('CatchUpProspectosJob: Ejecucion sin flujo', [
                'ejecucion_id' => $ejecucion->id,
            ]);

            return ['processed' => 0, 'dispatched' => 0];
        }

        $stageOrder = $resolver->getStageOrder($flujo);
        $firstStageId = $stageOrder[0] ?? null;

        if (! $firstStageId) {
            Log::warning('CatchUpProspectosJob: Flujo sin etapas ejecutables', [
                'ejecucion_id' => $ejecucion->id,
                'flujo_id' => $flujo->id,
            ]);

            return ['processed' => 0, 'dispatched' => 0];
        }

        // Get current execution stage index to determine who is "behind"
        $currentStageId = $ejecucion->nodo_actual;
        $currentStageIndex = $currentStageId
            ? array_search($currentStageId, $stageOrder)
            : 0;

        // If execution hasn't started or nodo_actual is invalid, use first stage
        if ($currentStageIndex === false) {
            $currentStageIndex = 0;
        }

        Log::info('CatchUpProspectosJob: Procesando ejecucion', [
            'ejecucion_id' => $ejecucion->id,
            'flujo_id' => $flujo->id,
            'current_stage' => $currentStageId,
            'current_stage_index' => $currentStageIndex,
            'total_stages' => count($stageOrder),
        ]);

        $totalProcessed = 0;
        $totalDispatched = 0;

        // 1. Process NEW prospects (ultima_etapa_node_id = NULL) - they need stage 1
        $newProspectResult = $this->processNewProspects($ejecucion, $flujo->id, $firstStageId, $resolver);
        $totalProcessed += $newProspectResult['processed'];
        $totalDispatched += $newProspectResult['dispatched'];

        // 2. Process BEHIND prospects (at earlier stages than current)
        // Only if execution has advanced past stage 1
        if ($currentStageIndex > 0) {
            $behindResult = $this->processBehindProspects(
                $ejecucion,
                $flujo->id,
                $stageOrder,
                $currentStageIndex,
                $resolver
            );
            $totalProcessed += $behindResult['processed'];
            $totalDispatched += $behindResult['dispatched'];
        }

        return [
            'processed' => $totalProcessed,
            'dispatched' => $totalDispatched,
        ];
    }

    /**
     * Process prospects with NULL ultima_etapa_node_id (new, never received any stage).
     *
     * @return array{processed: int, dispatched: int}
     */
    private function processNewProspects(
        FlujoEjecucion $ejecucion,
        int $flujoId,
        string $firstStageId,
        StageOrderResolver $resolver
    ): array {
        // Find prospects in this flow with NULL ultima_etapa_node_id
        $query = ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereNull('ultima_etapa_node_id')
            ->where('completado', false)
            ->where('cancelado', false);

        $count = $query->count();

        if ($count === 0) {
            return ['processed' => 0, 'dispatched' => 0];
        }

        Log::info('CatchUpProspectosJob: Encontrados prospectos nuevos', [
            'ejecucion_id' => $ejecucion->id,
            'count' => $count,
            'target_stage' => $firstStageId,
        ]);

        $dispatched = 0;

        // Process in chunks to avoid memory issues
        $query->select(['id', 'prospecto_id'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $prospects) use (
                $ejecucion,
                $firstStageId,
                $resolver,
                &$dispatched
            ) {
                $prospectoIds = $prospects->pluck('prospecto_id')->toArray();
                $this->dispatchStageForProspects($ejecucion, $firstStageId, $prospectoIds, $resolver);
                $dispatched++;
            }, 'id');

        return [
            'processed' => $count,
            'dispatched' => $dispatched,
        ];
    }

    /**
     * Process prospects who are behind the current execution stage.
     *
     * @param  array  $stageOrder  Ordered array of stage node_ids
     * @param  int  $currentStageIndex  Index of current execution stage
     * @return array{processed: int, dispatched: int}
     */
    private function processBehindProspects(
        FlujoEjecucion $ejecucion,
        int $flujoId,
        array $stageOrder,
        int $currentStageIndex,
        StageOrderResolver $resolver
    ): array {
        $totalProcessed = 0;
        $totalDispatched = 0;

        // Get all stages BEFORE the current stage (stages that behind-prospects might be at)
        $behindStages = array_slice($stageOrder, 0, $currentStageIndex);

        // For each "behind" stage, find prospects at that stage and advance them
        foreach ($behindStages as $stageIndex => $stageNodeId) {
            $nextStageId = $stageOrder[$stageIndex + 1] ?? null;

            if (! $nextStageId) {
                continue;
            }

            // Find prospects at this stage
            $query = ProspectoEnFlujo::where('flujo_id', $flujoId)
                ->where('ultima_etapa_node_id', $stageNodeId)
                ->where('completado', false)
                ->where('cancelado', false);

            $count = $query->count();

            if ($count === 0) {
                continue;
            }

            Log::info('CatchUpProspectosJob: Encontrados prospectos rezagados', [
                'ejecucion_id' => $ejecucion->id,
                'current_stage' => $stageNodeId,
                'next_stage' => $nextStageId,
                'count' => $count,
            ]);

            // Process in chunks
            $dispatchedForStage = 0;
            $query->select(['id', 'prospecto_id'])
                ->chunkById(self::CHUNK_SIZE, function (Collection $prospects) use (
                    $ejecucion,
                    $nextStageId,
                    $resolver,
                    &$dispatchedForStage
                ) {
                    $prospectoIds = $prospects->pluck('prospecto_id')->toArray();
                    $this->dispatchStageForProspects($ejecucion, $nextStageId, $prospectoIds, $resolver);
                    $dispatchedForStage++;
                }, 'id');

            $totalProcessed += $count;
            $totalDispatched += $dispatchedForStage;
        }

        return [
            'processed' => $totalProcessed,
            'dispatched' => $totalDispatched,
        ];
    }

    /**
     * Dispatch EnviarEtapaJob for a group of prospects to receive a specific stage.
     *
     * @param  array  $prospectoIds  Array of prospect IDs
     */
    private function dispatchStageForProspects(
        FlujoEjecucion $ejecucion,
        string $stageNodeId,
        array $prospectoIds,
        StageOrderResolver $resolver
    ): void {
        if (empty($prospectoIds)) {
            return;
        }

        $flujo = $ejecucion->flujo;
        $configStructure = $flujo->config_structure ?? [];
        $stages = $configStructure['stages'] ?? [];
        $branches = $configStructure['branches'] ?? [];

        // Find stage data
        $stage = collect($stages)->firstWhere('id', $stageNodeId);

        if (! $stage) {
            Log::warning('CatchUpProspectosJob: Stage no encontrado', [
                'ejecucion_id' => $ejecucion->id,
                'stage_node_id' => $stageNodeId,
            ]);

            return;
        }

        // Find or create FlujoEjecucionEtapa for this stage
        $etapaEjecucion = $this->findOrCreateEtapaEjecucion($ejecucion, $stageNodeId, $prospectoIds);

        if (! $etapaEjecucion) {
            Log::error('CatchUpProspectosJob: No se pudo crear etapa de ejecucion', [
                'ejecucion_id' => $ejecucion->id,
                'stage_node_id' => $stageNodeId,
            ]);

            return;
        }

        Log::info('CatchUpProspectosJob: Despachando EnviarEtapaJob', [
            'ejecucion_id' => $ejecucion->id,
            'etapa_ejecucion_id' => $etapaEjecucion->id,
            'stage_node_id' => $stageNodeId,
            'prospectos_count' => count($prospectoIds),
        ]);

        // Dispatch EnviarEtapaJob (uses 'envios' queue by default)
        EnviarEtapaJob::dispatch(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: $stage,
            prospectoIds: $prospectoIds,
            branches: $branches
        )->onQueue('catchup'); // Use catchup queue to not interfere with main flow
    }

    /**
     * Find existing or create new FlujoEjecucionEtapa for catch-up processing.
     */
    private function findOrCreateEtapaEjecucion(
        FlujoEjecucion $ejecucion,
        string $stageNodeId,
        array $prospectoIds
    ): ?FlujoEjecucionEtapa {
        // Check if there's already a pending etapa for this stage
        $existingEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $stageNodeId)
            ->where('estado', 'pending')
            ->first();

        if ($existingEtapa) {
            // Merge prospectoIds into existing etapa
            $existingIds = $existingEtapa->prospectos_ids ?? [];
            $mergedIds = array_values(array_unique(array_merge($existingIds, $prospectoIds)));

            $existingEtapa->update([
                'prospectos_ids' => $mergedIds,
                'prospectos_count' => count($mergedIds),
            ]);

            Log::debug('CatchUpProspectosJob: Actualizando etapa existente', [
                'etapa_id' => $existingEtapa->id,
                'prospectos_added' => count($prospectoIds),
                'total_prospectos' => count($mergedIds),
            ]);

            return $existingEtapa;
        }

        // Create new etapa for catch-up
        try {
            $etapa = FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $ejecucion->id,
                'etapa_id' => null,
                'node_id' => $stageNodeId,
                'fecha_programada' => now(),
                'estado' => 'pending',
                'ejecutado' => false,
                'prospectos_ids' => $prospectoIds,
                'prospectos_count' => count($prospectoIds),
                'response_athenacampaign' => [
                    'source' => 'catch_up_job',
                    'created_at' => now()->toISOString(),
                ],
            ]);

            Log::debug('CatchUpProspectosJob: Creada nueva etapa de ejecucion', [
                'etapa_id' => $etapa->id,
                'node_id' => $stageNodeId,
                'prospectos_count' => count($prospectoIds),
            ]);

            return $etapa;
        } catch (\Exception $e) {
            Log::error('CatchUpProspectosJob: Error creando etapa de ejecucion', [
                'ejecucion_id' => $ejecucion->id,
                'node_id' => $stageNodeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CatchUpProspectosJob: Fallo permanente', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
