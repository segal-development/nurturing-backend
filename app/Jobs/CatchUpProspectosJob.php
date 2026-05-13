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
 * For each group, it schedules them for their NEXT stage respecting the original
 * tiempo_espera delays between stages. This ensures prospects from sync don't
 * receive all messages at once.
 *
 * IMPORTANT: This job does NOT skip stages. Each prospect advances one stage at a time.
 * Multiple runs of this job will progressively advance behind-prospects.
 *
 * TIMING: Prospects are scheduled based on when they completed their previous stage
 * (or when they entered the flow for new prospects) + the tiempo_espera of the next stage.
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
        $this->onQueue('default');
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
        $flujo = $ejecucion->flujo;
        $stages = $flujo->config_structure['stages'] ?? [];
        $firstStage = collect($stages)->firstWhere('id', $firstStageId);
        $tiempoEspera = $firstStage['tiempo_espera'] ?? 0;

        // Find prospects in this flow with NULL ultima_etapa_node_id
        // Include fecha_inicio to calculate proper scheduling
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
            'tiempo_espera_dias' => $tiempoEspera,
        ]);

        $dispatched = 0;

        // Process in chunks to avoid memory issues
        $query->select(['id', 'prospecto_id', 'fecha_inicio'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $prospects) use (
                $ejecucion,
                $firstStageId,
                $tiempoEspera,
                $resolver,
                &$dispatched
            ) {
                $prospectoIds = $prospects->pluck('prospecto_id')->toArray();
                
                // Calculate fecha_programada based on earliest fecha_inicio in this batch + tiempo_espera
                $earliestFechaInicio = $prospects->min('fecha_inicio');
                $fechaProgramada = \Carbon\Carbon::parse($earliestFechaInicio)->addDays($tiempoEspera);
                
                $this->scheduleStageForProspects($ejecucion, $firstStageId, $prospectoIds, $fechaProgramada, $resolver);
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

        $flujo = $ejecucion->flujo;
        $stages = $flujo->config_structure['stages'] ?? [];

        // Get all stages BEFORE the current stage (stages that behind-prospects might be at)
        $behindStages = array_slice($stageOrder, 0, $currentStageIndex);

        // For each "behind" stage, find prospects at that stage and advance them
        foreach ($behindStages as $stageIndex => $stageNodeId) {
            $nextStageId = $stageOrder[$stageIndex + 1] ?? null;

            if (! $nextStageId) {
                continue;
            }

            // Get tiempo_espera for the NEXT stage
            $nextStage = collect($stages)->firstWhere('id', $nextStageId);
            $tiempoEspera = $nextStage['tiempo_espera'] ?? 0;

            // Find prospects at this stage - use updated_at to calculate timing
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
                'tiempo_espera_dias' => $tiempoEspera,
            ]);

            // Process in chunks
            $dispatchedForStage = 0;
            $query->select(['id', 'prospecto_id', 'updated_at'])
                ->chunkById(self::CHUNK_SIZE, function (Collection $prospects) use (
                    $ejecucion,
                    $nextStageId,
                    $tiempoEspera,
                    $resolver,
                    &$dispatchedForStage
                ) {
                    $prospectoIds = $prospects->pluck('prospecto_id')->toArray();
                    
                    // Calculate fecha_programada based on when they completed the previous stage
                    // updated_at is set when ultima_etapa_node_id is updated after successful send
                    $latestCompletion = $prospects->max('updated_at');
                    $fechaProgramada = \Carbon\Carbon::parse($latestCompletion)->addDays($tiempoEspera);
                    
                    $this->scheduleStageForProspects($ejecucion, $nextStageId, $prospectoIds, $fechaProgramada, $resolver);
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
     * Schedule prospects for a specific stage with proper fecha_programada.
     *
     * This method creates/updates the FlujoEjecucionEtapa with the correct
     * fecha_programada based on tiempo_espera. It does NOT dispatch immediately -
     * the EjecutarNodosProgramados job will pick it up when the time comes.
     *
     * @param  array  $prospectoIds  Array of prospect IDs
     * @param  \Carbon\Carbon  $fechaProgramada  When this stage should execute
     */
    private function scheduleStageForProspects(
        FlujoEjecucion $ejecucion,
        string $stageNodeId,
        array $prospectoIds,
        \Carbon\Carbon $fechaProgramada,
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

        // Find or create FlujoEjecucionEtapa for this stage with proper fecha_programada
        $etapaEjecucion = $this->findOrCreateEtapaEjecucion($ejecucion, $stageNodeId, $prospectoIds, $fechaProgramada);

        if (! $etapaEjecucion) {
            Log::error('CatchUpProspectosJob: No se pudo crear etapa de ejecucion', [
                'ejecucion_id' => $ejecucion->id,
                'stage_node_id' => $stageNodeId,
            ]);

            return;
        }

        $shouldDispatchNow = $fechaProgramada->isPast() || $fechaProgramada->isToday();

        Log::info('CatchUpProspectosJob: Etapa programada', [
            'ejecucion_id' => $ejecucion->id,
            'etapa_ejecucion_id' => $etapaEjecucion->id,
            'stage_node_id' => $stageNodeId,
            'prospectos_count' => count($prospectoIds),
            'fecha_programada' => $fechaProgramada->toDateTimeString(),
            'dispatch_now' => $shouldDispatchNow,
        ]);

        // Only dispatch immediately if the fecha_programada is in the past or today
        // Otherwise, EjecutarNodosProgramados will pick it up when the time comes
        if ($shouldDispatchNow) {
            EnviarEtapaJob::dispatch(
                flujoEjecucionId: $ejecucion->id,
                etapaEjecucionId: $etapaEjecucion->id,
                stage: $stage,
                prospectoIds: $prospectoIds,
                branches: $branches
            )->onQueue('default');
        }
    }

    /**
     * Find existing or create new FlujoEjecucionEtapa for catch-up processing.
     *
     * @param  \Carbon\Carbon  $fechaProgramada  When this stage should execute
     */
    private function findOrCreateEtapaEjecucion(
        FlujoEjecucion $ejecucion,
        string $stageNodeId,
        array $prospectoIds,
        \Carbon\Carbon $fechaProgramada
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

            // Use the earlier fecha_programada if prospects have different schedules
            $newFechaProgramada = $fechaProgramada->lt($existingEtapa->fecha_programada)
                ? $fechaProgramada
                : $existingEtapa->fecha_programada;

            $existingEtapa->update([
                'prospectos_ids' => $mergedIds,
                'prospectos_count' => count($mergedIds),
                'fecha_programada' => $newFechaProgramada,
            ]);

            Log::debug('CatchUpProspectosJob: Actualizando etapa existente', [
                'etapa_id' => $existingEtapa->id,
                'prospectos_added' => count($prospectoIds),
                'total_prospectos' => count($mergedIds),
                'fecha_programada' => $newFechaProgramada->toDateTimeString(),
            ]);

            return $existingEtapa;
        }

        // Create new etapa for catch-up with proper fecha_programada
        try {
            $etapa = FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $ejecucion->id,
                'etapa_id' => null,
                'node_id' => $stageNodeId,
                'fecha_programada' => $fechaProgramada,
                'estado' => 'pending',
                'ejecutado' => false,
                'prospectos_ids' => $prospectoIds,
                'prospectos_count' => count($prospectoIds),
                'response_athenacampaign' => [
                    'source' => 'catch_up_job',
                    'created_at' => now()->toISOString(),
                    'tiempo_espera_respetado' => true,
                ],
            ]);

            Log::debug('CatchUpProspectosJob: Creada nueva etapa de ejecucion', [
                'etapa_id' => $etapa->id,
                'node_id' => $stageNodeId,
                'prospectos_count' => count($prospectoIds),
                'fecha_programada' => $fechaProgramada->toDateTimeString(),
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
