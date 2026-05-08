<?php

namespace App\Services;

use App\Models\Flujo;

/**
 * Service to resolve stage ordering from flow's config_structure.
 *
 * Centralizes stage order logic used by:
 * - EnviarEtapaJob (filtering by previous stage completion)
 * - CatchUpProspectosJob (determining next stage for behind-prospects)
 * - BackfillUltimaEtapaCommand (validating stage progression)
 *
 * Based on existing logic from AsignarNuevosProspectosAFlujoJob::construirOrdenEjecucion()
 */
class StageOrderResolver
{
    /**
     * Get ordered array of stage node_ids for a flujo.
     * Follows branches from initial node, skipping start nodes.
     * Includes: email, sms, stage, end (for completion detection)
     * Excludes: start, condition (not direct "stages" in progression)
     *
     * @return string[] Array of node_ids in execution order
     */
    public function getStageOrder(Flujo $flujo): array
    {
        $configStructure = $flujo->config_structure;

        if (empty($configStructure) || empty($configStructure['stages'])) {
            return [];
        }

        $stages = $configStructure['stages'];
        $branches = $configStructure['branches'] ?? [];
        $initialNodeConfig = $configStructure['initial_node'] ?? null;
        
        // initial_node can be a string (node_id) or an array with 'id' key
        $initialNode = is_array($initialNodeConfig) 
            ? ($initialNodeConfig['id'] ?? null) 
            : $initialNodeConfig;

        // Find first stage (after start node)
        $firstStageId = $this->findFirstStageId($stages, $branches, $initialNode);

        if (! $firstStageId) {
            return [];
        }

        return $this->buildStageOrder($stages, $branches, $firstStageId);
    }

    /**
     * Get the first executable stage node_id.
     * This is the first email/sms/stage type node after the start node.
     */
    public function getFirstStage(Flujo $flujo): ?string
    {
        $stageOrder = $this->getStageOrder($flujo);

        // Filter to only executable stages (email, sms, stage)
        $executableStages = $this->filterExecutableStages($flujo, $stageOrder);

        return $executableStages[0] ?? null;
    }

    /**
     * Get the previous stage node_id for a given stage.
     * Returns null if the given stage is the first stage.
     */
    public function getPreviousStage(Flujo $flujo, string $nodeId): ?string
    {
        $stageOrder = $this->getStageOrder($flujo);
        $executableStages = $this->filterExecutableStages($flujo, $stageOrder);

        $index = array_search($nodeId, $executableStages);

        if ($index === false || $index === 0) {
            return null;
        }

        return $executableStages[$index - 1];
    }

    /**
     * Get the next stage node_id for a given stage.
     * Returns null if the given stage is the last stage.
     */
    public function getNextStage(Flujo $flujo, string $nodeId): ?string
    {
        $stageOrder = $this->getStageOrder($flujo);
        $executableStages = $this->filterExecutableStages($flujo, $stageOrder);

        $index = array_search($nodeId, $executableStages);

        if ($index === false || $index === count($executableStages) - 1) {
            return null;
        }

        return $executableStages[$index + 1];
    }

    /**
     * Check if a prospect with given ultima_etapa_node_id is eligible
     * to receive the target stage.
     *
     * Rules:
     * - NULL ultima_etapa = only eligible for first stage
     * - Has completed previous stage = eligible
     * - Same stage = not eligible (already completed)
     * - Future stage = not eligible (not there yet)
     */
    public function isEligibleForStage(
        Flujo $flujo,
        ?string $ultimaEtapaNodeId,
        string $targetStageNodeId
    ): bool {
        $stageOrder = $this->getStageOrder($flujo);
        $executableStages = $this->filterExecutableStages($flujo, $stageOrder);

        if (empty($executableStages)) {
            return false;
        }

        $targetIndex = array_search($targetStageNodeId, $executableStages);

        if ($targetIndex === false) {
            return false;
        }

        // Case 1: NULL ultima_etapa = only eligible for first stage
        if ($ultimaEtapaNodeId === null) {
            return $targetIndex === 0;
        }

        $ultimaIndex = array_search($ultimaEtapaNodeId, $executableStages);

        // If ultima_etapa is not found in current stages (legacy/orphan), treat as NULL
        if ($ultimaIndex === false) {
            return $targetIndex === 0;
        }

        // Case 2: Must be the NEXT stage after ultima_etapa
        // (prospect completed stage N, eligible for stage N+1)
        return $targetIndex === $ultimaIndex + 1;
    }

