<?php

namespace App\Services;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\ProspectoEnFlujo;
use Illuminate\Support\Facades\Log;

/**
 * Autoridad central para avance y completitud de flujos.
 *
 * ÚNICA clase autorizada a:
 *  - Mutar ultima_etapa_node_id en ProspectoEnFlujo
 *  - Marcar estado='completed' en FlujoEjecucion (vía finalizarRespetandoPerpetuo)
 *
 * Cualquier otra clase que necesite hacer estas operaciones DEBE delegarle.
 * El test Architecture/TransitionAuthorityTest.php falla si se detectan escrituras
 * de estas columnas fuera de esta clase o de FlujoEjecucion::finalizarRespetandoPerpetuo.
 */
class GuardedTransition
{
    /**
     * Avanza los prospectos a la etapa destino si pasan AMBOS guards:
     *   1. Guard de posición: ultima_etapa_node_id == etapa N-1 en la cadena lineal
     *   2. Gate temporal: now() >= fecha_inicio + offset_acumulado(etapa destino)
     *
     * @param  FlujoEjecucion  $ejecucion
     * @param  string          $targetStageNodeId  Etapa destino (node_id)
     * @param  array           $prospectoEnFlujoIds  IDs de ProspectoEnFlujo candidatos
     * @return array  IDs de ProspectoEnFlujo efectivamente avanzados
     */
    public function avanzarProspectos(
        FlujoEjecucion $ejecucion,
        string $targetStageNodeId,
        array $prospectoEnFlujoIds
    ): array {
        if (empty($prospectoEnFlujoIds)) {
            return [];
        }

        $flujo = $ejecucion->flujo;
        if (! $flujo) {
            Log::warning('GuardedTransition::avanzarProspectos: no se encontró flujo', [
                'ejecucion_id' => $ejecucion->id,
            ]);
            return [];
        }

        $avanzados = [];

        $pefs = ProspectoEnFlujo::whereIn('id', $prospectoEnFlujoIds)
            ->get();

        foreach ($pefs as $pef) {
            // Guard de posición
            if (! $this->guardPosicion($flujo, $pef, $targetStageNodeId)) {
                Log::warning('GuardedTransition: avance rechazado por guard de posición', [
                    'reason'           => 'position_guard_failed',
                    'flujo_id'         => $flujo->id,
                    'ejecucion_id'     => $ejecucion->id,
                    'prospecto_en_flujo_id' => $pef->id,
                    'etapa_actual'     => $pef->ultima_etapa_node_id,
                    'etapa_destino'    => $targetStageNodeId,
                ]);
                continue;
            }

            // Gate temporal
            if (! $this->puedeAvanzar($flujo, $pef, $targetStageNodeId)) {
                Log::warning('GuardedTransition: avance rechazado por gate temporal', [
                    'reason'           => 'temporal_gate_failed',
                    'flujo_id'         => $flujo->id,
                    'ejecucion_id'     => $ejecucion->id,
                    'prospecto_en_flujo_id' => $pef->id,
                    'etapa_destino'    => $targetStageNodeId,
                    'offset_dias'      => $this->offsetAcumulado($flujo, $targetStageNodeId),
                    'dias_desde_inicio' => $pef->fecha_inicio
                        ? (int) $pef->fecha_inicio->diffInDays(now())
                        : null,
                ]);
                continue;
            }

            // Avanzar posición
            ProspectoEnFlujo::withoutEvents(function () use ($pef, $targetStageNodeId) {
                $pef->update(['ultima_etapa_node_id' => $targetStageNodeId]);
            });

            $avanzados[] = $pef->id;
        }

        return $avanzados;
    }

    /**
     * Gate temporal puro — consumible por filtros de query y por el repair command.
     *
     * @param  Flujo            $flujo
     * @param  ProspectoEnFlujo $pef
     * @param  string           $targetStageNodeId
     * @return bool  true si now() >= fecha_inicio + offset_acumulado(targetStage)
     */
    public function puedeAvanzar(
        Flujo $flujo,
        ProspectoEnFlujo $pef,
        string $targetStageNodeId
    ): bool {
        if (! $pef->fecha_inicio) {
            // Sin fecha_inicio no hay gate temporal, se deja pasar
            return true;
        }

        $offsetDias = $this->offsetAcumulado($flujo, $targetStageNodeId);

        // -1 significa que el nodo no se encontró en la cadena de stages
        if ($offsetDias === -1) {
            return false;
        }

        $fechaHabilitacion = $pef->fecha_inicio->copy()->addDays($offsetDias);

        return now()->greaterThanOrEqualTo($fechaHabilitacion);
    }

