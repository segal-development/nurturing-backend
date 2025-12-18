<?php

namespace App\Http\Controllers;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Services\CostoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for cost-related endpoints.
 * 
 * Handles:
 * - Cost estimation for flows
 * - Cost dashboard statistics
 * - Pricing information
 */
class CostoController extends Controller
{
    public function __construct(
        private CostoService $costoService
    ) {}

    /**
     * Get current pricing configuration.
     * 
     * GET /api/costos/precios
     */
    public function getPrecios(): JsonResponse
    {
        return response()->json([
            'error' => false,
            'data' => $this->costoService->getPrecios(),
        ]);
    }

    /**
     * Calculate estimated cost for a flow.
     * 
     * GET /api/flujos/{flujo}/costo-estimado?cantidad_prospectos=100
     * 
     * @param Flujo $flujo
     * @param Request $request
     */
    public function getCostoEstimado(Flujo $flujo, Request $request): JsonResponse
    {
        $cantidadProspectos = (int) $request->input('cantidad_prospectos', 1);

        if ($cantidadProspectos < 1) {
            return response()->json([
                'error' => true,
                'message' => 'La cantidad de prospectos debe ser al menos 1',
            ], 422);
        }

        $costoEstimado = $this->costoService->calcularCostoEstimado($flujo, $cantidadProspectos);

        return response()->json([
            'error' => false,
            'data' => $costoEstimado,
        ]);
    }

    /**
     * Get cost for a specific execution.
     * 
     * GET /api/ejecuciones/{ejecucion}/costo
     */
    public function getCostoEjecucion(FlujoEjecucion $ejecucion): JsonResponse
    {
        // If execution is completed and has real cost, return it
        if ($ejecucion->estado === 'completed' && $ejecucion->costo_real !== null) {
            return response()->json([
                'error' => false,
                'data' => [
                    'ejecucion_id' => $ejecucion->id,
                    'flujo_id' => $ejecucion->flujo_id,
                    'estado' => $ejecucion->estado,
                    'costo_estimado' => $ejecucion->costo_estimado,
                    'costo_real' => $ejecucion->costo_real,
                    'costo_emails' => $ejecucion->costo_emails,
                    'costo_sms' => $ejecucion->costo_sms,
                    'total_emails_enviados' => $ejecucion->total_emails_enviados,
                    'total_sms_enviados' => $ejecucion->total_sms_enviados,
                    'diferencia' => $ejecucion->costo_estimado 
                        ? round($ejecucion->costo_real - $ejecucion->costo_estimado, 2)
                        : null,
                ],
            ]);
        }

        // Otherwise calculate current cost (for in_progress executions)
        $costoActual = $this->costoService->calcularCostoReal($ejecucion);

        return response()->json([
            'error' => false,
            'data' => array_merge($costoActual, [
                'estado' => $ejecucion->estado,
                'costo_estimado' => $ejecucion->costo_estimado,
                'nota' => $ejecucion->estado !== 'completed' 
                    ? 'El costo puede cambiar mientras la ejecución esté en progreso'
                    : null,
            ]),
        ]);
    }

    /**
     * Get cost dashboard statistics.
     * 
     * GET /api/costos/dashboard?fecha_inicio=2025-01-01&fecha_fin=2025-01-31
     */
    public function getDashboard(Request $request): JsonResponse
    {
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');

        $stats = $this->costoService->getDashboardStats($fechaInicio, $fechaFin);

        return response()->json([
            'error' => false,
            'data' => $stats,
        ]);
    }

    /**
     * Recalculate and update costs for a completed execution.
     * Useful for fixing executions that completed before cost tracking was implemented.
     * 
     * POST /api/ejecuciones/{ejecucion}/recalcular-costo
     */
    public function recalcularCosto(FlujoEjecucion $ejecucion): JsonResponse
    {
        if ($ejecucion->estado !== 'completed') {
            return response()->json([
                'error' => true,
                'message' => 'Solo se pueden recalcular costos de ejecuciones completadas',
            ], 422);
        }

        $ejecucion = $this->costoService->actualizarCostosEjecucion($ejecucion);

        return response()->json([
            'error' => false,
            'message' => 'Costos recalculados correctamente',
            'data' => [
                'costo_real' => $ejecucion->costo_real,
                'costo_emails' => $ejecucion->costo_emails,
                'costo_sms' => $ejecucion->costo_sms,
                'total_emails_enviados' => $ejecucion->total_emails_enviados,
                'total_sms_enviados' => $ejecucion->total_sms_enviados,
            ],
        ]);
    }
}
