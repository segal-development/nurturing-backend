<?php

namespace App\Jobs;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\ProspectoEnFlujo;
use App\Services\StageOrderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(StageOrderResolver $resolver): void
    {
        Log::info('CatchUpProspectosJob: Iniciando procesamiento de prospectos rezagados');

        // Get all executions of perpetual flujos that are in progress OR waiting.
        // We filter by flujo.es_perpetuo (source of truth) rather than the
        // ejecucion's own flag, which can get out of sync when an execution
        // was created before the flujo was marked perpetuo.
        // 'waiting' state is normal for perpetual flujos that finished one
        // cycle and are awaiting new prospects.
        $ejecuciones = FlujoEjecucion::query()
            ->whereIn('estado', ['in_progress', 'waiting'])
            ->whereHas('flujo', fn ($q) => $q->where('es_perpetuo', true)->where('activo', true))
            ->get();

        if ($ejecuciones->isEmpty()) {
            Log::info('CatchUpProspectosJob: No hay ejecuciones perpetuas en progreso');

            return;
        }

        Log::info('CatchUpProspectosJob: Encontradas ejecuciones perpetuas', [
            'count' => $ejecuciones->count(),
            'ejecucion_ids' => $ejecuciones->pluck('id')->toArray(),
        ]);

        // Gobernador de tasa: tope GLOBAL de prospectos por corrida, compartido entre
        // todas las ejecuciones perpetuas. Evita el flood al marcar un flujo perpetuo
        // con backlog grande; el backlog drena a esta tasa (cada 5 min) y el rate-limiter
        // de envíos pacea la entrega real. Tuneable vía NURTURING_CATCHUP_MAX_PER_RUN.
        $maxPerRun = (int) config('nurturing.catchup.max_prospectos_per_run', 50);
        if ($maxPerRun <= 0) {
            $maxPerRun = 50;
        }
        $remaining = $maxPerRun;

        $totalProcessed = 0;
        $totalDispatched = 0;

        foreach ($ejecuciones as $ejecucion) {
            if ($remaining <= 0) {
                Log::info('CatchUpProspectosJob: Tope por corrida alcanzado, corto el resto', [
                    'max_per_run' => $maxPerRun,
                ]);
                break;
            }
            $result = $this->procesarEjecucion($ejecucion, $resolver, $remaining);
            $totalProcessed += $result['processed'];
            $totalDispatched += $result['dispatched'];
        }

        Log::info('CatchUpProspectosJob: Completado', [
            'total_processed' => $totalProcessed,
            'total_dispatched' => $totalDispatched,
            'max_per_run' => $maxPerRun,
            'budget_restante' => $remaining,
        ]);
    }

    /**
     * Process a single perpetual execution to find and advance behind-prospects.
     *
     * @return array{processed: int, dispatched: int}
     */
    private function procesarEjecucion(FlujoEjecucion $ejecucion, StageOrderResolver $resolver, int &$remaining): array
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

        // 1. Process NEW prospects (ultima_etapa_node_id = NULL) - they need stage 1.
        //    Van primero: así un recién llegado (fecha_inicio reciente) se nutre antes
        //    que el backlog viejo, sin quedar starve-ado por el drenado.
        $newProspectResult = $this->processNewProspects($ejecucion, $flujo->id, $firstStageId, $resolver, $remaining);
        $totalProcessed += $newProspectResult['processed'];
        $totalDispatched += $newProspectResult['dispatched'];

        // 2. Process BEHIND prospects (at earlier stages than current)
        // Only if execution has advanced past stage 1 y queda presupuesto en la corrida.
        if ($currentStageIndex > 0 && $remaining > 0) {
            $behindResult = $this->processBehindProspects(
                $ejecucion,
                $flujo->id,
                $stageOrder,
                $currentStageIndex,
                $resolver,
                $remaining
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
        StageOrderResolver $resolver,
        int &$remaining
    ): array {
        if ($remaining <= 0) {
            return ['processed' => 0, 'dispatched' => 0];
        }

        $flujo = $ejecucion->flujo;
        $stages = $flujo->config_structure['stages'] ?? [];
        $firstStage = collect($stages)->firstWhere('id', $firstStageId);
        $tiempoEspera = $firstStage['tiempo_espera'] ?? 0;

        // Gobernador: traemos a lo sumo $remaining por corrida, MÁS NUEVOS PRIMERO
        // (fecha_inicio desc), para que un recién llegado se nutra antes que el backlog
        // viejo y para no inundar el pipeline al activar/recuperar un flujo perpetuo.
        $prospects = ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereNull('ultima_etapa_node_id')
            ->where('completado', false)
            ->where('cancelado', false)
            ->orderByDesc('fecha_inicio')
            ->limit($remaining)
            ->get(['id', 'prospecto_id', 'fecha_inicio']);

        if ($prospects->isEmpty()) {
            return ['processed' => 0, 'dispatched' => 0];
        }

        $prospectoIds = $prospects->pluck('prospecto_id')->toArray();
        $earliestFechaInicio = $prospects->min('fecha_inicio');
        $fechaProgramada = \Carbon\Carbon::parse($earliestFechaInicio)->addDays($tiempoEspera);

        Log::info('CatchUpProspectosJob: Procesando prospectos nuevos (gobernado)', [
            'ejecucion_id' => $ejecucion->id,
            'count' => count($prospectoIds),
            'budget_restante' => $remaining,
            'target_stage' => $firstStageId,
            'tiempo_espera_dias' => $tiempoEspera,
        ]);

        $this->scheduleStageForProspects($ejecucion, $firstStageId, $prospectoIds, $fechaProgramada, $resolver);
        $remaining -= count($prospectoIds);

        return [
            'processed' => count($prospectoIds),
            'dispatched' => 1,
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
        StageOrderResolver $resolver,
        int &$remaining
    ): array {
        $totalProcessed = 0;
        $totalDispatched = 0;

        $flujo = $ejecucion->flujo;
        $stages = $flujo->config_structure['stages'] ?? [];

        // Get all stages BEFORE the current stage (stages that behind-prospects might be at)
        $behindStages = array_slice($stageOrder, 0, $currentStageIndex);

        // For each "behind" stage, find prospects at that stage and advance them
        foreach ($behindStages as $stageIndex => $stageNodeId) {
            // Gobernador: si se acabó el presupuesto de la corrida, paramos.
            if ($remaining <= 0) {
                break;
            }

            $nextStageId = $stageOrder[$stageIndex + 1] ?? null;

            if (! $nextStageId) {
                continue;
            }

            // Get tiempo_espera for the NEXT stage
            $nextStage = collect($stages)->firstWhere('id', $nextStageId);
            $tiempoEspera = $nextStage['tiempo_espera'] ?? 0;

            // A lo sumo $remaining por corrida, MÁS RECIENTES PRIMERO (updated_at desc).
            // updated_at se setea al actualizar ultima_etapa_node_id tras un envío exitoso.
            $prospects = ProspectoEnFlujo::where('flujo_id', $flujoId)
                ->where('ultima_etapa_node_id', $stageNodeId)
                ->where('completado', false)
                ->where('cancelado', false)
                ->orderByDesc('updated_at')
                ->limit($remaining)
                ->get(['id', 'prospecto_id', 'updated_at']);

            if ($prospects->isEmpty()) {
                continue;
            }

            $prospectoIds = $prospects->pluck('prospecto_id')->toArray();
            $latestCompletion = $prospects->max('updated_at');
            $fechaProgramada = \Carbon\Carbon::parse($latestCompletion)->addDays($tiempoEspera);

            Log::info('CatchUpProspectosJob: Procesando prospectos rezagados (gobernado)', [
                'ejecucion_id' => $ejecucion->id,
                'current_stage' => $stageNodeId,
                'next_stage' => $nextStageId,
                'count' => count($prospectoIds),
                'budget_restante' => $remaining,
                'tiempo_espera_dias' => $tiempoEspera,
            ]);

            $this->scheduleStageForProspects($ejecucion, $nextStageId, $prospectoIds, $fechaProgramada, $resolver);
            $remaining -= count($prospectoIds);

            $totalProcessed += count($prospectoIds);
            $totalDispatched++;
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
     * EXCEPTION: For COMPLETED stages in perpetual flows, we dispatch immediately
     * because those stages won't be picked up by EjecutarNodosProgramados.
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
        $nodes = $configStructure['nodes'] ?? [];
        $branches = $configStructure['branches'] ?? [];

        // Find stage data - check both 'stages' and 'nodes' arrays
        $stage = collect($stages)->firstWhere('id', $stageNodeId);
        if (! $stage) {
            $stage = collect($nodes)->firstWhere('id', $stageNodeId);
        }

        if (! $stage) {
            Log::warning('CatchUpProspectosJob: Stage no encontrado', [
                'ejecucion_id' => $ejecucion->id,
                'stage_node_id' => $stageNodeId,
            ]);

            return;
        }

        // Find or create FlujoEjecucionEtapa for this stage with proper fecha_programada
        $result = $this->findOrCreateEtapaEjecucion($ejecucion, $stageNodeId, $prospectoIds, $fechaProgramada);

        if (! $result || ! $result['etapa']) {
            Log::error('CatchUpProspectosJob: No se pudo crear etapa de ejecucion', [
                'ejecucion_id' => $ejecucion->id,
                'stage_node_id' => $stageNodeId,
            ]);

            return;
        }

        $etapaEjecucion = $result['etapa'];
        $forceDispatch = $result['force_dispatch'] ?? false;

        $shouldDispatchNow = $forceDispatch || $fechaProgramada->isPast() || $fechaProgramada->isToday();

        Log::info('CatchUpProspectosJob: Etapa programada', [
            'ejecucion_id' => $ejecucion->id,
            'etapa_ejecucion_id' => $etapaEjecucion->id,
            'stage_node_id' => $stageNodeId,
            'prospectos_count' => count($prospectoIds),
            'fecha_programada' => $fechaProgramada->toDateTimeString(),
            'dispatch_now' => $shouldDispatchNow,
            'force_dispatch' => $forceDispatch,
            'etapa_estado' => $etapaEjecucion->estado,
        ]);

        // Dispatch if: fecha_programada is past/today OR etapa was already completed (perpetual catch-up)
        if ($shouldDispatchNow) {
            // FIX: Marcar como executing ANTES de despachar para que el visual refleje la realidad
            // Esto evita el bug donde la etapa queda en 'pending' pero ya tiene envíos
            if ($etapaEjecucion->estado === 'pending') {
                $etapaEjecucion->update(['estado' => 'executing']);
            }

            EnviarEtapaJob::dispatch(
                flujoEjecucionId: $ejecucion->id,
                etapaEjecucionId: $etapaEjecucion->id,
                stage: $stage,
                prospectoIds: $prospectoIds,
                branches: $branches
            )->onQueue('envios');

            Log::info('CatchUpProspectosJob: EnviarEtapaJob despachado', [
                'ejecucion_id' => $ejecucion->id,
                'etapa_ejecucion_id' => $etapaEjecucion->id,
                'prospectos_count' => count($prospectoIds),
                'queue' => 'envios',
                'etapa_estado' => $etapaEjecucion->estado,
            ]);
        }
    }

    /**
     * Find existing or create new FlujoEjecucionEtapa for catch-up processing.
     *
     * For COMPLETED stages in perpetual flows, we reuse the existing etapa
     * and set force_dispatch=true so EnviarEtapaJob is dispatched immediately.
     *
     * @param  \Carbon\Carbon  $fechaProgramada  When this stage should execute
     * @return array{etapa: ?FlujoEjecucionEtapa, force_dispatch: bool}|null
     */
    private function findOrCreateEtapaEjecucion(
        FlujoEjecucion $ejecucion,
        string $stageNodeId,
        array $prospectoIds,
        \Carbon\Carbon $fechaProgramada
    ): ?array {
        // First, check if there's ANY existing etapa for this stage
        // Use orderBy('id') to always get the OLDEST (original) one if duplicates exist
        $existingEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $stageNodeId)
            ->orderBy('id')
            ->first();

        if ($existingEtapa) {
            // For COMPLETED stages in perpetual flows, we reuse the etapa
            // and FORCE dispatch because EjecutarNodosProgramados won't pick it up
            if ($existingEtapa->estado === 'completed') {
                Log::info('CatchUpProspectosJob: Reusing completed etapa for new prospects - WILL FORCE DISPATCH', [
                    'etapa_id' => $existingEtapa->id,
                    'node_id' => $stageNodeId,
                    'new_prospectos_count' => count($prospectoIds),
                    'etapa_estado' => $existingEtapa->estado,
                ]);

                $existingEtapa->prospectos()->syncWithoutDetaching($prospectoIds);
                $mergedIds = $existingEtapa->prospectos()->pluck('prospectos.id')->toArray();

                $existingEtapa->update([
                    'prospectos_ids' => $mergedIds,
                    'prospectos_count' => count($mergedIds),
                ]);

                // FORCE DISPATCH for completed etapas - this is the key fix!
                return ['etapa' => $existingEtapa, 'force_dispatch' => true];
            }

            // For PENDING stages, merge prospectoIds (no force dispatch - scheduler will pick up)
            if ($existingEtapa->estado === 'pending') {
                $existingEtapa->prospectos()->syncWithoutDetaching($prospectoIds);
                $mergedIds = $existingEtapa->prospectos()->pluck('prospectos.id')->toArray();

                $newFechaProgramada = $fechaProgramada->lt($existingEtapa->fecha_programada)
                    ? $fechaProgramada
                    : $existingEtapa->fecha_programada;

                $existingEtapa->update([
                    'prospectos_ids' => $mergedIds,
                    'prospectos_count' => count($mergedIds),
                    'fecha_programada' => $newFechaProgramada,
                ]);

                Log::debug('CatchUpProspectosJob: Actualizando etapa pendiente', [
                    'etapa_id' => $existingEtapa->id,
                    'prospectos_added' => count($prospectoIds),
                    'total_prospectos' => count($mergedIds),
                    'fecha_programada' => $newFechaProgramada->toDateTimeString(),
                ]);

                return ['etapa' => $existingEtapa, 'force_dispatch' => false];
            }

            // For other states (executing, failed), return the etapa but log warning
            Log::warning('CatchUpProspectosJob: Etapa in unexpected state', [
                'etapa_id' => $existingEtapa->id,
                'estado' => $existingEtapa->estado,
            ]);

            return ['etapa' => $existingEtapa, 'force_dispatch' => false];
        }

        // Use firstOrCreate to avoid race conditions with other jobs
        try {
            $etapa = FlujoEjecucionEtapa::firstOrCreate(
                [
                    'flujo_ejecucion_id' => $ejecucion->id,
                    'node_id' => $stageNodeId,
                ],
                [
                    'etapa_id' => null,
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
                ]
            );

            // If etapa already existed, merge prospectos
            if (! $etapa->wasRecentlyCreated) {
                $etapa->prospectos()->syncWithoutDetaching($prospectoIds);
                $mergedIds = $etapa->prospectos()->pluck('prospectos.id')->toArray();
                $etapa->update([
                    'prospectos_ids' => $mergedIds,
                    'prospectos_count' => count($mergedIds),
                ]);

                Log::debug('CatchUpProspectosJob: Etapa existente actualizada con nuevos prospectos', [
                    'etapa_id' => $etapa->id,
                    'node_id' => $stageNodeId,
                    'estado' => $etapa->estado,
                    'prospectos_count' => count($mergedIds),
                ]);

                // Force dispatch if etapa is completed (perpetual flow catch-up)
                $forceDispatch = $etapa->estado === 'completed';

                return ['etapa' => $etapa, 'force_dispatch' => $forceDispatch];
            }

            Log::debug('CatchUpProspectosJob: Creada nueva etapa de ejecucion', [
                'etapa_id' => $etapa->id,
                'node_id' => $stageNodeId,
                'prospectos_count' => count($prospectoIds),
                'fecha_programada' => $fechaProgramada->toDateTimeString(),
            ]);

            return ['etapa' => $etapa, 'force_dispatch' => false];
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