    /**
     * Get the stage position/index in the flow.
     * Useful for comparing progress between prospects.
     *
     * @return int|null Position (0-indexed) or null if not found
     */
    public function getStagePosition(Flujo $flujo, string $nodeId): ?int
    {
        $stageOrder = $this->getStageOrder($flujo);
        $executableStages = $this->filterExecutableStages($flujo, $stageOrder);

        $index = array_search($nodeId, $executableStages);

        return $index !== false ? $index : null;
    }

    /**
     * Find the first stage ID after the start node.
     */
    private function findFirstStageId(array $stages, array $branches, ?string $initialNode): ?string
    {
        $startNodeId = $initialNode;

        if (! $startNodeId) {
            // Fallback: buscar nodo tipo 'start'
            $startNode = collect($stages)->firstWhere('type', 'start');
            $startNodeId = $startNode['id'] ?? null;
        }

        if (! $startNodeId) {
            // No start node, try to find first executable stage by orden
            $firstExecutable = collect($stages)
                ->filter(fn ($s) => in_array($s['type'] ?? '', ['email', 'sms', 'stage', 'ambos']))
                ->sortBy('orden')
                ->first();

            return $firstExecutable['id'] ?? null;
        }

        // Follow branch from start node
        $firstConnection = collect($branches)->firstWhere('source_node_id', $startNodeId);

        if ($firstConnection) {
            return $firstConnection['target_node_id'];
        }

        // Fallback: first executable stage
        $firstExecutable = collect($stages)
            ->filter(fn ($s) => in_array($s['type'] ?? '', ['email', 'sms', 'stage', 'ambos']))
            ->sortBy('orden')
            ->first();

        return $firstExecutable['id'] ?? null;
    }

    /**
     * Build the order of execution following branches.
     * Includes all node types for complete traversal, but isExecutable filters appropriately.
     *
     * @return string[] Array of node_ids in execution order
     */
    private function buildStageOrder(array $stages, array $branches, string $firstStageId): array
    {
        $orden = [];
        $visitados = [];
        $nodoActual = $firstStageId;

        while ($nodoActual && ! in_array($nodoActual, $visitados)) {
            $stage = collect($stages)->firstWhere('id', $nodoActual);

            if ($stage) {
                $type = $stage['type'] ?? null;

                // Include executable stages (email, sms, stage, ambos) and end nodes
                // Condition nodes are skipped (they're routing, not stages)
                if (in_array($type, ['email', 'sms', 'stage', 'ambos', 'end'])) {
                    $orden[] = $nodoActual;
                }
            }

            $visitados[] = $nodoActual;

            // Find next connection
            $siguienteConexion = collect($branches)->firstWhere('source_node_id', $nodoActual);
            $nodoActual = $siguienteConexion['target_node_id'] ?? null;
        }

        return $orden;
    }

    /**
     * Filter stage order to only executable stages (email, sms, stage, ambos).
     * Excludes 'end' nodes as they're not actual stages to execute.
     *
     * @return string[] Array of executable node_ids
     */
    private function filterExecutableStages(Flujo $flujo, array $stageOrder): array
    {
        $configStructure = $flujo->config_structure;
        $stages = $configStructure['stages'] ?? [];

        return array_values(array_filter($stageOrder, function ($nodeId) use ($stages) {
            $stage = collect($stages)->firstWhere('id', $nodeId);
            $type = $stage['type'] ?? null;

            return in_array($type, ['email', 'sms', 'stage', 'ambos']);
        }));
    }
}
