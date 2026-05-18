<?php

namespace App\Services;

use App\Models\Flujo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FlujoStatsService
{
    private const CACHE_TTL_SHORT = 60;

    private const CACHE_TTL_MEDIUM = 120;

    public function getProspectoStats(Flujo $flujo): array
    {
        return Cache::remember("flujo:{$flujo->id}:stats", self::CACHE_TTL_SHORT, function () use ($flujo) {
            $stats = DB::table('prospecto_en_flujo')
                ->where('flujo_id', $flujo->id)
                ->selectRaw("
                    COUNT(*) as total,
                    COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes,
                    COUNT(CASE WHEN estado = 'en_proceso' THEN 1 END) as en_proceso,
                    COUNT(CASE WHEN completado = true THEN 1 END) as completados,
                    COUNT(CASE WHEN cancelado = true THEN 1 END) as cancelados
                ")
                ->first();

            return [
                'total_prospectos' => $stats->total ?? 0,
                'prospectos_pendientes' => $stats->pendientes ?? 0,
                'prospectos_en_proceso' => $stats->en_proceso ?? 0,
                'prospectos_completados' => $stats->completados ?? 0,
                'prospectos_cancelados' => $stats->cancelados ?? 0,
                'total_etapas' => $flujo->flujoEtapas->count(),
                'total_condiciones' => $flujo->flujoCondiciones->count(),
                'total_ramificaciones' => $flujo->flujoRamificaciones->count(),
                'total_nodos_finales' => $flujo->flujoNodosFinales->count(),
            ];
        });
    }

    public function getNodeStats(Flujo $flujo): array
    {
        $configVisual = $flujo->config_visual;
        if (! $configVisual || empty($configVisual['nodes'])) {
            return [];
        }

        return Cache::remember("flujo:{$flujo->id}:node_stats", self::CACHE_TTL_MEDIUM, function () use ($flujo, $configVisual) {
            return $this->computeNodeStats($flujo, $configVisual);
        });
    }

    public function getFullStats(Flujo $flujo): array
    {
        return Cache::remember("flujo:{$flujo->id}:full_stats", self::CACHE_TTL_MEDIUM, function () use ($flujo) {
            return $this->computeFullStats($flujo);
        });
    }

    public function getCostStats(): array
    {
        return Cache::remember('flujos:cost_stats', self::CACHE_TTL_MEDIUM, function () {
            return $this->computeCostStats();
        });
    }

    public function invalidateCache(int $flujoId): void
    {
        Cache::forget("flujo:{$flujoId}:stats");
        Cache::forget("flujo:{$flujoId}:node_stats");
        Cache::forget("flujo:{$flujoId}:full_stats");
    }

    private function computeNodeStats(Flujo $flujo, array $configVisual): array
    {
        $nodesInfo = collect($configVisual['nodes'])->mapWithKeys(function ($node) {
            return [
                $node['id'] => [
                    'type' => $node['type'] ?? 'stage',
                    'tipo_mensaje' => $node['data']['tipo_mensaje'] ?? 'email',
                    'label' => $node['data']['label'] ?? $node['id'],
                ],
            ];
        });

        $etapasPorNodeId = DB::table('flujo_ejecucion_etapas as fee')
            ->join('flujo_ejecuciones as fe', 'fee.flujo_ejecucion_id', '=', 'fe.id')
            ->where('fe.flujo_id', $flujo->id)
            ->select('fee.id as etapa_id', 'fee.node_id')
            ->get()
            ->groupBy('node_id');

        if ($etapasPorNodeId->isEmpty()) {
            return $nodesInfo->map(function ($info, $nodeId) {
                $isEmail = in_array($info['tipo_mensaje'], ['email', 'ambos']);

                return [
                    'node_id' => $nodeId,
                    'tipo_mensaje' => $info['tipo_mensaje'],
                    'label' => $info['label'],
                    'total_enviado' => 0,
                    'total_fallido' => 0,
                    'total_abierto' => $isEmail ? 0 : null,
                    'total_clickeado' => $isEmail ? 0 : null,
                ];
            })->values()->toArray();
        }

        $allEtapaIds = $etapasPorNodeId->flatten()->pluck('etapa_id')->toArray();

        $stats = DB::table('envios')
            ->select(
                'flujo_ejecucion_etapa_id',
                'canal',
                'estado',
                DB::raw('count(*) as total')
            )
            ->whereIn('flujo_ejecucion_etapa_id', $allEtapaIds)
            ->groupBy('flujo_ejecucion_etapa_id', 'canal', 'estado')
            ->get();

        $statsByNodeId = [];

        foreach ($etapasPorNodeId as $nodeId => $etapas) {
            $etapaIds = $etapas->pluck('etapa_id')->toArray();
            $nodeStats = $stats->whereIn('flujo_ejecucion_etapa_id', $etapaIds);

            $enviado = $nodeStats->whereIn('estado', ['enviado', 'abierto', 'clickeado'])->sum('total');
            $fallido = $nodeStats->where('estado', 'fallido')->sum('total');
            $abierto = $nodeStats->whereIn('estado', ['abierto', 'clickeado'])->sum('total');
            $clickeado = $nodeStats->where('estado', 'clickeado')->sum('total');
            $pendiente = $nodeStats->where('estado', 'pendiente')->sum('total');

            $nodeInfo = $nodesInfo[$nodeId] ?? ['tipo_mensaje' => 'email', 'label' => $nodeId];
            $isEmail = in_array($nodeInfo['tipo_mensaje'], ['email', 'ambos']);

            $statsByNodeId[$nodeId] = [
                'node_id' => $nodeId,
                'tipo_mensaje' => $nodeInfo['tipo_mensaje'],
                'label' => $nodeInfo['label'],
                'total_pendiente' => $pendiente,
                'total_enviado' => $enviado,
                'total_fallido' => $fallido,
                'total_abierto' => $isEmail ? $abierto : null,
                'total_clickeado' => $isEmail ? $clickeado : null,
            ];
        }

        foreach ($nodesInfo as $nodeId => $info) {
            if (! isset($statsByNodeId[$nodeId]) && $info['type'] === 'stage') {
                $isEmail = in_array($info['tipo_mensaje'], ['email', 'ambos']);
                $statsByNodeId[$nodeId] = [
                    'node_id' => $nodeId,
                    'tipo_mensaje' => $info['tipo_mensaje'],
                    'label' => $info['label'],
                    'total_pendiente' => 0,
                    'total_enviado' => 0,
                    'total_fallido' => 0,
                    'total_abierto' => $isEmail ? 0 : null,
                    'total_clickeado' => $isEmail ? 0 : null,
                ];
            }
        }

        return array_values($statsByNodeId);
    }

    private function computeFullStats(Flujo $flujo): array
    {
        $enviosStats = DB::table('envios')
            ->where('flujo_id', $flujo->id)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as enviados"),
                DB::raw("SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos"),
                DB::raw("SUM(CASE WHEN estado IN ('abierto', 'clickeado') THEN 1 ELSE 0 END) as abiertos"),
                DB::raw("SUM(CASE WHEN estado = 'clickeado' THEN 1 ELSE 0 END) as clickeados"),
                DB::raw("SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes"),
                DB::raw("SUM(CASE WHEN canal = 'email' AND estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as emails_enviados"),
                DB::raw("SUM(CASE WHEN canal = 'sms' AND estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as sms_enviados")
            )
            ->first();

        $prospectosStats = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completados"),
                DB::raw("SUM(CASE WHEN estado = 'en_proceso' THEN 1 ELSE 0 END) as en_proceso"),
                DB::raw("SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes"),
                DB::raw("SUM(CASE WHEN estado = 'cancelado' THEN 1 ELSE 0 END) as cancelados")
            )
            ->first();

        $costos = $flujo->metadata['costos_vigentes'] ?? null;
        $costoTotal = $costos['costo_total'] ?? 0;

        $stageStats = $this->computePerStageStats($flujo);

        $totalEnviados = $enviosStats->enviados ?? 0;
        $totalAbiertos = $enviosStats->abiertos ?? 0;
        $totalClickeados = $enviosStats->clickeados ?? 0;
        $totalFallidos = $enviosStats->fallidos ?? 0;
        $totalProspectos = $prospectosStats->total ?? 0;
        $prospectosCompletados = $prospectosStats->completados ?? 0;

        $tasaApertura = $totalEnviados > 0 ? round(($totalAbiertos / $totalEnviados) * 100, 1) : 0;
        $tasaClick = $totalEnviados > 0 ? round(($totalClickeados / $totalEnviados) * 100, 1) : 0;
        $tasaFallo = ($totalEnviados + $totalFallidos) > 0
            ? round(($totalFallidos / ($totalEnviados + $totalFallidos)) * 100, 1)
            : 0;
        $costoPorConversion = $prospectosCompletados > 0
            ? round($costoTotal / $prospectosCompletados, 2)
            : 0;

        return [
            'resumen' => [
                'tasa_apertura' => $tasaApertura,
                'tasa_click' => $tasaClick,
                'tasa_fallo' => $tasaFallo,
                'costo_total' => round($costoTotal, 2),
                'costo_por_conversion' => $costoPorConversion,
                'emails_enviados' => $enviosStats->emails_enviados ?? 0,
                'sms_enviados' => $enviosStats->sms_enviados ?? 0,
            ],
            'funnel' => [
                'prospectos' => $totalProspectos,
                'enviados' => $totalEnviados,
                'abiertos' => $totalAbiertos,
                'clickeados' => $totalClickeados,
                'conversiones' => $prospectosCompletados,
                'tasa_apertura' => $tasaApertura,
                'tasa_click_sobre_abiertos' => $totalAbiertos > 0
                    ? round(($totalClickeados / $totalAbiertos) * 100, 1)
                    : 0,
                'tasa_conversion' => $totalProspectos > 0
                    ? round(($prospectosCompletados / $totalProspectos) * 100, 1)
                    : 0,
            ],
            'etapas' => $stageStats,
            'totales' => [
                'envios' => [
                    'total' => $enviosStats->total ?? 0,
                    'enviados' => $totalEnviados,
                    'fallidos' => $totalFallidos,
                    'pendientes' => $enviosStats->pendientes ?? 0,
                    'abiertos' => $totalAbiertos,
                    'clickeados' => $totalClickeados,
                ],
                'prospectos' => [
                    'total' => $totalProspectos,
                    'completados' => $prospectosCompletados,
                    'en_proceso' => $prospectosStats->en_proceso ?? 0,
                    'pendientes' => $prospectosStats->pendientes ?? 0,
                    'cancelados' => $prospectosStats->cancelados ?? 0,
                ],
            ],
        ];
    }

    private function computePerStageStats(Flujo $flujo): array
    {
        $configVisual = $flujo->config_visual;
        if (! $configVisual || empty($configVisual['nodes'])) {
            return [];
        }

        $etapasPorNodeId = DB::table('flujo_ejecucion_etapas as fee')
            ->join('flujo_ejecuciones as fe', 'fee.flujo_ejecucion_id', '=', 'fe.id')
            ->where('fe.flujo_id', $flujo->id)
            ->select('fee.id as etapa_id', 'fee.node_id')
            ->get()
            ->groupBy('node_id');

        $allEtapaIds = $etapasPorNodeId->flatten()->pluck('etapa_id')->toArray();

        $enviosPorEtapa = [];
        if (! empty($allEtapaIds)) {
            $enviosPorEtapa = DB::table('envios')
                ->select(
                    'flujo_ejecucion_etapa_id',
                    'estado',
                    DB::raw('count(*) as total')
                )
                ->whereIn('flujo_ejecucion_etapa_id', $allEtapaIds)
                ->groupBy('flujo_ejecucion_etapa_id', 'estado')
                ->get()
                ->groupBy('flujo_ejecucion_etapa_id');
        }

        $stageStats = [];

        foreach ($configVisual['nodes'] as $node) {
            if (($node['type'] ?? '') !== 'stage') {
                continue;
            }

            $nodeId = $node['id'];
            $label = $node['data']['label'] ?? $nodeId;
            $tipoMensaje = $node['data']['tipo_mensaje'] ?? 'email';
            $orden = $node['data']['orden'] ?? 0;

            $nodeEtapaIds = ($etapasPorNodeId[$nodeId] ?? collect())->pluck('etapa_id')->toArray();

            $enviado = 0;
            $fallido = 0;
            $abierto = 0;
            $clickeado = 0;

            foreach ($nodeEtapaIds as $etapaId) {
                $stats = $enviosPorEtapa[$etapaId] ?? collect();
                $enviado += $stats->whereIn('estado', ['enviado', 'abierto', 'clickeado'])->sum('total');
                $fallido += $stats->where('estado', 'fallido')->sum('total');
                $abierto += $stats->whereIn('estado', ['abierto', 'clickeado'])->sum('total');
                $clickeado += $stats->where('estado', 'clickeado')->sum('total');
            }

            $tasaApertura = $enviado > 0 ? round(($abierto / $enviado) * 100, 1) : 0;
            $tasaClick = $enviado > 0 ? round(($clickeado / $enviado) * 100, 1) : 0;

            $stageStats[] = [
                'node_id' => $nodeId,
                'label' => $label,
                'tipo_mensaje' => $tipoMensaje,
                'orden' => $orden,
                'enviados' => $enviado,
                'fallidos' => $fallido,
                'abiertos' => in_array($tipoMensaje, ['email', 'ambos']) ? $abierto : null,
                'clickeados' => in_array($tipoMensaje, ['email', 'ambos']) ? $clickeado : null,
                'tasa_apertura' => in_array($tipoMensaje, ['email', 'ambos']) ? $tasaApertura : null,
                'tasa_click' => in_array($tipoMensaje, ['email', 'ambos']) ? $tasaClick : null,
            ];
        }

        usort($stageStats, fn ($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));

        return $stageStats;
    }

    private function computeCostStats(): array
    {
        $flujos = Flujo::query()
            ->select('id', 'nombre', 'metadata', 'created_at')
            ->whereNotNull('metadata')
            ->get();

        $totalGastado = 0;
        $totalEmails = 0;
        $totalSms = 0;
        $totalProspectos = 0;
        $costosPorFlujo = [];

        foreach ($flujos as $flujo) {
            $costos = $flujo->metadata['costos_vigentes'] ?? null;

            if ($costos) {
                $costoTotal = (float) ($costos['costo_total'] ?? 0);
                $cantidadEmails = (int) ($costos['cantidad_emails'] ?? 0);
                $cantidadSms = (int) ($costos['cantidad_sms'] ?? 0);

                $totalGastado += $costoTotal;
                $totalEmails += $cantidadEmails;
                $totalSms += $cantidadSms;
                $totalProspectos += ($cantidadEmails + $cantidadSms);

                $costosPorFlujo[] = [
                    'flujo_id' => $flujo->id,
                    'nombre' => $flujo->nombre,
                    'fecha_creacion' => $flujo->created_at->toISOString(),
                    'costo_total' => $costoTotal,
                    'email_unitario' => (float) ($costos['email_costo_unitario'] ?? 0),
                    'sms_unitario' => (float) ($costos['sms_costo_unitario'] ?? 0),
                    'cantidad_emails' => $cantidadEmails,
                    'cantidad_sms' => $cantidadSms,
                ];
            }
        }

        return [
            'resumen' => [
                'total_gastado' => round($totalGastado, 2),
                'total_emails_enviados' => $totalEmails,
                'total_sms_enviados' => $totalSms,
                'total_prospectos_contactados' => $totalProspectos,
                'costo_promedio_por_prospecto' => $totalProspectos > 0 ? round($totalGastado / $totalProspectos, 2) : 0,
            ],
            'flujos' => $costosPorFlujo,
        ];
    }
}
