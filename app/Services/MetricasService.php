<?php

namespace App\Services;

use App\Models\Desuscripcion;
use App\Models\Flujo;
use App\Models\Prospecto;
use Carbon\Carbon;
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
     * Inicio del periodo como calendario.
     * dias=1 → hoy 00:00, dias=7 → hace 6 días 00:00 (incluye hoy = 7 días).
     */
    private function fechaDesdePeriodo(int $dias)
    {
        return now()->subDays(max(0, $dias - 1))->startOfDay();
    }

    /**
     * Resuelve el rango de fechas a aplicar en las queries.
     *
     * Si se pasan AMBAS fechas (rango custom) se usa ese rango acotado por día.
     * Si no, se conserva el comportamiento histórico: desde el inicio del periodo
     * de $dias hasta ahora.
     *
     * @return array{0: Carbon, 1: Carbon} [$desde, $hasta]
     */
    private function resolverRango(int $dias, ?string $fechaInicio, ?string $fechaFin): array
    {
        if ($fechaInicio !== null && $fechaInicio !== '' && $fechaFin !== null && $fechaFin !== '') {
            return [
                Carbon::parse($fechaInicio)->startOfDay(),
                Carbon::parse($fechaFin)->endOfDay(),
            ];
        }

        return [$this->fechaDesdePeriodo($dias), now()];
    }

    /**
     * Sufijo de cache key para un rango custom. Vacío en modo dias para no
     * alterar las keys existentes.
     */
    private function rangoSuffix(?string $fechaInicio, ?string $fechaFin): string
    {
        if ($fechaInicio !== null && $fechaInicio !== '' && $fechaFin !== null && $fechaFin !== '') {
            return ":{$fechaInicio}_{$fechaFin}";
        }

        return '';
    }

    /**
     * Lista de fechas (Y-m-d) que abarca el rango [$desde, $hasta], inclusive.
     * Usada para rellenar series por día. En modo dias produce exactamente los
     * mismos $dias días que terminan hoy (comportamiento histórico).
     *
     * @return array<int, string>
     */
    private function fechasDelRango(Carbon $desde, Carbon $hasta): array
    {
        $fechas = [];
        $cursor = $desde->copy()->startOfDay();
        $fin = $hasta->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($fin)) {
            $fechas[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $fechas;
    }

    /**
     * Obtiene todas las métricas del dashboard en un solo método.
     */
    public function getDashboardCompleto(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas_dashboard_{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($dias, $flujoId, $fechaInicio, $fechaFin) {
            $data = [
                'resumen' => $this->getResumenGeneral($dias, $flujoId, $fechaInicio, $fechaFin),
                'aperturas' => $this->getMetricasAperturas($dias, $flujoId, $fechaInicio, $fechaFin),
                'clicks' => $this->getMetricasClicks($dias, $flujoId, $fechaInicio, $fechaFin),
                'envios' => $this->getMetricasEnvios($dias, $flujoId, $fechaInicio, $fechaFin),
                'desuscripciones' => $this->getMetricasDesuscripciones($dias, $flujoId, $fechaInicio, $fechaFin),
                'conversiones' => $this->getMetricasConversiones($dias, $flujoId, $fechaInicio, $fechaFin),
                'tendencias' => $this->getTendencias($dias, $flujoId, $fechaInicio, $fechaFin),
                'nuevos_prospectos' => $this->getNuevosProspectosPorDia($dias, $flujoId, $fechaInicio, $fechaFin),
                'clientes_ingresados' => $this->getClientesIngresados($dias, $flujoId, $fechaInicio, $fechaFin),
                // Embudo por COHORTE (solo por flujo): de los que ENTRARON en la ventana, cuántos
                // recibieron y abrieron. Así el embudo nunca crece (recibieron <= entraron) — a
                // diferencia de los KPI de período, que cuentan envíos de cualquier cohorte.
                'embudo' => $flujoId !== null
                    ? $this->getEmbudoCohorte($dias, $flujoId, $fechaInicio, $fechaFin)
                    : null,
                'problemas_envio' => $this->getProblemasEnvio($dias, $flujoId, $fechaInicio, $fechaFin),
                'reconciliacion_sysgal' => $this->getReconciliacionSysgal(),
                'envios_hoy' => $this->getEnviosHoyConFallback($flujoId),
                'generado_at' => now()->toIso8601String(),
            ];

            // Top flujos solo tiene sentido cuando NO hay filtro de flujo
            if ($flujoId === null) {
                $data['top_flujos'] = $this->getTopFlujos($dias, 5, $fechaInicio, $fechaFin);
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
    public function getResumenGeneral(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:resumen:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeResumenGeneral($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    private function computeResumenGeneral(int $dias, ?int $flujoId, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        // Query 1: Envíos. Excluimos duplicados marcados (race condition arreglada el
        // 2026-05-28) para que Tasa de Entrega y Total Envíos no se vean afectados por ellos.
        $envioStats = DB::table('envios')
            ->whereBetween('created_at', [$desde, $hasta])
            ->whereRaw("COALESCE(metadata->>'razon_fallo', '') != 'duplicado_race_condition'")
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN estado IN ('enviado', 'entregado', 'abierto', 'clickeado') THEN 1 END) as exitosos
            ")
            ->first();

        $totalEnvios = (int) ($envioStats->total ?? 0);
        $enviosExitosos = (int) ($envioStats->exitosos ?? 0);

        // Query 2: Aperturas de ENVIOS del período (JOIN siempre, para que la tasa sea coherente).
        // Sin esto, las aperturas pueden ser de emails enviados antes del período y la tasa supera 100%.
        $aperturaQuery = DB::table('email_aperturas as ea')
            ->join('envios as e', 'e.id', '=', 'ea.envio_id')
            ->whereBetween('e.created_at', [$desde, $hasta]);
        if ($flujoId !== null) {
            $aperturaQuery->where('e.flujo_id', $flujoId);
        }
        $aperturaStats = $aperturaQuery
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT ea.envio_id) as unicos')
            ->first();

        $totalAperturas = (int) ($aperturaStats->total ?? 0);
        $enviosConApertura = (int) ($aperturaStats->unicos ?? 0);

        // Query 3: Clicks de ENVIOS del período (misma lógica que aperturas).
        $clickQuery = DB::table('email_clicks as ec')
            ->join('envios as e', 'e.id', '=', 'ec.envio_id')
            ->whereBetween('e.created_at', [$desde, $hasta]);
        if ($flujoId !== null) {
            $clickQuery->where('e.flujo_id', $flujoId);
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
                ->whereBetween('created_at', [$desde, $hasta])
                ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
                ->count();
        }

        // Conversiones: prospectos en estado 'convertido' actualizados en el período.
        // Filtrado por flujo via JOIN con prospecto_en_flujo cuando aplica.
        $conversionesQuery = DB::table('prospectos as p')
            ->where('p.estado', 'convertido')
            ->whereBetween('p.updated_at', [$desde, $hasta]);
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
    public function getMetricasAperturas(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:aperturas:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasAperturas($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    private function computeMetricasAperturas(int $dias, ?int $flujoId, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        // Por día
        $porDiaQuery = DB::table('email_aperturas as ea')->whereBetween('ea.fecha_apertura', [$desde, $hasta]);
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
        foreach ($this->fechasDelRango($desde, $hasta) as $fecha) {
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
            ->whereBetween('ea.fecha_apertura', [$desde, $hasta]);
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
        $porDispositivoQuery = DB::table('email_aperturas as ea')->whereBetween('ea.fecha_apertura', [$desde, $hasta]);
        if ($flujoId !== null) {
            $porDispositivoQuery->join('envios as e', 'e.id', '=', 'ea.envio_id')->where('e.flujo_id', $flujoId);
        }
        $porDispositivo = $porDispositivoQuery
            ->select('ea.dispositivo', DB::raw('COUNT(*) as total'))
            ->groupBy('ea.dispositivo')
            ->orderByDesc('total')
            ->get();

        // Por cliente de email
        $porClienteQuery = DB::table('email_aperturas as ea')->whereBetween('ea.fecha_apertura', [$desde, $hasta]);
        if ($flujoId !== null) {
            $porClienteQuery->join('envios as e', 'e.id', '=', 'ea.envio_id')->where('e.flujo_id', $flujoId);
        }
        $porCliente = $porClienteQuery
            ->select('ea.cliente_email', DB::raw('COUNT(*) as total'))
            ->groupBy('ea.cliente_email')
            ->orderByDesc('total')
            ->get();

        // Por hora del día
        $porHoraQuery = DB::table('email_aperturas as ea')->whereBetween('ea.fecha_apertura', [$desde, $hasta]);
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
    public function getMetricasClicks(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:clicks:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasClicks($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    private function computeMetricasClicks(int $dias, ?int $flujoId, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        // Por día
        $porDiaQuery = DB::table('email_clicks as ec')->whereBetween('ec.fecha_click', [$desde, $hasta]);
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
        foreach ($this->fechasDelRango($desde, $hasta) as $fecha) {
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
            ->whereBetween('ec.fecha_click', [$desde, $hasta]);
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
            ->whereBetween('ec.fecha_click', [$desde, $hasta])
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
    public function getMetricasEnvios(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:envios:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasEnvios($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    private function computeMetricasEnvios(int $dias, ?int $flujoId, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        // Totales por estado
        $porEstado = DB::table('envios')
            ->select('estado', DB::raw('COUNT(*) as total'))
            ->whereBetween('created_at', [$desde, $hasta])
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->toArray();

        // Por canal (email vs sms)
        $porCanal = DB::table('envios')
            ->select('canal', DB::raw('COUNT(*) as total'))
            ->whereBetween('created_at', [$desde, $hasta])
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('canal')
            ->pluck('total', 'canal')
            ->toArray();

        // Por día y estado.
        // Excluimos duplicados marcados (race condition arreglado el 2026-05-28) para no
        // ensuciar el chart con un spike artificial de fallidos en días pasados.
        $porDia = DB::table('envios')
            ->select(
                DB::raw('DATE(created_at) as fecha'),
                DB::raw("COUNT(CASE WHEN estado IN ('enviado', 'entregado', 'abierto', 'clickeado') THEN 1 END) as exitosos"),
                DB::raw("COUNT(CASE WHEN estado = 'fallido' THEN 1 END) as fallidos"),
                DB::raw("COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes")
            )
            ->whereBetween('created_at', [$desde, $hasta])
            ->whereRaw("COALESCE(metadata->>'razon_fallo', '') != 'duplicado_race_condition'")
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha')
            ->toArray();

        // Rellenar días sin datos
        $porDiaCompleto = [];
        foreach ($this->fechasDelRango($desde, $hasta) as $fecha) {
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
            ->whereBetween('e.created_at', [$desde, $hasta]);
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
    public function getMetricasDesuscripciones(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        if (! Schema::hasTable('desuscripciones')) {
            return $this->getDesuscripcionesVacias($dias, $fechaInicio, $fechaFin);
        }

        $cacheKey = "metricas:desuscripciones:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasDesuscripciones($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    private function computeMetricasDesuscripciones(int $dias, ?int $flujoId, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        $totalQuery = Desuscripcion::whereBetween('created_at', [$desde, $hasta]);
        if ($flujoId !== null) {
            $totalQuery->where('flujo_id', $flujoId);
        }
        $total = $totalQuery->count();

        // Por canal
        $porCanal = DB::table('desuscripciones')
            ->select('canal', DB::raw('COUNT(*) as total'))
            ->whereBetween('created_at', [$desde, $hasta])
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('canal')
            ->pluck('total', 'canal')
            ->toArray();

        // Por motivo
        $porMotivo = DB::table('desuscripciones')
            ->select('motivo', DB::raw('COUNT(*) as total'))
            ->whereBetween('created_at', [$desde, $hasta])
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
            ->whereBetween('created_at', [$desde, $hasta])
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha')
            ->toArray();

        $porDiaCompleto = [];
        foreach ($this->fechasDelRango($desde, $hasta) as $fecha) {
            $data = $porDia[$fecha] ?? null;
            $porDiaCompleto[] = [
                'fecha' => $fecha,
                'total' => $data ? (int) $data->total : 0,
            ];
        }

        // Por flujo
        $porFlujoQuery = DB::table('desuscripciones as d')
            ->leftJoin('flujos as f', 'f.id', '=', 'd.flujo_id')
            ->whereBetween('d.created_at', [$desde, $hasta])
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
    private function getDesuscripcionesVacias(int $dias, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        $porDiaCompleto = [];
        foreach ($this->fechasDelRango($desde, $hasta) as $fecha) {
            $porDiaCompleto[] = [
                'fecha' => $fecha,
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
    public function getMetricasConversiones(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:conversiones:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeMetricasConversiones($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    private function computeMetricasConversiones(int $dias, ?int $flujoId, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        // Total convertidos en el período (filtrado por flujo si aplica)
        $convertidosQuery = DB::table('prospectos as p')
            ->where('p.estado', 'convertido')
            ->whereBetween('p.updated_at', [$desde, $hasta]);
        if ($flujoId !== null) {
            $convertidosQuery->join('prospecto_en_flujo as pf', 'pf.prospecto_id', '=', 'p.id')
                ->where('pf.flujo_id', $flujoId);
        }
        $total = $convertidosQuery->distinct()->count('p.id');

        // Total prospectos para tasa
        $totalProspectosQuery = DB::table('prospectos as p')->whereBetween('p.created_at', [$desde, $hasta]);
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
            ->whereBetween('p.updated_at', [$desde, $hasta]);
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
            ->whereBetween('p.updated_at', [$desde, $hasta]);
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
        foreach ($this->fechasDelRango($desde, $hasta) as $fecha) {
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
    public function getTendencias(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:tendencias:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeTendencias($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    private function computeTendencias(int $dias, ?int $flujoId, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desdeActual, $hastaActual] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        $esRango = $fechaInicio !== null && $fechaInicio !== '' && $fechaFin !== null && $fechaFin !== '';

        // Ventana anterior: misma duración inmediatamente antes de la actual.
        // En modo dias se conserva EXACTO el comportamiento histórico (subDays($dias),
        // un día más que la ventana visible para alinear el corte por calendario).
        $hastaAnterior = $desdeActual;
        if ($esRango) {
            $duracionDias = $desdeActual->copy()->startOfDay()->diffInDays($hastaActual->copy()->startOfDay()) + 1;
            $desdeAnterior = $desdeActual->copy()->subDays($duracionDias);
        } else {
            $desdeAnterior = $desdeActual->copy()->subDays($dias);
        }

        // Envíos (actual + anterior)
        $envios = DB::table('envios')
            ->whereBetween('created_at', [$desdeAnterior, $hastaActual])
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->selectRaw('
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as actual,
                COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) as anterior
            ', [$desdeActual, $desdeAnterior, $hastaAnterior])
            ->first();

        // Aperturas (actual + anterior) — atadas a envios.created_at para coherencia con el KPI
        $aperturasQuery = DB::table('email_aperturas as ea')
            ->join('envios as e', 'e.id', '=', 'ea.envio_id')
            ->whereBetween('e.created_at', [$desdeAnterior, $hastaActual]);
        if ($flujoId !== null) {
            $aperturasQuery->where('e.flujo_id', $flujoId);
        }
        $aperturas = $aperturasQuery
            ->selectRaw('
                COUNT(CASE WHEN e.created_at >= ? THEN 1 END) as actual,
                COUNT(CASE WHEN e.created_at >= ? AND e.created_at < ? THEN 1 END) as anterior
            ', [$desdeActual, $desdeAnterior, $hastaAnterior])
            ->first();

        // Clicks (actual + anterior) — atadas a envios.created_at para coherencia con el KPI
        $clicksQuery = DB::table('email_clicks as ec')
            ->join('envios as e', 'e.id', '=', 'ec.envio_id')
            ->whereBetween('e.created_at', [$desdeAnterior, $hastaActual]);
        if ($flujoId !== null) {
            $clicksQuery->where('e.flujo_id', $flujoId);
        }
        $clicks = $clicksQuery
            ->selectRaw('
                COUNT(CASE WHEN e.created_at >= ? THEN 1 END) as actual,
                COUNT(CASE WHEN e.created_at >= ? AND e.created_at < ? THEN 1 END) as anterior
            ', [$desdeActual, $desdeAnterior, $hastaAnterior])
            ->first();

        // Desuscripciones (actual + anterior)
        $desuscripcionesActual = 0;
        $desuscripcionesAnterior = 0;
        if (Schema::hasTable('desuscripciones')) {
            $desus = DB::table('desuscripciones')
                ->whereBetween('created_at', [$desdeAnterior, $hastaActual])
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
    public function getNuevosProspectosPorDia(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:nuevos_prospectos:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeNuevosProspectosPorDia($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    private function computeNuevosProspectosPorDia(int $dias, ?int $flujoId, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        $porDia = DB::table('prospecto_en_flujo')
            ->select(
                DB::raw('DATE(fecha_inicio) as fecha'),
                DB::raw('COUNT(*) as total')
            )
            ->whereBetween('fecha_inicio', [$desde, $hasta])
            ->where('cancelado', false)
            ->when($flujoId, fn ($q, $id) => $q->where('flujo_id', $id))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha')
            ->toArray();

        $porDiaCompleto = [];
        $total = 0;
        $fechas = $this->fechasDelRango($desde, $hasta);
        foreach ($fechas as $fecha) {
            $data = $porDia[$fecha] ?? null;
            $count = $data ? (int) $data->total : 0;
            $total += $count;
            $porDiaCompleto[] = [
                'fecha' => $fecha,
                'total' => $count,
            ];
        }

        $cantidadDias = count($fechas);
        $promedioDiario = $cantidadDias > 0 ? round($total / $cantidadDias, 1) : 0;

        // Breakdown por flujo — solo cuando no hay filtro, para dar contexto al total.
        $porFlujo = [];
        if ($flujoId === null) {
            $porFlujo = DB::table('prospecto_en_flujo as pf')
                ->join('flujos as f', 'f.id', '=', 'pf.flujo_id')
                ->whereBetween('pf.fecha_inicio', [$desde, $hasta])
                ->where('pf.cancelado', false)
                ->select(
                    'f.id as flujo_id',
                    'f.nombre as flujo_nombre',
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('f.id', 'f.nombre')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => [
                    'flujo_id' => (int) $r->flujo_id,
                    'flujo_nombre' => $r->flujo_nombre,
                    'total' => (int) $r->total,
                ])
                ->toArray();
        }

        return [
            'total' => $total,
            'promedio_diario' => $promedioDiario,
            'por_dia' => $porDiaCompleto,
            'por_flujo' => $porFlujo,
        ];
    }

    /**
     * Embudo por COHORTE para un flujo: de los prospectos que ENTRARON al flujo en la ventana,
     * cuántos RECIBIERON un email exitoso y cuántos ABRIERON. Es MONÓTONO (entraron >= recibieron
     * >= abrieron) porque sigue a la MISMA gente — a diferencia de los KPI de período, que cuentan
     * envíos de cualquier cohorte (eso hacía que "recibieron" superara a "entraron" en los drips).
     */
    private function getEmbudoCohorte(int $dias, int $flujoId, ?string $fechaInicio, ?string $fechaFin): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        // Flujos SYSGAL: el embudo se ancla en la FECHA DE INGRESO real (columna fecha_ingreso),
        // NO en fecha_inicio (cuándo entró al flujo). Así "Entraron" cuadra con "SYSGAL reportó" por
        // día de ingreso, inmune a catch-ups/re-syncs/doble-membresía.
        //  - Clientes-Ingreso: SYSGAL consulta el día (hoy − 3) → ventana de ingreso desplazada −3.
        //  - Contratos Nuevos: SYSGAL consulta el día tal cual → ventana de ingreso = ventana SYSGAL.
        $flujo = Flujo::find($flujoId);
        $esClientesIngreso = $flujo && $flujo->origen === 'Grupo Deudas - Clientes Ingreso';
        $esContratosNuevos = $flujo && $flujo->origen === 'Grupo Deudas - Contratos Nuevos';
        $porFechaIngreso = $esClientesIngreso || $esContratosNuevos;

        if ($porFechaIngreso) {
            // Cohorte CUMULATIVA: todos los prospectos con fecha_ingreso en el período shifted,
            // sin filtro de fecha_inicio. "Entraron al flujo" = total de esa cohorte en el flujo,
            // independiente de cuándo entraron físicamente. Esto da el match natural con SYSGAL
            // (39 reportó → 31 están, 8 no se pudieron) que la gerencia espera ver.
            $shift = $esClientesIngreso ? 3 : 0;
            $desdeIngreso = Carbon::parse($desde)->subDays($shift)->toDateString();
            $hastaIngreso = Carbon::parse($hasta)->subDays($shift)->toDateString();
            $cohorte = DB::table('prospecto_en_flujo')
                ->where('flujo_id', $flujoId)
                ->where('cancelado', false)
                ->whereNotNull('fecha_ingreso')
                ->whereBetween('fecha_ingreso', [$desdeIngreso, $hastaIngreso])
                ->select('prospecto_id');
        } else {
            // Resto: por fecha de ENTRADA al flujo (el envío es ~inmediato).
            $cohorte = DB::table('prospecto_en_flujo')
                ->where('flujo_id', $flujoId)
                ->where('cancelado', false)
                ->whereBetween('fecha_inicio', [$desde, $hasta])
                ->select('prospecto_id');
        }

        $entraron = (clone $cohorte)->distinct()->count('prospecto_id');

        if ($entraron === 0) {
            return [
                'entraron' => 0, 'recibieron' => 0, 'abrieron' => 0, 'clickaron' => 0,
                'desuscribieron' => 0, 'con_problema' => 0,
                'tasa_entrega' => 0, 'tasa_apertura' => 0, 'tasa_ctr' => 0, 'tasa_desuscripcion' => 0,
            ];
        }

        // Recibieron: de la cohorte CUMULATIVA, cuántos recibieron al menos un envío exitoso.
        // Excluye:
        //  - Duplicados marcados (race condition arreglada 2026-05-28).
        //  - Prospectos con email_invalido=true: el envío row puede estar como "enviado" pero
        //    Athena rebotó y marcó el email como inválido — realmente NO llegó al destinatario.
        //    Aparecen en "Datos con problemas de envío".
        $recibieron = DB::table('envios as e')
            ->join('prospectos as p', 'p.id', '=', 'e.prospecto_id')
            ->where('e.flujo_id', $flujoId)
            ->whereIn('e.estado', ['enviado', 'entregado', 'abierto', 'clickeado'])
            ->whereRaw("COALESCE(e.metadata->>'razon_fallo', '') != 'duplicado_race_condition'")
            ->where(function ($q) { $q->where('p.email_invalido', false)->orWhereNull('p.email_invalido'); })
            ->whereIn('e.prospecto_id', (clone $cohorte))
            ->distinct()
            ->count('e.prospecto_id');

        // Abrieron: de la cohorte, cuántos abrieron al menos un email del flujo (sin filtro de fecha).
        $abrieron = DB::table('email_aperturas as ea')
            ->join('envios as e', 'e.id', '=', 'ea.envio_id')
            ->where('e.flujo_id', $flujoId)
            ->whereIn('e.prospecto_id', (clone $cohorte))
            ->distinct()
            ->count('e.prospecto_id');

        // Clickaron: de la cohorte, cuántos clickearon al menos un email (sin filtro de fecha).
        $clickaron = DB::table('email_clicks as ec')
            ->join('envios as e', 'e.id', '=', 'ec.envio_id')
            ->where('e.flujo_id', $flujoId)
            ->whereIn('e.prospecto_id', (clone $cohorte))
            ->distinct()
            ->count('e.prospecto_id');

        // Desuscribieron: de la cohorte cumulativa.
        $desuscribieron = DB::table('desuscripciones')
            ->where('flujo_id', $flujoId)
            ->whereIn('prospecto_id', (clone $cohorte))
            ->distinct()
            ->count('prospecto_id');

        // Con problema de dato: de la cohorte, cuántos NO pueden recibir (email inválido o sin email).
        $conProblema = DB::table('prospectos')
            ->whereIn('id', (clone $cohorte))
            ->where(function ($q) {
                $q->where('email_invalido', true)
                    ->orWhereNull('email')
                    ->orWhere('email', '');
            })
            ->count();

        // Tasas de COHORTE (no de período): por eso cuadran con el embudo de arriba.
        return [
            'entraron' => $entraron,
            'recibieron' => $recibieron,
            'abrieron' => $abrieron,
            'clickaron' => $clickaron,
            'desuscribieron' => $desuscribieron,
            'con_problema' => $conProblema,
            'tasa_entrega' => round($recibieron / $entraron * 100, 2),
            'tasa_apertura' => $recibieron > 0 ? round($abrieron / $recibieron * 100, 2) : 0,
            'tasa_ctr' => $abrieron > 0 ? round($clickaron / $abrieron * 100, 2) : 0,
            'tasa_desuscripcion' => $recibieron > 0 ? round($desuscribieron / $recibieron * 100, 2) : 0,
        ];
    }

    /**
     * Clientes ingresados (prospectos creados) en el período — conteo system-wide.
     *
     * A diferencia de getNuevosProspectosPorDia (que cuenta incorporaciones a flujos),
     * esto cuenta cuántos clientes ENTRARON al sistema (tabla prospectos), sin importar
     * si se asignaron a una campaña. El gap entre ambos = clientes ingresados que NO
     * entraron a ningún flujo. No se filtra por flujo: es una métrica de ingreso global.
     */
    public function getClientesIngresados(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:clientes_ingresados:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeClientesIngresados($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    /**
     * Resultado cacheado del reconcile SYSGAL (contratos + clientes-ingreso) del mes.
     *
     * Lo refresca el comando `nurturing:cache-reconciliacion` (scheduler, en la VM),
     * porque el reconcile pega en vivo a SYSGAL y no debe correr en cada request.
     * Devuelve null si todavía no se computó. NO depende del flujo: es una verificación
     * global SYSGAL ↔ ingresados (clave de cache: metricas:reconciliacion-sysgal).
     *
     * @return array<string, mixed>|null
     */
    public function getReconciliacionSysgal(): ?array
    {
        // Lee la tabla `cache` con KEY LITERAL (sin prefijo de Laravel): la VM que computa
        // el reconcile y la API/Cloud Run NO comparten Redis, y el cache.prefix puede diferir
        // entre servicios. Una key literal en la DB compartida garantiza que se crucen.
        // La escribe nurturing:cache-reconciliacion (scheduler en la VM).
        $row = DB::table('cache')->where('key', 'reconciliacion-sysgal')->first();

        if (! $row) {
            return null;
        }
        if (isset($row->expiration) && (int) $row->expiration < now()->timestamp) {
            return null;
        }

        return json_decode($row->value, true) ?: null;
    }

    /**
     * @return array{total: int, por_dia: array<int, array{fecha: string, total: int}>}
     */
    private function computeClientesIngresados(int $dias, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        // Con flujo seleccionado: clientes que ENTRARON a ese flujo en el período (miembros
        // del flujo, por fecha_inicio). Sin flujo: ingreso global al sistema (prospectos
        // creados). Distinto criterio, MISMA forma de salida {total, por_dia}.
        $porDia = $flujoId !== null
            ? DB::table('prospecto_en_flujo')
                ->select(DB::raw('DATE(fecha_inicio) as fecha'), DB::raw('COUNT(*) as total'))
                ->where('flujo_id', $flujoId)
                ->where('cancelado', false)
                ->whereBetween('fecha_inicio', [$desde, $hasta])
                ->groupBy('fecha')
                ->orderBy('fecha')
                ->get()
                ->keyBy('fecha')
                ->toArray()
            : DB::table('prospectos')
                ->select(DB::raw('DATE(created_at) as fecha'), DB::raw('COUNT(*) as total'))
                ->whereBetween('created_at', [$desde, $hasta])
                ->groupBy('fecha')
                ->orderBy('fecha')
                ->get()
                ->keyBy('fecha')
                ->toArray();

        $porDiaCompleto = [];
        $total = 0;
        foreach ($this->fechasDelRango($desde, $hasta) as $fecha) {
            $count = isset($porDia[$fecha]) ? (int) $porDia[$fecha]->total : 0;
            $total += $count;
            $porDiaCompleto[] = ['fecha' => $fecha, 'total' => $count];
        }

        return [
            'total' => $total,
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

        // Para flujos SYSGAL: filtrar a la cohorte del día (fecha_inicio=hoy AND
        // fecha_ingreso=hoy-shift), no a todos los envíos por created_at. Así no entra
        // el catch-up del sync horario (firmados días previos) que pertenece a otra cohorte.
        $esClientesIngreso = $flujo && $flujo->origen === 'Grupo Deudas - Clientes Ingreso';
        $esContratosNuevos = $flujo && $flujo->origen === 'Grupo Deudas - Contratos Nuevos';
        $esSysgal = $esClientesIngreso || $esContratosNuevos;

        // Envíos de HOY agrupados por etapa. Filtramos node_id NULL: son envíos sin etapa
        // (huérfanos creados fuera del flujo normal); aparecían como "(etapa desconocida)" y
        // confundían. Excluimos duplicados marcados (race condition arreglada 2026-05-28).
        $porEtapaQuery = DB::table('envios as e')
            ->leftJoin('flujo_ejecucion_etapas as fee', 'fee.id', '=', 'e.flujo_ejecucion_etapa_id')
            ->where('e.flujo_id', $flujoId)
            ->whereDate('e.created_at', now()->toDateString())
            ->whereNotNull('fee.node_id')
            ->whereRaw("COALESCE(e.metadata->>'razon_fallo', '') != 'duplicado_race_condition'");

        if ($esSysgal) {
            $shift = $esClientesIngreso ? 3 : 0;
            $hoyShifted = now()->subDays($shift)->toDateString();
            $cohorteIds = DB::table('prospecto_en_flujo')
                ->where('flujo_id', $flujoId)
                ->where('cancelado', false)
                ->whereDate('fecha_inicio', now()->toDateString())
                ->whereDate('fecha_ingreso', $hoyShifted)
                ->pluck('prospecto_id');

            $porEtapaQuery->whereIn('e.prospecto_id', $cohorteIds);
        }

        $porEtapaHoy = $porEtapaQuery
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
                ->whereNotNull('fee.node_id') // huérfanos sin etapa: fuera del listing
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
    public function getTopFlujos(int $dias = 30, int $limit = 5, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:top_flujos:{$dias}:{$limit}".$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeTopFlujos($dias, $limit, $fechaInicio, $fechaFin));
    }

    private function computeTopFlujos(int $dias, int $limit, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        return DB::table('flujos as f')
            ->join('envios as e', function ($join) use ($desde, $hasta) {
                $join->on('e.flujo_id', '=', 'f.id')
                    ->whereBetween('e.created_at', [$desde, $hasta]);
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
            ->havingRaw('COUNT(DISTINCT e.id) > 0')
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
     * Prospectos del flujo con problemas de DATO que impiden el envío:
     * email faltante/inválido o teléfono faltante. Devuelve resumen por motivo
     * (solo los canales que usa el flujo) + lista detallada para corregir en el
     * origen (SYSGAL). Los prospectos igual se insertan al flujo; esto explica
     * por qué a algunos no se les envió.
     *
     * "no_contactables" = no tienen NINGÚN canal válido (en 'ambos': ni email ni
     * teléfono). Los demás reciben al menos un canal, pero se listan igual para
     * limpiar el dato de origen.
     */
    public function getProblemasEnvio(int $dias = 30, ?int $flujoId = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $cacheKey = "metricas:problemas_envio:{$dias}".$this->cacheSuffix($flujoId).$this->rangoSuffix($fechaInicio, $fechaFin);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->computeProblemasEnvio($dias, $flujoId, $fechaInicio, $fechaFin));
    }

    private function computeProblemasEnvio(int $dias, ?int $flujoId, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        [$desde, $hasta] = $this->resolverRango($dias, $fechaInicio, $fechaFin);

        // El canal REAL lo definen los stages (tipo_mensaje), igual que EnviarEtapaJob.
        // flujo.canal_envio puede estar desincronizado (ej: dice 'email' pero los
        // stages mandan 'ambos'), así que NO lo usamos como fuente de verdad.
        $consideraEmail = true;
        $consideraSms = true;
        if ($flujoId !== null) {
            $flujo = Flujo::find($flujoId);
            $tipos = collect($flujo?->config_structure['stages'] ?? [])
                ->pluck('tipo_mensaje')
                ->filter()
                ->unique();
            if ($tipos->isEmpty() && $flujo?->canal_envio) {
                $tipos = collect([$flujo->canal_envio]);
            }
            $consideraEmail = $tipos->contains(fn ($t) => in_array($t, ['email', 'ambos'], true));
            $consideraSms = $tipos->contains(fn ($t) => in_array($t, ['sms', 'ambos'], true));
            // Sin info de canal → considerar ambos para no perder data.
            if (! $consideraEmail && ! $consideraSms) {
                $consideraEmail = $consideraSms = true;
            }
        }

        $canal = $consideraEmail && $consideraSms ? 'ambos' : ($consideraEmail ? 'email' : 'sms');

        // Expresiones SQL (PostgreSQL)
        $sinEmail = "(p.email IS NULL OR p.email = '')";
        $emailInvalido = 'p.email_invalido = true';
        $sinTelefono = "(p.telefono IS NULL OR p.telefono = '')";
        $emailMalo = "({$sinEmail} OR {$emailInvalido})";

        // "con problema": al menos un dato relevante a los canales del flujo está mal.
        // "no contactable": no queda NINGÚN canal válido (en 'ambos': ni email ni teléfono).
        if ($consideraEmail && $consideraSms) {
            $conProblema = "({$emailMalo} OR {$sinTelefono})";
            $noContactable = "({$emailMalo} AND {$sinTelefono})";
        } elseif ($consideraEmail) {
            $conProblema = $emailMalo;
            $noContactable = $emailMalo;
        } else { // solo SMS
            $conProblema = $sinTelefono;
            $noContactable = $sinTelefono;
        }

        // Para flujos SYSGAL alineamos el filtro a la MISMA cohorte que el embudo
        // (fecha_ingreso shifted), no por fecha_inicio. Sin esto, el panel mostraba prospectos
        // que entraron HOY al flujo (incluido legacy recuperado) en vez de los de la cohorte
        // del día seleccionado — generaba contradicción visual con el embudo.
        $esClientesIngreso = $flujoId !== null && isset($flujo)
            && $flujo?->origen === 'Grupo Deudas - Clientes Ingreso';
        $esContratosNuevos = $flujoId !== null && isset($flujo)
            && $flujo?->origen === 'Grupo Deudas - Contratos Nuevos';

        $base = function () use ($desde, $hasta, $flujoId, $esClientesIngreso, $esContratosNuevos) {
            $q = DB::table('prospecto_en_flujo as pf')
                ->join('prospectos as p', 'p.id', '=', 'pf.prospecto_id')
                ->where('pf.cancelado', false)
                ->when($flujoId, fn ($q2, $id) => $q2->where('pf.flujo_id', $id));

            if ($esClientesIngreso || $esContratosNuevos) {
                $shift = $esClientesIngreso ? 3 : 0;
                $desdeIngreso = \Carbon\Carbon::parse($desde)->subDays($shift)->toDateString();
                $hastaIngreso = \Carbon\Carbon::parse($hasta)->subDays($shift)->toDateString();
                $q->whereNotNull('pf.fecha_ingreso')->whereBetween('pf.fecha_ingreso', [$desdeIngreso, $hastaIngreso]);
            } else {
                $q->whereBetween('pf.fecha_inicio', [$desde, $hasta]);
            }

            return $q;
        };

        $stats = $base()->selectRaw("
            COUNT(*) as total_miembros,
            COUNT(CASE WHEN {$sinEmail} THEN 1 END) as sin_email,
            COUNT(CASE WHEN {$emailInvalido} THEN 1 END) as email_invalido,
            COUNT(CASE WHEN {$sinTelefono} THEN 1 END) as sin_telefono,
            COUNT(CASE WHEN {$conProblema} THEN 1 END) as con_problemas,
            COUNT(CASE WHEN {$noContactable} THEN 1 END) as no_contactables
        ")->first();

        $porMotivo = [];
        if ($consideraEmail) {
            $porMotivo['sin_email'] = (int) ($stats->sin_email ?? 0);
            $porMotivo['email_invalido'] = (int) ($stats->email_invalido ?? 0);
        }
        if ($consideraSms) {
            $porMotivo['sin_telefono'] = (int) ($stats->sin_telefono ?? 0);
        }

        // Lista detallada (capada para no inflar el payload)
        $cap = 200;
        $filas = $base()
            ->whereRaw($conProblema)
            ->orderByDesc('pf.fecha_inicio')
            ->limit($cap + 1)
            ->get(['p.id', 'p.nombre', 'p.rut', 'p.email', 'p.telefono', 'p.email_invalido', 'p.email_invalido_motivo']);

        $truncado = $filas->count() > $cap;

        $detalle = $filas->take($cap)->map(function ($p) use ($consideraEmail, $consideraSms) {
            $motivos = [];
            if ($consideraEmail) {
                if (empty($p->email)) {
                    $motivos[] = 'sin_email';
                } elseif ($p->email_invalido) {
                    $motivos[] = 'email_invalido';
                }
            }
            if ($consideraSms && empty($p->telefono)) {
                $motivos[] = 'sin_telefono';
            }

            return [
                'prospecto_id' => $p->id,
                'nombre' => $p->nombre,
                'rut' => $p->rut,
                'motivos' => $motivos,
                'email_invalido_motivo' => $p->email_invalido_motivo,
            ];
        })->values()->all();

        return [
            'canal' => $canal,
            'por_flujo' => $flujoId !== null,
            'total_miembros' => (int) ($stats->total_miembros ?? 0),
            'total_con_problemas' => (int) ($stats->con_problemas ?? 0),
            'no_contactables' => (int) ($stats->no_contactables ?? 0),
            'por_motivo' => $porMotivo,
            'detalle' => $detalle,
            'detalle_truncado' => $truncado,
        ];
    }

    /**
     * Invalida el cache de métricas. Limpia variantes con y sin flujoId.
     */
    public function invalidarCache(): void
    {
        $periodos = [1, 7, 30, 90, 365];
        $tipos = ['resumen', 'aperturas', 'clicks', 'envios', 'desuscripciones', 'conversiones', 'tendencias', 'nuevos_prospectos', 'problemas_envio'];

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
        $periodos = [1, 7, 30, 90, 365];
        $tipos = ['resumen', 'aperturas', 'clicks', 'envios', 'desuscripciones', 'conversiones', 'tendencias', 'nuevos_prospectos', 'problemas_envio'];
        $suffix = ":f{$flujoId}";

        foreach ($periodos as $dias) {
            Cache::forget("metricas_dashboard_{$dias}{$suffix}");
            foreach ($tipos as $tipo) {
                Cache::forget("metricas:{$tipo}:{$dias}{$suffix}");
            }
        }
        Cache::forget("metricas:envios_hoy:f{$flujoId}");
    }
}
