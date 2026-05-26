<?php

namespace App\Http\Controllers;

use App\Jobs\GenerarExportSysgalJob;
use App\Services\MetricasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $dias = min(max($dias, 1), 365); // Entre 1 (Hoy) y 365 días

        $flujoId = $request->input('flujo_id');
        $flujoId = $flujoId !== null && $flujoId !== '' ? (int) $flujoId : null;

        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');

        $metricas = $this->metricasService->getDashboardCompleto($dias, $flujoId, $fechaInicio, $fechaFin);

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
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');
        $resumen = $this->metricasService->getResumenGeneral($dias, null, $fechaInicio, $fechaFin);

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
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');
        $metricas = $this->metricasService->getMetricasAperturas($dias, null, $fechaInicio, $fechaFin);

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
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');
        $metricas = $this->metricasService->getMetricasClicks($dias, null, $fechaInicio, $fechaFin);

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
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');
        $metricas = $this->metricasService->getMetricasEnvios($dias, null, $fechaInicio, $fechaFin);

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
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');
        $metricas = $this->metricasService->getMetricasDesuscripciones($dias, null, $fechaInicio, $fechaFin);

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
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');
        $metricas = $this->metricasService->getMetricasConversiones($dias, null, $fechaInicio, $fechaFin);

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
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');
        $flujos = $this->metricasService->getTopFlujos($dias, $limit, $fechaInicio, $fechaFin);

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
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');
        $tendencias = $this->metricasService->getTendencias($dias, null, $fechaInicio, $fechaFin);

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

    /**
     * Encola la generación del export de SYSGAL para un rango arbitrario.
     *
     * POST /api/metricas/export-sysgal  { tipo, desde, hasta }
     *
     * El trabajo real lo hace la VM (única whitelisteada en SYSGAL) vía GenerarExportSysgalJob.
     * Devuelve un token para consultar el estado y descargar.
     */
    public function exportSysgal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo' => 'required|in:contratos,clientes-ingreso',
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ]);

        $token = (string) Str::uuid();

        $this->putExport($token, ['estado' => 'pendiente']);

        GenerarExportSysgalJob::dispatch(
            $token,
            $validated['tipo'],
            $validated['desde'],
            $validated['hasta'],
        );

        return response()->json(['success' => true, 'token' => $token]);
    }

    /**
     * Estado del export (pendiente | listo | error) + conteo. NO incluye el CSV.
     *
     * GET /api/metricas/export-sysgal/{token}
     */
    public function exportSysgalStatus(string $token): JsonResponse
    {
        $data = $this->getExport($token);

        if ($data === null) {
            return response()->json(['success' => false, 'estado' => 'desconocido'], 404);
        }

        unset($data['csv']); // el CSV no viaja en el status (puede ser pesado)

        return response()->json(['success' => true] + $data);
    }

    /**
     * Descarga el CSV generado.
     *
     * GET /api/metricas/export-sysgal/{token}/download
     */
    public function exportSysgalDownload(string $token)
    {
        $data = $this->getExport($token);

        if ($data === null || ($data['estado'] ?? null) !== 'listo' || ! isset($data['csv'])) {
            abort(404, 'Export no disponible');
        }

        $filename = ($data['tipo'] ?? 'export').'-'.($data['desde'] ?? '').'_al_'.($data['hasta'] ?? '').'.csv';

        return response($data['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getExport(string $token): ?array
    {
        $row = DB::table('cache')->where('key', 'export-sysgal:'.$token)->first();

        return $row ? (json_decode($row->value, true) ?: null) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putExport(string $token, array $payload): void
    {
        DB::table('cache')->updateOrInsert(
            ['key' => 'export-sysgal:'.$token],
            ['value' => json_encode($payload), 'expiration' => now()->addHours(2)->timestamp]
        );
    }
}