    /**
     * Finaliza la ejecución SI y SOLO SI nextNodeId es un end_node real.
     *
     * Si no es end_node real: loguea warning y retorna false sin tocar la ejecución.
     *
     * @param  FlujoEjecucion  $ejecucion
     * @param  string|null     $nextNodeId  Nodo al que apunta la transición (puede ser null)
     * @param  string          $reason      Texto de auditoría (ej: 'observer', 'ENP:rama1')
     * @return bool  true si la ejecución fue marcada completed (o waiting si perpetua)
     */
    public function finalizarSiAlcanzoEndNode(
        FlujoEjecucion $ejecucion,
        ?string $nextNodeId,
        string $reason
    ): bool {
        $flujo = $ejecucion->flujo;

        if (! $this->esEndNodeReal($flujo, $nextNodeId)) {
            Log::warning('GuardedTransition: completitud bloqueada — no es end_node real', [
                'reason'       => 'completion_blocked_no_end_node',
                'ejecucion_id' => $ejecucion->id,
                'flujo_id'     => $flujo?->id,
                'next_node_id' => $nextNodeId,
                'caller'       => $reason,
            ]);

            return false;
        }

        // Guard de prospectos varados (solo aplica a ejecuciones NO-perpetuas).
        // Los perpetuos salen vía finalizarRespetandoPerpetuo → 'waiting', nunca 'completed'.
        $esPerpetuo = $ejecucion->es_perpetuo || ($flujo?->es_perpetuo ?? false);
        if (! $esPerpetuo && $flujo !== null) {
            $varadosCount = $this->contarProspectosVaradosMidFlow($flujo);
            if ($varadosCount > 0) {
                Log::warning('GuardedTransition: completitud bloqueada — prospectos activos varados mid-flow', [
                    'reason'        => 'completion_blocked_prospectos_stranded',
                    'ejecucion_id'  => $ejecucion->id,
                    'flujo_id'      => $flujo->id,
                    'varados_count' => $varadosCount,
                    'caller'        => $reason,
                ]);

                return false;
            }
        }

        // Delegar la escritura al método de dominio del modelo (único writer físico)
        $ejecucion->finalizarRespetandoPerpetuo();

        Log::info('GuardedTransition: ejecución finalizada via end_node real', [
            'ejecucion_id' => $ejecucion->id,
            'flujo_id'     => $flujo?->id,
            'next_node_id' => $nextNodeId,
            'caller'       => $reason,
            'estado_final' => $ejecucion->fresh()->estado,
        ]);

        return true;
    }

    /**
     * Offset acumulado en días desde fecha_inicio para alcanzar la etapa $stageNodeId.
     *
     * Suma los tiempo_espera de TODAS las etapas desde la primera hasta stageNodeId
     * (INCLUSIVO), siguiendo la cadena de branches.
     *
     * GOTCHA: Clientes-Ingreso tiene baseline +3 días porque el sync single-day
     * trae clientes que ingresaron 3 días antes (MetricasService L1184-1188).
     *
     * Retorna -1 si el nodo no se encuentra en la cadena de stages del flujo.
     *
     * @param  Flujo   $flujo
     * @param  string  $stageNodeId
     * @return int  días acumulados o -1 si no se encontró el nodo
     */
    public function offsetAcumulado(Flujo $flujo, string $stageNodeId): int
    {
        $cfg = $flujo->config_structure ?? [];
        $stages = $cfg['stages'] ?? [];
        $branches = $cfg['branches'] ?? [];

        if (empty($stages)) {
            return -1;
        }

        $esClientesIngreso = $flujo->origen === 'Grupo Deudas - Clientes Ingreso';

        // Encontrar el primer stage ejecutable (después del nodo start)
        $firstStageId = $this->findFirstExecutableStageId($cfg);
        if (! $firstStageId) {
            return -1;
        }

        $stagesPorId = collect($stages)->keyBy('id');
        $nextOf = collect($branches)->keyBy('source_node_id');

        // Baseline: Clientes-Ingreso +3, resto 0
        $offsetDias = $esClientesIngreso ? 3 : 0;
        $currentId = $firstStageId;
        $visitados = [];

        while ($currentId && ! in_array($currentId, $visitados, true)) {
            $visitados[] = $currentId;
            $stage = $stagesPorId[$currentId] ?? null;

            if (! $stage) {
                break;
            }

            $offsetDias += (int) ($stage['tiempo_espera'] ?? 0);

            if ($currentId === $stageNodeId) {
                return $offsetDias;
            }

            $currentId = $nextOf[$currentId]['target_node_id'] ?? null;
        }

        return -1; // nodo no encontrado en la cadena
    }

