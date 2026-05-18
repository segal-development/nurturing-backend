<?php

namespace App\Services;

use App\Models\Desuscripcion;
use App\Models\Flujo;
use App\Models\Prospecto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servicio centralizado de métricas para el dashboard.
 *
 * Proporciona métricas de:
 * - Aperturas de email (por flujo, día, tasa)
 * - Clicks (por link, flujo, CTR)
 * - Envíos (enviados, fallidos, pendientes)
 * - Desuscripciones (tasa, motivos, tendencia)
 * - Conversiones (prospectos convertidos)
 * - Nuevos prospectos por día (entrada al flujo)
 *
 * Todos los métodos públicos aceptan un $flujoId opcional para filtrar
 * las métricas a un único flujo. Cuando se pasa, las cache keys incluyen
 * el flujoId para no mezclar resultados.
 */
class MetricasService
{
    /**
     * Tiempo de cache en segundos (5 minutos)
     */
    private const CACHE_TTL = 300;

    /**
     * Genera un sufijo para cache key basado en flujoId.
     */
    private function cacheSuffix(?int $flujoId): string
    {
        return $flujoId !== null ? ":f{$flujoId}" : '';
    }

    /**
     * Obtiene todas las métricas del dashboard en un solo método.
     */
    public function getDashboardCompleto(int $dias = 30, ?int $flujoId = null): array
    {
        $cacheKey = "metricas_dashboard_{$dias}".$this->cacheSuffix($flujoId);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($dias, $flujoId) {
            $data = [
                'resumen' => $this->getResumenGeneral($dias, $flujoId),
                'aperturas' => $this->getMetricasAperturas($dias, $flujoId),
                'clicks' => $this->getMetricasClicks($dias, $flujoId),
                'envios' => $this->getMetricasEnvios($dias, $flujoId),
                'desuscripciones' => $this->getMetricasDesuscripciones($dias, $flujoId),
                'conversiones' => $this->getMetricasConversiones($dias, $flujoId),
                'tendencias' => $this->getTendencias($dias, $flujoId),
                'nuevos_prospectos' => $this->getNuevosProspectosPorDia($dias, $flujoId),
                'envios_hoy' => $this->getEnviosHoyConFallback($flujoId),
                'generado_at' => now()->toIso8601String(),
            ];

            // Top flujos solo tiene sentido cuando NO hay filtro de flujo
            if ($flujoId === null) {
                $data['top_flujos'] = $this->getTopFlujos($dias);
            } else {
                $data['top_flujos'] = [];
            }

            return $data;
        });
    }

    /**
     * Resumen general de métricas clave (KPIs)
     * Optimizado: 4 queries consolidadas en vez de 7 separadas
     */
    public function getResumenGeneral(int $dias = 30, ?int $flujoId = null): array
    {
        $cacheKey = "metricas:resumen:{$dias}".$this->cacheSuffix($flujoId);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeResumenGeneral($dias, $flujoId));
    }

    private function computeResumenGeneral(int $dias, ?int $flujoId): array
    {
        $desde = now()->subDays($dias);

        // Query 1: Envíos
        $envioStats = DB::table('envios')
            ->where('created_at', '>=', $desde)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN estado IN ('enviado', 'entregado', 'abierto', 'clickeado') THEN 1 END) as exitosos
            ")
            ->first();

        $totalEnvios = (int) ($envioStats->total ?? 0);
        $enviosExitosos = (int) ($envioStats->exitosos ?? 0);

        // Query 2: Aperturas (JOIN si filtra por flujo)
        $aperturaQuery = DB::table('email_aperturas as ea')->where('ea.created_at', '>=', $desde);
        if ($flujoId !== null) {
            $aperturaQuery->join('envios as e', 'e.id', '=', 'ea.envio_id')->where('e.flujo_id', $flujoId);
        }
        $aperturaStats = $aperturaQuery
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT ea.envio_id) as unicos')
            ->first();

        $totalAperturas = (int) ($aperturaStats->total ?? 0);
        $enviosConApertura = (int) ($aperturaStats->unicos ?? 0);

        // Query 3: Clicks (JOIN si filtra por flujo)
        $clickQuery = DB::table('email_clicks as ec')->where('ec.created_at', '>=', $desde);
        if ($flujoId !== null) {
            $clickQuery->join('envios as e', 'e.id', '=', 'ec.envio_id')->where('e.flujo_id', $flujoId);
        }
        $clickStats = $clickQuery
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT ec.envio_id) as unicos')
            ->first();

        $totalClicks = (int) ($clickStats->total ?? 0);
        $enviosConClick = (int) ($clickStats->unicos ?? 0);

        // Query 4: Desuscripciones
        $totalDesuscripciones = 0;
        if (Schema::hasTable('desuscripciones')) {
            $totalDesuscripciones = DB::table('desuscripciones')
                ->where('created_at', '>=', $desde)
                ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
                ->count();
        }

        // Conversiones: prospectos en estado 'convertido' actualizados en el período.
        // Filtrado por flujo via JOIN con prospecto_en_flujo cuando aplica.
        $conversionesQuery = DB::table('prospectos as p')
            ->where('p.estado', 'convertido')
            ->where('p.updated_at', '>=', $desde);
        if ($flujoId !== null) {
            $conversionesQuery->join('prospecto_en_flujo as pf', 'pf.prospecto_id', '=', 'p.id')
                ->where('pf.flujo_id', $flujoId);
        }
        $prospectosConvertidos = $conversionesQuery->distinct()->count('p.id');

        // Tasas
        $tasaEntrega = $totalEnvios > 0 ? round(($enviosExitosos / $totalEnvios) * 100, 2) : 0;
        $tasaApertura = $enviosExitosos > 0 ? round(($enviosConApertura / $enviosExitosos) * 100, 2) : 0;
        $tasaClick = $enviosConApertura > 0 ? round(($enviosConClick / $enviosConApertura) * 100, 2) : 0;
        $tasaDesuscripcion = $enviosExitosos > 0 ? round(($totalDesuscripciones / $enviosExitosos) * 100, 2) : 0;

        return [
            'periodo_dias' => $dias,
            'flujo_id' => $flujoId,
            'total_envios' => $totalEnvios,
            'envios_exitosos' => $enviosExitosos,
            'total_aperturas' => $totalAperturas,
            'aperturas_unicas' => $enviosConApertura,
            'total_clicks' => $totalClicks,
            'clicks_unicos' => $enviosConClick,
            'desuscripciones' => $totalDesuscripciones,
            'conversiones' => $prospectosConvertidos,
            'tasas' => [
                'entrega' => $tasaEntrega,
                'apertura' => $tasaApertura,
                'click' => $tasaClick,
                'ctr' => $tasaClick,
                'desuscripcion' => $tasaDesuscripcion,
            ],
        ];
    }

    /**
     * Métricas de aperturas de email
     */
    public function getMetricasAperturas(int $dias = 30, ?int $flujoId = null): array
    {
        $cacheKey = "metricas:aperturas:{$dias}".$this->cacheSuffix($flujoId);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasAperturas($dias, $flujoId));
    }

    private function computeMetricasAperturas(int $dias, ?int $flujoId): array
    {
        $desde = now()->subDays($dias);

        // Por día
        $porDiaQuery = DB::table('email_aperturas as ea')->where('ea.fecha_apertura', '>=', $desde);
        if ($flujoId !== null) {
            $porDiaQuery->join('envios as e', 'e.id', '=', 'ea.envio_id')->where('e.flujo_id', $flujoId);
        }
        $porDia = $porDiaQuery
            ->select(
                DB::raw('DATE(ea.fecha_apertura) as fecha'),
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT ea.envio_id) as unicos')
            )
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha')
            ->toArray();

        // Rellenar días sin datos
        $porDiaCompleto = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = now()->subDays($i)->format('Y-m-d');
            $data = $porDia[$fecha] ?? null;
            $porDiaCompleto[] = [
                'fecha' => $fecha,
                'total' => $data ? (int) $data->total : 0,
                'unicos' => $data ? (int) $data->unicos : 0,
            ];
        }

        // Por flujo (cuando hay filtro, esta vista es de un solo flujo)
        $porFlujoQuery = DB::table('email_aperturas as ea')
            ->join('envios as e', 'e.id', '=', 'ea.envio_id')
            ->join('flujos as f', 'f.id', '=', 'e.flujo_id')
            ->where('ea.fecha_apertura', '>=', $desde);
        if ($flujoId !== null) {
            $porFlujoQuery->where('e.flujo_id', $flujoId);
        }
        $porFlujo = $porFlujoQuery
            ->select(
                'f.id as flujo_id',
                'f.nombre as flujo_nombre',
                DB::raw('COUNT(*) as total_aperturas'),
                DB::raw('COUNT(DISTINCT ea.envio_id) as aperturas_unicas')
            )
            ->groupBy('f.id', 'f.nombre')
            ->orderByDesc('total_aperturas')
            ->limit(10)
            ->get();

        // Por dispositivo
        $porDispositivoQuery = DB::table('email_aperturas as ea')->where('ea.fecha_apertura', '>=', $desde);
        if ($flujoId !== null) {
            $porDispositivoQuery->join('envios as e', 'e.id', '=', 'ea.envio_id')->where('e.flujo_id', $flujoId);
        }
        $porDispositivo = $porDispositivoQuery
            ->select('ea.dispositivo', DB::raw('COUNT(*) as total'))
            ->groupBy('ea.dispositivo')
            ->orderByDesc('total')
            ->get();

        // Por cliente de email
        $porClienteQuery = DB::table('email_aperturas as ea')->where('ea.fecha_apertura', '>=', $desde);
        if ($flujoId !== null) {
            $porClienteQuery->join('envios as e', 'e.id', '=', 'ea.envio_id')->where('e.flujo_id', $flujoId);
        }
        $porCliente = $porClienteQuery
            ->select('ea.cliente_email', DB::raw('COUNT(*) as total'))
            ->groupBy('ea.cliente_email')
            ->orderByDesc('total')
            ->get();

        // Por hora del día
        $porHoraQuery = DB::table('email_aperturas as ea')->where('ea.fecha_apertura', '>=', $desde);
        if ($flujoId !== null) {
            $porHoraQuery->join('envios as e', 'e.id', '=', 'ea.envio_id')->where('e.flujo_id', $flujoId);
        }
        $porHora = $porHoraQuery
            ->select(
                DB::raw('EXTRACT(HOUR FROM ea.fecha_apertura) as hora'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('hora')
            ->orderBy('hora')
            ->get();

        return [
            'por_dia' => $porDiaCompleto,
            'por_flujo' => $porFlujo,
            'por_dispositivo' => $porDispositivo,
            'por_cliente_email' => $porCliente,
            'por_hora' => $porHora,
        ];
    }

    /**
     * Métricas de clicks
     */
    public function getMetricasClicks(int $dias = 30, ?int $flujoId = null): array
    {
        $cacheKey = "metricas:clicks:{$dias}".$this->cacheSuffix($flujoId);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasClicks($dias, $flujoId));
    }

    private function computeMetricasClicks(int $dias, ?int $flujoId): array
    {
        $desde = now()->subDays($dias);

        // Por día
        $porDiaQuery = DB::table('email_clicks as ec')->where('ec.fecha_click', '>=', $desde);
        if ($flujoId !== null) {
            $porDiaQuery->join('envios as e', 'e.id', '=', 'ec.envio_id')->where('e.flujo_id', $flujoId);
        }
        $porDia = $porDiaQuery
            ->select(
                DB::raw('DATE(ec.fecha_click) as fecha'),
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT ec.envio_id) as unicos')
            )
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha')
            ->toArray();

        // Rellenar días sin datos
        $porDiaCompleto = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = now()->subDays($i)->format('Y-m-d');
            $data = $porDia[$fecha] ?? null;
            $porDiaCompleto[] = [
                'fecha' => $fecha,
                'total' => $data ? (int) $data->total : 0,
                'unicos' => $data ? (int) $data->unicos : 0,
            ];
        }

        // Por flujo
        $porFlujoQuery = DB::table('email_clicks as ec')
            ->join('envios as e', 'e.id', '=', 'ec.envio_id')
            ->join('flujos as f', 'f.id', '=', 'e.flujo_id')
            ->where('ec.fecha_click', '>=', $desde);
        if ($flujoId !== null) {
            $porFlujoQuery->where('e.flujo_id', $flujoId);
        }
        $porFlujo = $porFlujoQuery
            ->select(
                'f.id as flujo_id',
                'f.nombre as flujo_nombre',
                DB::raw('COUNT(*) as total_clicks'),
                DB::raw('COUNT(DISTINCT ec.envio_id) as clicks_unicos')
            )
            ->groupBy('f.id', 'f.nombre')
            ->orderByDesc('total_clicks')
            ->limit(10)
            ->get();

        // Top URLs clickeadas
        $topUrlsQuery = DB::table('email_clicks as ec')
            ->where('ec.fecha_click', '>=', $desde)
            ->whereNotNull('ec.url_original');
        if ($flujoId !== null) {
            $topUrlsQuery->join('envios as e', 'e.id', '=', 'ec.envio_id')->where('e.flujo_id', $flujoId);
        }
        $topUrls = $topUrlsQuery
            ->select('ec.url_original as url_destino', DB::raw('COUNT(*) as total'))
            ->groupBy('ec.url_original')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return [
            'por_dia' => $porDiaCompleto,
            'por_flujo' => $porFlujo,
            'top_urls' => $topUrls,
        ];
    }

    /**
     * Métricas de envíos
     */
    public function getMetricasEnvios(int $dias = 30, ?int $flujoId = null): array
    {
        $cacheKey = "metricas:envios:{$dias}".$this->cacheSuffix($flujoId);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasEnvios($dias, $flujoId));
    }

    private function computeMetricasEnvios(int $dias, ?int $flujoId): array
    {
        $desde = now()->subDays($dias);

        // Totales por estado
        $porEstado = DB::table('envios')
            ->select('estado', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $desde)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->toArray();

        // Por canal (email vs sms)
        $porCanal = DB::table('envios')
            ->select('canal', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $desde)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('canal')
            ->pluck('total', 'canal')
            ->toArray();

        // Por día y estado
        $porDia = DB::table('envios')
            ->select(
                DB::raw('DATE(created_at) as fecha'),
                DB::raw("COUNT(CASE WHEN estado IN ('enviado', 'entregado', 'abierto', 'clickeado') THEN 1 END) as exitosos"),
                DB::raw("COUNT(CASE WHEN estado = 'fallido' THEN 1 END) as fallidos"),
                DB::raw("COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes")
            )
            ->where('created_at', '>=', $desde)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha')
            ->toArray();

        // Rellenar días sin datos
        $porDiaCompleto = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = now()->subDays($i)->format('Y-m-d');
            $data = $porDia[$fecha] ?? null;
            $porDiaCompleto[] = [
                'fecha' => $fecha,
                'exitosos' => $data ? (int) $data->exitosos : 0,
                'fallidos' => $data ? (int) $data->fallidos : 0,
                'pendientes' => $data ? (int) $data->pendientes : 0,
            ];
        }

        // Por flujo
        $porFlujoQuery = DB::table('envios as e')
            ->join('flujos as f', 'f.id', '=', 'e.flujo_id')
            ->where('e.created_at', '>=', $desde);
        if ($flujoId !== null) {
            $porFlujoQuery->where('e.flujo_id', $flujoId);
        }
        $porFlujo = $porFlujoQuery
            ->select(
                'f.id as flujo_id',
                'f.nombre as flujo_nombre',
                DB::raw('COUNT(*) as total'),
                DB::raw("COUNT(CASE WHEN e.estado IN ('enviado', 'entregado', 'abierto', 'clickeado') THEN 1 END) as exitosos"),
                DB::raw("COUNT(CASE WHEN e.estado = 'fallido' THEN 1 END) as fallidos")
            )
            ->groupBy('f.id', 'f.nombre')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return [
            'por_estado' => $porEstado,
            'por_canal' => $porCanal,
            'por_dia' => $porDiaCompleto,
            'por_flujo' => $porFlujo,
        ];
    }

    /**
     * Métricas de desuscripciones
     */
    public function getMetricasDesuscripciones(int $dias = 30, ?int $flujoId = null): array
    {
        if (! Schema::hasTable('desuscripciones')) {
            return $this->getDesuscripcionesVacias($dias);
        }

        $cacheKey = "metricas:desuscripciones:{$dias}".$this->cacheSuffix($flujoId);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasDesuscripciones($dias, $flujoId));
    }

    private function computeMetricasDesuscripciones(int $dias, ?int $flujoId): array
    {
        $desde = now()->subDays($dias);

        $totalQuery = Desuscripcion::where('created_at', '>=', $desde);
        if ($flujoId !== null) {
            $totalQuery->where('flujo_id', $flujoId);
        }
        $total = $totalQuery->count();

        // Por canal
        $porCanal = DB::table('desuscripciones')
            ->select('canal', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $desde)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('canal')
            ->pluck('total', 'canal')
            ->toArray();

        // Por motivo
        $porMotivo = DB::table('desuscripciones')
            ->select('motivo', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $desde)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->whereNotNull('motivo')
            ->groupBy('motivo')
            ->orderByDesc('total')
            ->get();

        // Por día
        $porDia = DB::table('desuscripciones')
            ->select(
                DB::raw('DATE(created_at) as fecha'),
                DB::raw('COUNT(*) as total')
            )
            ->where('created_at', '>=', $desde)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha')
            ->toArray();

        $porDiaCompleto = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = now()->subDays($i)->format('Y-m-d');
            $data = $porDia[$fecha] ?? null;
            $porDiaCompleto[] = [
                'fecha' => $fecha,
                'total' => $data ? (int) $data->total : 0,
            ];
        }

        // Por flujo
        $porFlujoQuery = DB::table('desuscripciones as d')
            ->leftJoin('flujos as f', 'f.id', '=', 'd.flujo_id')
            ->where('d.created_at', '>=', $desde)
            ->whereNotNull('d.flujo_id');
        if ($flujoId !== null) {
            $porFlujoQuery->where('d.flujo_id', $flujoId);
        }
        $porFlujo = $porFlujoQuery
            ->select(
                'f.id as flujo_id',
                'f.nombre as flujo_nombre',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('f.id', 'f.nombre')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return [
            'total' => $total,
            'por_canal' => $porCanal,
            'por_motivo' => $porMotivo,
            'por_dia' => $porDiaCompleto,
            'por_flujo' => $porFlujo,
        ];
    }

    /**
     * Estructura vacía para desuscripciones cuando la tabla no existe
     */
    private function getDesuscripcionesVacias(int $dias): array
    {
        $porDiaCompleto = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $porDiaCompleto[] = [
                'fecha' => now()->subDays($i)->format('Y-m-d'),
                'total' => 0,
            ];
        }

        return [
            'total' => 0,
            'por_canal' => [],
            'por_motivo' => [],
            'por_dia' => $porDiaCompleto,
            'por_flujo' => [],
        ];
    }

    /**
     * Métricas de conversiones (prospectos que pasaron a convertido)
     */
    public function getMetricasConversiones(int $dias = 30, ?int $flujoId = null): array
    {
        $cacheKey = "metricas:conversiones:{$dias}".$this->cacheSuffix($flujoId);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasConversiones($dias, $flujoId));
    }

    private function computeMetricasConversiones(int $dias, ?int $flujoId): array
    {
        $desde = now()->subDays($dias);

        // Total convertidos en el período (filtrado por flujo si aplica)
        $convertidosQuery = DB::table('prospectos as p')
            ->where('p.estado', 'convertido')
            ->where('p.updated_at', '>=', $desde);
        if ($flujoId !== null) {
            $convertidosQuery->join('prospecto_en_flujo as pf', 'pf.prospecto_id', '=', 'p.id')
                ->where('pf.flujo_id', $flujoId);
        }
        $total = $convertidosQuery->distinct()->count('p.id');

        // Total prospectos para tasa
        $totalProspectosQuery = DB::table('prospectos as p')->where('p.created_at', '>=', $desde);
        if ($flujoId !== null) {
            $totalProspectosQuery->join('prospecto_en_flujo as pf', 'pf.prospecto_id', '=', 'p.id')
                ->where('pf.flujo_id', $flujoId);
        }
        $totalProspectos = $totalProspectosQuery->distinct()->count('p.id');
        $tasaConversion = $totalProspectos > 0 ? round(($total / $totalProspectos) * 100, 2) : 0;

        // Por tipo de prospecto
        $porTipoQuery = DB::table('prospectos as p')
            ->join('tipo_prospecto as tp', 'tp.id', '=', 'p.tipo_prospecto_id')
            ->where('p.estado', 'convertido')
            ->where('p.updated_at', '>=', $desde);
        if ($flujoId !== null) {
            $porTipoQuery->join('prospecto_en_flujo as pf', 'pf.prospecto_id', '=', 'p.id')
                ->where('pf.flujo_id', $flujoId);
        }
        $porTipo = $porTipoQuery
            ->select(
                'tp.nombre as tipo',
                DB::raw('COUNT(DISTINCT p.id) as total')
            )
            ->groupBy('tp.id', 'tp.nombre')
            ->orderByDesc('total')
            ->get();

        // Por día
        $porDiaQuery = DB::table('prospectos as p')
            ->where('p.estado', 'convertido')
            ->where('p.updated_at', '>=', $desde);
        if ($flujoId !== null) {
            $porDiaQuery->join('prospecto_en_flujo as pf', 'pf.prospecto_id', '=', 'p.id')
                ->where('pf.flujo_id', $flujoId);
        }
        $porDia = $porDiaQuery
            ->select(
                DB::raw('DATE(p.updated_at) as fecha'),
                DB::raw('COUNT(DISTINCT p.id) as total')
            )
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha')
            ->toArray();

        $porDiaCompleto = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = now()->subDays($i)->format('Y-m-d');
            $data = $porDia[$fecha] ?? null;
            $porDiaCompleto[] = [
                'fecha' => $fecha,
                'total' => $data ? (int) $data->total : 0,
            ];
        }

        return [
            'total' => $total,
            'tasa_conversion' => $tasaConversion,
            'por_tipo' => $porTipo,
            'por_dia' => $porDiaCompleto,
        ];
    }

    /**
     * Tendencias comparativas (este período vs anterior)
     */
    public function getTendencias(int $dias = 30, ?int $flujoId = null): array
    {
        $cacheKey = "metricas:tendencias:{$dias}".$this->cacheSuffix($flujoId);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeTendencias($dias, $flujoId));
    }

    private function computeTendencias(int $dias, ?int $flujoId): array
    {
        $desdeActual = now()->subDays($dias);
        $desdeAnterior = now()->subDays($dias * 2);
        $hastaAnterior = now()->subDays($dias);

        // Envíos (actual + anterior)
        $envios = DB::table('envios')
            ->where('created_at', '>=', $desdeAnterior)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->selectRaw('
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as actual,
                COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) as anterior
            ', [$desdeActual, $desdeAnterior, $hastaAnterior])
            ->first();

        // Aperturas (actual + anterior)
        $aperturasQuery = DB::table('email_aperturas as ea')->where('ea.created_at', '>=', $desdeAnterior);
        if ($flujoId !== null) {
            $aperturasQuery->join('envios as e', 'e.id', '=', 'ea.envio_id')->where('e.flujo_id', $flujoId);
        }
        $aperturas = $aperturasQuery
            ->selectRaw('
                COUNT(CASE WHEN ea.created_at >= ? THEN 1 END) as actual,
                COUNT(CASE WHEN ea.created_at >= ? AND ea.created_at < ? THEN 1 END) as anterior
            ', [$desdeActual, $desdeAnterior, $hastaAnterior])
            ->first();

        // Clicks (actual + anterior)
        $clicksQuery = DB::table('email_clicks as ec')->where('ec.created_at', '>=', $desdeAnterior);
        if ($flujoId !== null) {
            $clicksQuery->join('envios as e', 'e.id', '=', 'ec.envio_id')->where('e.flujo_id', $flujoId);
        }
        $clicks = $clicksQuery
            ->selectRaw('
                COUNT(CASE WHEN ec.created_at >= ? THEN 1 END) as actual,
                COUNT(CASE WHEN ec.created_at >= ? AND ec.created_at < ? THEN 1 END) as anterior
            ', [$desdeActual, $desdeAnterior, $hastaAnterior])
            ->first();

        // Desuscripciones (actual + anterior)
        $desuscripcionesActual = 0;
        $desuscripcionesAnterior = 0;
        if (Schema::hasTable('desuscripciones')) {
            $desus = DB::table('desuscripciones')
                ->where('created_at', '>=', $desdeAnterior)
                ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
                ->selectRaw('
                    COUNT(CASE WHEN created_at >= ? THEN 1 END) as actual,
                    COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) as anterior
                ', [$desdeActual, $desdeAnterior, $hastaAnterior])
                ->first();
            $desuscripcionesActual = (int) ($desus->actual ?? 0);
            $desuscripcionesAnterior = (int) ($desus->anterior ?? 0);
        }

        return [
            'envios' => $this->calcularTendencia((int) ($envios->actual ?? 0), (int) ($envios->anterior ?? 0)),
            'aperturas' => $this->calcularTendencia((int) ($aperturas->actual ?? 0), (int) ($aperturas->anterior ?? 0)),
            'clicks' => $this->calcularTendencia((int) ($clicks->actual ?? 0), (int) ($clicks->anterior ?? 0)),
            'desuscripciones' => $this->calcularTendencia($desuscripcionesActual, $desuscripcionesAnterior),
        ];
    }

    /**
     * Nuevos prospectos por día que entraron a un flujo (o a cualquier flujo).
     * Útil para entender la tasa de incorporación a campañas.
     */
    public function getNuevosProspectosPorDia(int $dias = 30, ?int $flujoId = null): array
    {
        $cacheKey = "metricas:nuevos_prospectos:{$dias}".$this->cacheSuffix($flujoId);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeNuevosProspectosPorDia($dias, $flujoId));
    }

    private function computeNuevosProspectosPorDia(int $dias, ?int $flujoId): array
    {
        $desde = now()->subDays($dias);

        $porDia = DB::table('prospecto_en_flujo')
            ->select(
                DB::raw('DATE(fecha_inicio) as fecha'),
                DB::raw('COUNT(*) as total')
            )
            ->where('fecha_inicio', '>=', $desde)
            ->where('cancelado', false)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha')
            ->toArray();

        $porDiaCompleto = [];
        $total = 0;
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = now()->subDays($i)->format('Y-m-d');
            $data = $porDia[$fecha] ?? null;
            $count = $data ? (int) $data->total : 0;
            $total += $count;
            $porDiaCompleto[] = [
                'fecha' => $fecha,
                'total' => $count,
            ];
        }

        $promedioDiario = $dias > 0 ? round($total / $dias, 1) : 0;

        return [
            'total' => $total,
            'promedio_diario' => $promedioDiario,
            'por_dia' => $porDiaCompleto,
        ];
    }

    /**
     * Envíos del día actual desglosados por etapa, con fallback histórico
     * al último envío de cada etapa si hoy no hubo actividad.
     *
     * Solo tiene sentido cuando se filtra por un flujo: devuelve estructura
     * vacía si $flujoId es null.
     */
    public function getEnviosHoyConFallback(?int $flujoId): array
    {
        if ($flujoId === null) {
            return [
                'total_hoy' => 0,
                'por_etapa_hoy' => [],
                'ultimo_por_etapa' => [],
            ];
        }

        $cacheKey = "metricas:envios_hoy:f{$flujoId}";

        return Cache::remember($cacheKey, 60, fn () => $this->computeEnviosHoyConFallback($flujoId));
    }

    private function computeEnviosHoyConFallback(int $flujoId): array
    {
        // Lookup stage labels from flujo config_structure once
        $flujo = Flujo::find($flujoId);
        $stageLabels = [];
        foreach (($flujo?->config_structure['stages'] ?? []) as $s) {
            $stageLabels[$s['id'] ?? ''] = $s['label'] ?? ($s['nombre'] ?? '(sin nombre)');
        }

        // Envíos de HOY agrupados por etapa
        $porEtapaHoy = DB::table('envios as e')
            ->leftJoin('flujo_ejecucion_etapas as fee', 'fee.id', '=', 'e.flujo_ejecucion_etapa_id')
            ->where('e.flujo_id', $flujoId)
            ->whereDate('e.created_at', now()->toDateString())
            ->select(
                'fee.node_id',
                DB::raw('COUNT(*) as total'),
                DB::raw('MAX(e.created_at) as ultimo')
            )
            ->groupBy('fee.node_id')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'node_id' => $r->node_id,
                'etapa_nombre' => $stageLabels[$r->node_id] ?? '(etapa desconocida)',
                'total' => (int) $r->total,
                'ultimo' => $r->ultimo,
            ])
            ->toArray();

        $totalHoy = array_sum(array_column($porEtapaHoy, 'total'));

        // Fallback: si hoy=0, traer el último envío histórico por etapa
        $ultimoPorEtapa = [];
        if ($totalHoy === 0) {
            $ultimos = DB::table('envios as e')
                ->leftJoin('flujo_ejecucion_etapas as fee', 'fee.id', '=', 'e.flujo_ejecucion_etapa_id')
                ->where('e.flujo_id', $flujoId)
                ->select(
                    'fee.node_id',
                    DB::raw('MAX(e.created_at) as ultimo'),
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('fee.node_id')
                ->orderByDesc('ultimo')
                ->get();

            $ultimoPorEtapa = $ultimos->map(fn ($r) => [
                'node_id' => $r->node_id,
                'etapa_nombre' => $stageLabels[$r->node_id] ?? '(etapa desconocida)',
                'ultimo' => $r->ultimo,
                'total' => (int) $r->total,
            ])->toArray();
        }

        return [
            'total_hoy' => $totalHoy,
            'por_etapa_hoy' => $porEtapaHoy,
            'ultimo_por_etapa' => $ultimoPorEtapa,
        ];
    }

    /**
     * Top flujos por rendimiento (no se filtra por flujoId — siempre global)
     */
    public function getTopFlujos(int $dias = 30, int $limit = 5): array
    {
        return Cache::remember("metricas:top_flujos:{$dias}:{$limit}", self::CACHE_TTL, fn () => $this->computeTopFlujos($dias, $limit));
    }

    private function computeTopFlujos(int $dias, int $limit): array
    {
        $desde = now()->subDays($dias);

        return DB::table('flujos as f')
            ->leftJoin('envios as e', function ($join) use ($desde) {
                $join->on('e.flujo_id', '=', 'f.id')
                    ->where('e.created_at', '>=', $desde);
            })
            ->leftJoin('email_aperturas as ea', 'ea.envio_id', '=', 'e.id')
            ->leftJoin('email_clicks as ec', 'ec.envio_id', '=', 'e.id')
            ->select(
                'f.id',
                'f.nombre',
                DB::raw('COUNT(DISTINCT e.id) as total_envios'),
                DB::raw('COUNT(DISTINCT ea.id) as total_aperturas'),
                DB::raw('COUNT(DISTINCT ec.id) as total_clicks'),
                DB::raw('CASE WHEN COUNT(DISTINCT e.id) > 0
                    THEN ROUND((COUNT(DISTINCT ea.envio_id)::numeric / COUNT(DISTINCT e.id)::numeric) * 100, 2)
                    ELSE 0 END as tasa_apertura'),
                DB::raw('CASE WHEN COUNT(DISTINCT ea.envio_id) > 0
                    THEN ROUND((COUNT(DISTINCT ec.envio_id)::numeric / COUNT(DISTINCT ea.envio_id)::numeric) * 100, 2)
                    ELSE 0 END as ctr')
            )
            ->groupBy('f.id', 'f.nombre')
            ->orderByDesc('total_envios')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Calcula el porcentaje de cambio entre dos valores
     */
    private function calcularTendencia(int $actual, int $anterior): array
    {
        if ($anterior === 0) {
            $cambio = $actual > 0 ? 100 : 0;
        } else {
            $cambio = round((($actual - $anterior) / $anterior) * 100, 1);
        }

        return [
            'actual' => $actual,
            'anterior' => $anterior,
            'cambio_porcentaje' => $cambio,
            'direccion' => $cambio > 0 ? 'up' : ($cambio < 0 ? 'down' : 'stable'),
        ];
    }

    /**
     * Invalida el cache de métricas. Limpia variantes con y sin flujoId.
     */
    public function invalidarCache(): void
    {
        $periodos = [7, 30, 90];
        $tipos = ['resumen', 'aperturas', 'clicks', 'envios', 'desuscripciones', 'conversiones', 'tendencias', 'nuevos_prospectos'];

        foreach ($periodos as $dias) {
            Cache::forget("metricas_dashboard_{$dias}");
            foreach ($tipos as $tipo) {
                Cache::forget("metricas:{$tipo}:{$dias}");
            }
            Cache::forget("metricas:top_flujos:{$dias}:5");
            Cache::forget("metricas:top_flujos:{$dias}:10");
        }

        // Nota: para variantes con flujoId la invalidación es selectiva por flujo;
        // se delega al endpoint /metricas/refresh que conoce el flujoId actual.
    }

    /**
     * Invalida el cache de métricas para un flujo específico.
     */
    public function invalidarCacheFlujo(int $flujoId): void
    {
        $periodos = [7, 30, 90];
        $tipos = ['resumen', 'aperturas', 'clicks', 'envios', 'desuscripciones', 'conversiones', 'tendencias', 'nuevos_prospectos'];
        $suffix = ":f{$flujoId}";

        foreach ($periodos as $dias) {
            Cache::forget("metricas_dashboard_{$dias}{$suffix}");
            foreach ($tipos as $tipo) {
                Cache::forget("metricas:{$tipo}:{$dias}{$suffix}");
            }
        }
    }
}
