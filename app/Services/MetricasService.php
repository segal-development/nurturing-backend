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
 */
class MetricasService
{
    /**
     * Tiempo de cache en segundos (5 minutos)
     */
    private const CACHE_TTL = 300;

    /**
     * Obtiene todas las métricas del dashboard en un solo método.
     */
    public function getDashboardCompleto(int $dias = 30): array
    {
        $cacheKey = "metricas_dashboard_{$dias}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($dias) {
            return [
                'resumen' => $this->getResumenGeneral($dias),
                'aperturas' => $this->getMetricasAperturas($dias),
                'clicks' => $this->getMetricasClicks($dias),
                'envios' => $this->getMetricasEnvios($dias),
                'desuscripciones' => $this->getMetricasDesuscripciones($dias),
                'conversiones' => $this->getMetricasConversiones($dias),
                'tendencias' => $this->getTendencias($dias),
                'top_flujos' => $this->getTopFlujos($dias),
                'generado_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Resumen general de métricas clave (KPIs)
     * Optimizado: 4 queries consolidadas en vez de 7 separadas
     */
    public function getResumenGeneral(int $dias = 30): array
    {
        return Cache::remember("metricas:resumen:{$dias}", self::CACHE_TTL, fn () => $this->computeResumenGeneral($dias));
    }

    private function computeResumenGeneral(int $dias): array
    {
        $desde = now()->subDays($dias);

        // Query 1: Envíos - totales y exitosos en 1 sola query
        $envioStats = DB::table('envios')
            ->where('created_at', '>=', $desde)
            ->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN estado IN ('enviado', 'entregado', 'abierto', 'clickeado') THEN 1 END) as exitosos
            ")
            ->first();

        $totalEnvios = (int) ($envioStats->total ?? 0);
        $enviosExitosos = (int) ($envioStats->exitosos ?? 0);

        // Query 2: Aperturas - total y únicas en 1 sola query
        $aperturaStats = DB::table('email_aperturas')
            ->where('created_at', '>=', $desde)
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT envio_id) as unicos')
            ->first();

        $totalAperturas = (int) ($aperturaStats->total ?? 0);
        $enviosConApertura = (int) ($aperturaStats->unicos ?? 0);

        // Query 3: Clicks - total y únicos en 1 sola query
        $clickStats = DB::table('email_clicks')
            ->where('created_at', '>=', $desde)
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT envio_id) as unicos')
            ->first();

        $totalClicks = (int) ($clickStats->total ?? 0);
        $enviosConClick = (int) ($clickStats->unicos ?? 0);

        // Query 4: Desuscripciones + conversiones
        $totalDesuscripciones = Schema::hasTable('desuscripciones')
            ? DB::table('desuscripciones')->where('created_at', '>=', $desde)->count()
            : 0;

        $prospectosConvertidos = DB::table('prospectos')
            ->where('estado', 'convertido')
            ->where('updated_at', '>=', $desde)
            ->count();

        // Calcular tasas
        $tasaEntrega = $totalEnvios > 0 ? round(($enviosExitosos / $totalEnvios) * 100, 2) : 0;
        $tasaApertura = $enviosExitosos > 0 ? round(($enviosConApertura / $enviosExitosos) * 100, 2) : 0;
        $tasaClick = $enviosConApertura > 0 ? round(($enviosConClick / $enviosConApertura) * 100, 2) : 0;
        $tasaDesuscripcion = $enviosExitosos > 0 ? round(($totalDesuscripciones / $enviosExitosos) * 100, 2) : 0;

