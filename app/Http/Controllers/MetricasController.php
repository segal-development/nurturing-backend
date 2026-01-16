<?php

namespace App\Http\Controllers;

use App\Services\MetricasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller para métricas y analytics del dashboard.
 * 
 * Proporciona endpoints para:
 * - Dashboard completo de métricas
 * - Métricas específicas (aperturas, clicks, envíos, etc.)
 * - Métricas por flujo
 * - Exportación de reportes
 */
class MetricasController extends Controller
{
    public function __construct(
        private MetricasService $metricasService
    ) {}

    /**
     * Dashboard completo de métricas
     * 
     * GET /api/metricas/dashboard
     * 
     * Query params:
     * - dias: Período en días (default: 30)
     */
    public function dashboard(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 30);
        $dias = min(max($dias, 7), 365); // Entre 7 y 365 días

        $metricas = $this->metricasService->getDashboardCompleto($dias);

        return response()->json([
            'success' => true,
            'data' => $metricas,
        ]);
    }

    /**
     * Resumen de KPIs principales
     * 
     * GET /api/metricas/resumen
     */
    public function resumen(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 30);
        $resumen = $this->metricasService->getResumenGeneral($dias);

        return response()->json([
            'success' => true,
            'data' => $resumen,
        ]);
    }

    /**
     * Métricas de aperturas de email
     * 
     * GET /api/metricas/aperturas
     */
    public function aperturas(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 30);
        $metricas = $this->metricasService->getMetricasAperturas($dias);

        return response()->json([
            'success' => true,
            'data' => $metricas,
        ]);
    }

    /**
     * Métricas de clicks
     * 
     * GET /api/metricas/clicks
     */
    public function clicks(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 30);
        $metricas = $this->metricasService->getMetricasClicks($dias);

        return response()->json([
            'success' => true,
            'data' => $metricas,
        ]);
    }

    /**
     * Métricas de envíos
     * 
     * GET /api/metricas/envios
     */
    public function envios(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 30);
        $metricas = $this->metricasService->getMetricasEnvios($dias);

        return response()->json([
            'success' => true,
            'data' => $metricas,
        ]);
    }

    /**
     * Métricas de desuscripciones
     * 
     * GET /api/metricas/desuscripciones
     */
    public function desuscripciones(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 30);
        $metricas = $this->metricasService->getMetricasDesuscripciones($dias);

        return response()->json([
            'success' => true,
            'data' => $metricas,
        ]);
    }

    /**
     * Métricas de conversiones
     * 
     * GET /api/metricas/conversiones
     */
    public function conversiones(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 30);
        $metricas = $this->metricasService->getMetricasConversiones($dias);

        return response()->json([
            'success' => true,
            'data' => $metricas,
        ]);
    }

    /**
     * Top flujos por rendimiento
     * 
     * GET /api/metricas/top-flujos
     */
    public function topFlujos(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 30);
        $limit = (int) $request->input('limit', 10);
        $flujos = $this->metricasService->getTopFlujos($dias, $limit);

        return response()->json([
            'success' => true,
            'data' => $flujos,
        ]);
    }

    /**
     * Tendencias comparativas
     * 
     * GET /api/metricas/tendencias
     */
    public function tendencias(Request $request): JsonResponse
    {
        $dias = (int) $request->input('dias', 30);
        $tendencias = $this->metricasService->getTendencias($dias);

        return response()->json([
            'success' => true,
            'data' => $tendencias,
        ]);
    }

    /**
     * Invalida el cache de métricas (para refresh manual)
     * 
     * POST /api/metricas/refresh
     */
    public function refresh(): JsonResponse
    {
        $this->metricasService->invalidarCache();

        return response()->json([
            'success' => true,
            'message' => 'Cache de métricas invalidado',
        ]);
    }
}