    /**
     * Determina si un nodeId es un end_node real del flujo.
     *
     * Un nodo es end_node real si:
     *   - Tiene prefijo 'end-'  (convención histórica del proyecto)
     *   - O está listado en cfg['end_nodes']  (declaración explícita)
     *
     * Retorna false para null (ausencia de nodo siguiente no implica fin real).
     */
    private function esEndNodeReal(?Flujo $flujo, ?string $nodeId): bool
    {
        if ($nodeId === null) {
            return false;
        }

        // Prefijo 'end-' es la convención principal
        if (str_starts_with($nodeId, 'end-')) {
            return true;
        }

        // Verificar declaración explícita en config_structure['end_nodes']
        $cfg = $flujo?->config_structure ?? [];
        $endNodes = $cfg['end_nodes'] ?? [];

        if (in_array($nodeId, $endNodes, true)) {
            return true;
        }

        // También verificar si el tipo del nodo en stages es 'end'
        $stages = $cfg['stages'] ?? [];
        $stage = collect($stages)->firstWhere('id', $nodeId);
        if ($stage && ($stage['type'] ?? '') === 'end') {
            return true;
        }

        return false;
    }

    /**
     * Cuenta prospectos activos varados mid-flow para un flujo dado.
     *
     * Un prospecto está "varado mid-flow" si:
     *   - completado=false AND cancelado=false (activo)
     *   - su ultima_etapa_node_id tiene una arista saliente en config_structure.branches
     *     hacia un nodo que ES un stage real:
     *       * target NO empieza con 'end-'
     *       * target NO está en config_structure.end_nodes
     *       * target existe como stage en config_structure.stages
     *
     * Se usa para bloquear la completitud prematura de ejecuciones no-perpetuas
     * cuando la ejecución fast-forwardea al end_node pero una cohorte quedó atrás.
     */
    private function contarProspectosVaradosMidFlow(Flujo $flujo): int
    {
        $cfg      = $flujo->config_structure ?? [];
        $stages   = $cfg['stages']    ?? [];
        $branches = $cfg['branches']  ?? [];
        $endNodes = $cfg['end_nodes'] ?? [];

        if (empty($stages) || empty($branches)) {
            return 0;
        }

        // Construir lookup: node_id → es stage real
        $stageIds = collect($stages)
            ->filter(fn ($s) => ! in_array($s['type'] ?? '', ['end', 'start'], true))
            ->pluck('id')
            ->flip()   // para O(1) lookup
            ->all();

        // Construir mapa source_node_id → target_node_id (primer branch, asume lineal o toma el primero)
        $branchTarget = collect($branches)->keyBy('source_node_id');

        // Recopilar los node_ids cuya siguiente arista apunta a un stage real
        $nodeIdsVarables = [];
        foreach ($branches as $branch) {
            $target = $branch['target_node_id'] ?? null;
            if ($target === null) {
                continue;
            }

            // El target debe ser un stage real (no end)
            if (str_starts_with($target, 'end-')) {
                continue;
            }
            if (in_array($target, $endNodes, true)) {
                continue;
            }
            if (! isset($stageIds[$target])) {
                continue;
            }

            // El source de esta arista es un nodo desde el que se puede quedar varado
            $source = $branch['source_node_id'] ?? null;
            if ($source !== null) {
                $nodeIdsVarables[] = $source;
            }
        }

        if (empty($nodeIdsVarables)) {
            return 0;
        }

        $nodeIdsVarables = array_unique($nodeIdsVarables);

        return ProspectoEnFlujo::where('flujo_id', $flujo->id)
            ->where('completado', false)
            ->where('cancelado', false)
            ->whereIn('ultima_etapa_node_id', $nodeIdsVarables)
            ->count();
    }