        return [
            'periodo_dias' => $dias,
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
    public function getMetricasAperturas(int $dias = 30): array
    {
        return Cache::remember("metricas:aperturas:{$dias}", self::CACHE_TTL, fn () => $this->computeMetricasAperturas($dias));
    }

    private function computeMetricasAperturas(int $dias): array
    {
        $desde = now()->subDays($dias);

        // Por día
        $porDia = DB::table('email_aperturas')
            ->select(
                DB::raw('DATE(fecha_apertura) as fecha'),
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT envio_id) as unicos')
            )
            ->where('fecha_apertura', '>=', $desde)
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
        $porFlujo = DB::table('email_aperturas as ea')
            ->join('envios as e', 'e.id', '=', 'ea.envio_id')
            ->join('flujos as f', 'f.id', '=', 'e.flujo_id')
            ->select(
                'f.id as flujo_id',
                'f.nombre as flujo_nombre',
                DB::raw('COUNT(*) as total_aperturas'),
                DB::raw('COUNT(DISTINCT ea.envio_id) as aperturas_unicas')
            )
            ->where('ea.fecha_apertura', '>=', $desde)
            ->groupBy('f.id', 'f.nombre')
            ->orderByDesc('total_aperturas')
            ->limit(10)
            ->get();

        // Por dispositivo
        $porDispositivo = DB::table('email_aperturas')
            ->select('dispositivo', DB::raw('COUNT(*) as total'))
            ->where('fecha_apertura', '>=', $desde)
            ->groupBy('dispositivo')
            ->orderByDesc('total')
            ->get();

        // Por cliente de email
        $porCliente = DB::table('email_aperturas')
            ->select('cliente_email', DB::raw('COUNT(*) as total'))
            ->where('fecha_apertura', '>=', $desde)
            ->groupBy('cliente_email')
            ->orderByDesc('total')
            ->get();

        // Por hora del día
        $porHora = DB::table('email_aperturas')
            ->select(
                DB::raw('EXTRACT(HOUR FROM fecha_apertura) as hora'),
                DB::raw('COUNT(*) as total')
            )
            ->where('fecha_apertura', '>=', $desde)
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
    public function getMetricasClicks(int $dias = 30): array
    {
        return Cache::remember("metricas:clicks:{$dias}", self::CACHE_TTL, fn () => $this->computeMetricasClicks($dias));
    }

    private function computeMetricasClicks(int $dias): array
    {
        $desde = now()->subDays($dias);

        // Por día
        $porDia = DB::table('email_clicks')
            ->select(
                DB::raw('DATE(fecha_click) as fecha'),
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT envio_id) as unicos')
            )
            ->where('fecha_click', '>=', $desde)
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
        $porFlujo = DB::table('email_clicks as ec')
            ->join('envios as e', 'e.id', '=', 'ec.envio_id')
            ->join('flujos as f', 'f.id', '=', 'e.flujo_id')
            ->select(
                'f.id as flujo_id',
                'f.nombre as flujo_nombre',
                DB::raw('COUNT(*) as total_clicks'),
                DB::raw('COUNT(DISTINCT ec.envio_id) as clicks_unicos')
            )
            ->where('ec.fecha_click', '>=', $desde)
            ->groupBy('f.id', 'f.nombre')
            ->orderByDesc('total_clicks')
            ->limit(10)
            ->get();

        // Top URLs clickeadas
        $topUrls = DB::table('email_clicks')
            ->select('url_original as url_destino', DB::raw('COUNT(*) as total'))
            ->where('fecha_click', '>=', $desde)
            ->whereNotNull('url_original')
            ->groupBy('url_original')
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
    public function getMetricasEnvios(int $dias = 30): array
    {
        return Cache::remember("metricas:envios:{$dias}", self::CACHE_TTL, fn () => $this->computeMetricasEnvios($dias));
    }

    private function computeMetricasEnvios(int $dias): array
    {
        $desde = now()->subDays($dias);

        // Totales por estado
        $porEstado = DB::table('envios')
            ->select('estado', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $desde)
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->toArray();

        // Por canal (email vs sms)
        $porCanal = DB::table('envios')
            ->select('canal', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $desde)
            ->groupBy('canal')
            ->pluck('total', 'canal')
            ->toArray();

        // Por día y estado
        // Incluir 'abierto' y 'clickeado' como exitosos (son estados posteriores a 'enviado')
        $porDia = DB::table('envios')
            ->select(
                DB::raw('DATE(created_at) as fecha'),
                DB::raw("COUNT(CASE WHEN estado IN ('enviado', 'entregado', 'abierto', 'clickeado') THEN 1 END) as exitosos"),
                DB::raw("COUNT(CASE WHEN estado = 'fallido' THEN 1 END) as fallidos"),
                DB::raw("COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes")
            )
            ->where('created_at', '>=', $desde)
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
        // Incluir 'abierto' y 'clickeado' como exitosos (son estados posteriores a 'enviado')
        $porFlujo = DB::table('envios as e')
            ->join('flujos as f', 'f.id', '=', 'e.flujo_id')
            ->select(
                'f.id as flujo_id',
                'f.nombre as flujo_nombre',
                DB::raw('COUNT(*) as total'),
                DB::raw("COUNT(CASE WHEN e.estado IN ('enviado', 'entregado', 'abierto', 'clickeado') THEN 1 END) as exitosos"),
                DB::raw("COUNT(CASE WHEN e.estado = 'fallido' THEN 1 END) as fallidos")
            )
            ->where('e.created_at', '>=', $desde)
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
    public function getMetricasDesuscripciones(int $dias = 30): array
    {
        if (! Schema::hasTable('desuscripciones')) {
            return $this->getDesuscripcionesVacias($dias);
        }

        return Cache::remember("metricas:desuscripciones:{$dias}", self::CACHE_TTL, fn () => $this->computeMetricasDesuscripciones($dias));
    }

    private function computeMetricasDesuscripciones(int $dias): array
    {
        $desde = now()->subDays($dias);

        $total = Desuscripcion::where('created_at', '>=', $desde)->count();

        // Por canal
        $porCanal = DB::table('desuscripciones')
            ->select('canal', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $desde)
            ->groupBy('canal')
            ->pluck('total', 'canal')
            ->toArray();

        // Por motivo
        $porMotivo = DB::table('desuscripciones')
            ->select('motivo', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $desde)
            ->whereNotNull('motivo')
            ->groupBy('motivo')
            ->orderByDesc('total')
            ->get();

        // Por día (tendencia)
        $porDia = DB::table('desuscripciones')
            ->select(
                DB::raw('DATE(created_at) as fecha'),
                DB::raw('COUNT(*) as total')
            )
            ->where('created_at', '>=', $desde)
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
            ];
        }

        // Por flujo (si aplica)
        $porFlujo = DB::table('desuscripciones as d')
            ->leftJoin('flujos as f', 'f.id', '=', 'd.flujo_id')
            ->select(
                'f.id as flujo_id',
                'f.nombre as flujo_nombre',
                DB::raw('COUNT(*) as total')
            )
            ->where('d.created_at', '>=', $desde)
            ->whereNotNull('d.flujo_id')
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
     * Retorna estructura vacía para desuscripciones cuando la tabla no existe
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
    public function getMetricasConversiones(int $dias = 30): array
    {
        return Cache::remember("metricas:conversiones:{$dias}", self::CACHE_TTL, fn () => $this->computeMetricasConversiones($dias));
    }

    private function computeMetricasConversiones(int $dias): array
    {
        $desde = now()->subDays($dias);

        $total = Prospecto::where('estado', 'convertido')
            ->where('updated_at', '>=', $desde)
            ->count();

        $totalProspectos = Prospecto::where('created_at', '>=', $desde)->count();
        $tasaConversion = $totalProspectos > 0 ? round(($total / $totalProspectos) * 100, 2) : 0;

        // Por tipo de prospecto
        $porTipo = DB::table('prospectos as p')
            ->join('tipo_prospecto as tp', 'tp.id', '=', 'p.tipo_prospecto_id')
            ->select(
                'tp.nombre as tipo',
                DB::raw('COUNT(*) as total')
            )
            ->where('p.estado', 'convertido')
            ->where('p.updated_at', '>=', $desde)
            ->groupBy('tp.id', 'tp.nombre')
            ->orderByDesc('total')
            ->get();

        // Por día
        $porDia = DB::table('prospectos')
            ->select(
                DB::raw('DATE(updated_at) as fecha'),
                DB::raw('COUNT(*) as total')
            )
            ->where('estado', 'convertido')
            ->where('updated_at', '>=', $desde)
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
     * Optimizado: 4 queries con conditional aggregation en vez de 8 separadas
     */
    public function getTendencias(int $dias = 30): array
    {
        return Cache::remember("metricas:tendencias:{$dias}", self::CACHE_TTL, fn () => $this->computeTendencias($dias));
    }

    private function computeTendencias(int $dias): array
    {
        $desdeActual = now()->subDays($dias);
        $desdeAnterior = now()->subDays($dias * 2);
        $hastaAnterior = now()->subDays($dias);

        // 1 query para envíos (actual + anterior)
        $envios = DB::table('envios')
            ->where('created_at', '>=', $desdeAnterior)
            ->selectRaw('
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as actual,
                COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) as anterior
            ', [$desdeActual, $desdeAnterior, $hastaAnterior])
            ->first();

        // 1 query para aperturas (actual + anterior)
        $aperturas = DB::table('email_aperturas')
            ->where('created_at', '>=', $desdeAnterior)
            ->selectRaw('
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as actual,
                COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) as anterior
            ', [$desdeActual, $desdeAnterior, $hastaAnterior])
            ->first();

        // 1 query para clicks (actual + anterior)
        $clicks = DB::table('email_clicks')
            ->where('created_at', '>=', $desdeAnterior)
            ->selectRaw('
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as actual,
                COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) as anterior
            ', [$desdeActual, $desdeAnterior, $hastaAnterior])
            ->first();

        // 1 query para desuscripciones (actual + anterior)
        $desuscripcionesActual = 0;
        $desuscripcionesAnterior = 0;
        if (Schema::hasTable('desuscripciones')) {
            $desus = DB::table('desuscripciones')
                ->where('created_at', '>=', $desdeAnterior)
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
     * Top flujos por rendimiento
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
     * Invalida el cache de métricas
     */
    public function invalidarCache(): void
    {
        $periodos = [7, 30, 90];
        $tipos = ['resumen', 'aperturas', 'clicks', 'envios', 'desuscripciones', 'conversiones', 'tendencias'];

        foreach ($periodos as $dias) {
            Cache::forget("metricas_dashboard_{$dias}");
            foreach ($tipos as $tipo) {
                Cache::forget("metricas:{$tipo}:{$dias}");
            }
            Cache::forget("metricas:top_flujos:{$dias}:5");
            Cache::forget("metricas:top_flujos:{$dias}:10");
        }
    }
}