    /**
     * Guard de posición: verifica que la ultima_etapa_node_id del prospecto
     * sea exactamente la etapa N-1 antes de targetStageNodeId en la cadena lineal.
     *
     * Para la primera etapa ejecutable, acepta ultima_etapa_node_id=null (nuevo prospecto).
     */
    private function guardPosicion(
        Flujo $flujo,
        ProspectoEnFlujo $pef,
        string $targetStageNodeId
    ): bool {
        $cfg = $flujo->config_structure ?? [];
        $stages = $cfg['stages'] ?? [];
        $branches = $cfg['branches'] ?? [];

        if (empty($stages)) {
            return false;
        }

        $firstStageId = $this->findFirstExecutableStageId($cfg);
        if (! $firstStageId) {
            return false;
        }

        // Construir la lista de etapas ejecutables en orden
        $executableStages = $this->buildExecutableOrder($stages, $branches, $firstStageId);

        $targetIndex = array_search($targetStageNodeId, $executableStages);

        if ($targetIndex === false) {
            // targetStageNodeId no está en la cadena de etapas ejecutables
            // (puede ser un end_node u otro tipo no ejecutable)
            return false;
        }

        $currentUltima = $pef->ultima_etapa_node_id;

        if ($currentUltima === null) {
            // Prospecto nuevo: solo puede avanzar a la primera etapa ejecutable
            return $targetIndex === 0;
        }

        $currentIndex = array_search($currentUltima, $executableStages);

        if ($currentIndex === false) {
            // Posición actual no reconocida — tratar como nueva (solo primera etapa)
            return $targetIndex === 0;
        }

        return $targetIndex === $currentIndex + 1;
    }

    /**
     * Encuentra el primer stage ejecutable del flujo (el que sigue al nodo start).
     */
    private function findFirstExecutableStageId(array $cfg): ?string
    {
        $stages = $cfg['stages'] ?? [];
        $branches = $cfg['branches'] ?? [];
        $initialNodeConfig = $cfg['initial_node'] ?? null;

        // Resolver el ID del nodo inicial
        $initialNodeId = is_array($initialNodeConfig)
            ? ($initialNodeConfig['id'] ?? null)
            : $initialNodeConfig;

        // Si tenemos initial_node, seguir la rama desde él
        if ($initialNodeId) {
            $firstConn = collect($branches)->firstWhere('source_node_id', $initialNodeId);
            if ($firstConn) {
                return $firstConn['target_node_id'] ?? null;
            }
        }

        // Fallback: buscar nodo tipo 'start' y seguir su rama
        $startNode = collect($stages)->firstWhere('type', 'start');
        $startId = $startNode['id'] ?? null;

        if ($startId) {
            // También intentar el patrón 'initial-*' de MetricasService
            $nextOf = collect($branches)->keyBy('source_node_id');
            $fromInitial = $nextOf->filter(
                fn ($b, $k) => str_starts_with((string) $k, 'initial')
            )->first();

            if ($fromInitial) {
                return $fromInitial['target_node_id'] ?? null;
            }

            $firstConn = collect($branches)->firstWhere('source_node_id', $startId);
            if ($firstConn) {
                return $firstConn['target_node_id'] ?? null;
            }
        }

        // Fallback final: primer nodo ejecutable por orden
        return collect($stages)
            ->filter(fn ($s) => in_array($s['type'] ?? '', ['email', 'sms', 'stage', 'ambos']))
            ->sortBy('orden')
            ->first()['id'] ?? null;
    }

    /**
     * Construye la lista ordenada de etapas ejecutables (email, sms, stage, ambos)
     * siguiendo la cadena de branches. Excluye nodos de tipo end/start/condition.
     *
     * @return string[]  Array de node_ids en orden de ejecución
     */
    private function buildExecutableOrder(array $stages, array $branches, string $firstStageId): array
    {
        $stagesPorId = collect($stages)->keyBy('id');
        $nextOf = collect($branches)->keyBy('source_node_id');
        $executableTypes = ['email', 'sms', 'stage', 'ambos'];

        $orden = [];
        $visitados = [];
        $currentId = $firstStageId;

        while ($currentId && ! in_array($currentId, $visitados, true)) {
            $visitados[] = $currentId;
            $stage = $stagesPorId[$currentId] ?? null;

            if ($stage) {
                $type = $stage['type'] ?? null;
                if (in_array($type, $executableTypes)) {
                    $orden[] = $currentId;
                }
                // end nodes terminan la cadena
                if ($type === 'end') {
                    break;
                }
            }

            $currentId = $nextOf[$currentId]['target_node_id'] ?? null;
        }

        return $orden;
    }
}
