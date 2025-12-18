<?php

namespace App\Http\Controllers;

use App\Models\Envio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnvioController extends Controller
{
    /**
     * Obtiene estadísticas de envíos por día
     *
     * GET /api/envios/estadisticas?fecha_inicio=2025-01-01&fecha_fin=2025-01-31
     */
    public function estadisticas(Request $request): JsonResponse
    {
        $fechaInicio = $request->input('fecha_inicio', now()->startOfMonth());
        $fechaFin = $request->input('fecha_fin', now()->endOfMonth());

        // Estadísticas agrupadas por día
        $estadisticasPorDia = Envio::whereBetween('fecha_enviado', [$fechaInicio, $fechaFin])
            ->select(
                DB::raw('DATE(fecha_enviado) as fecha'),
                DB::raw('COUNT(*) as total_envios'),
                DB::raw("SUM(CASE WHEN estado = 'enviado' THEN 1 ELSE 0 END) as exitosos"),
                DB::raw("SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos"),
                DB::raw("SUM(CASE WHEN canal = 'email' THEN 1 ELSE 0 END) as emails"),
                DB::raw("SUM(CASE WHEN canal = 'sms' THEN 1 ELSE 0 END) as sms")
            )
            ->groupBy('fecha')
            ->orderBy('fecha', 'desc')
            ->get();

        // Estadísticas totales del período
        $totales = Envio::whereBetween('fecha_enviado', [$fechaInicio, $fechaFin])
            ->selectRaw('
                COUNT(*) as total_envios,
                SUM(CASE WHEN estado = "enviado" THEN 1 ELSE 0 END) as exitosos,
                SUM(CASE WHEN estado = "fallido" THEN 1 ELSE 0 END) as fallidos,
                SUM(CASE WHEN canal = "email" THEN 1 ELSE 0 END) as emails,
                SUM(CASE WHEN canal = "sms" THEN 1 ELSE 0 END) as sms
            ')
            ->first();

        return response()->json([
            'error' => false,
            'data' => [
                'por_dia' => $estadisticasPorDia,
                'totales' => $totales,
                'periodo' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                ],
            ],
        ]);
    }

    /**
     * Obtiene estadísticas del día actual
     *
     * GET /api/envios/estadisticas/hoy
     */
    public function estadisticasHoy(): JsonResponse
    {
        $hoy = now()->startOfDay();

        $estadisticas = Envio::whereDate('fecha_enviado', $hoy)
            ->selectRaw('
                COUNT(*) as total_envios,
                SUM(CASE WHEN estado = "enviado" THEN 1 ELSE 0 END) as exitosos,
                SUM(CASE WHEN estado = "fallido" THEN 1 ELSE 0 END) as fallidos,
                SUM(CASE WHEN canal = "email" THEN 1 ELSE 0 END) as emails,
                SUM(CASE WHEN canal = "sms" THEN 1 ELSE 0 END) as sms
            ')
            ->first();

        return response()->json([
            'error' => false,
            'data' => [
                'fecha' => $hoy->toDateString(),
                'estadisticas' => $estadisticas,
            ],
        ]);
    }

    /**
     * Lista todos los envíos con filtros opcionales
     *
     * GET /api/envios?estado=enviado&canal=email&fecha_desde=2025-01-01
     * 
     * Response format:
     * {
     *   "data": [...envios],
     *   "meta": { "total", "pagina", "por_pagina", "total_paginas" }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $query = Envio::with(['prospecto', 'flujo']);

        // Filtrar por estado
        if ($request->filled('estado')) {
            $query->where('estado', $request->input('estado'));
        }

        // Filtrar por canal
        if ($request->filled('canal')) {
            $query->where('canal', $request->input('canal'));
        }

        // Filtrar por flujo
        if ($request->filled('flujo_id')) {
            $query->where('flujo_id', $request->input('flujo_id'));
        }

        // Filtrar por fecha desde
        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_enviado', '>=', $request->input('fecha_desde'));
        }

        // Filtrar por fecha hasta
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_enviado', '<=', $request->input('fecha_hasta'));
        }

        $porPagina = (int) $request->input('por_pagina', 50);
        $pagina = (int) $request->input('pagina', 1);

        $envios = $query->orderBy('created_at', 'desc')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        // Transformar envíos para incluir metadata.destinatario
        $enviosTransformados = collect($envios->items())->map(function ($envio) {
            return [
                'id' => $envio->id,
                'flujo_id' => $envio->flujo_id,
                'prospecto_id' => $envio->prospecto_id,
                'estado' => $envio->estado,
                'canal' => $envio->canal ?? 'email',
                'fecha_creacion' => $envio->created_at?->toISOString(),
                'fecha_enviado' => $envio->fecha_enviado,
                'contenido' => $envio->contenido ?? '',
                'metadata' => [
                    'destinatario' => $envio->prospecto?->email ?? $envio->prospecto?->celular ?? 'Sin destinatario',
                    'asunto' => $envio->asunto ?? null,
                    'error' => $envio->error_mensaje ?? null,
                ],
                'prospecto' => $envio->prospecto,
                'flujo' => $envio->flujo,
            ];
        });

        return response()->json([
            'data' => $enviosTransformados,
            'meta' => [
                'total' => $envios->total(),
                'pagina' => $envios->currentPage(),
                'por_pagina' => $envios->perPage(),
                'total_paginas' => $envios->lastPage(),
            ],
        ]);
    }

    /**
     * Obtiene el detalle de un envío específico
     *
     * GET /api/envios/{envio}
     */
    public function show(Envio $envio): JsonResponse
    {
        $envio->load(['prospecto', 'flujo', 'etapaFlujo']);

        return response()->json([
            'error' => false,
            'data' => $envio,
        ]);
    }

    /**
     * Obtiene el contador de envíos por flujo
     *
     * GET /api/envios/contador-por-flujo
     */
    public function contadorPorFlujo(Request $request): JsonResponse
    {
        $fechaInicio = $request->input('fecha_inicio', now()->startOfMonth());
        $fechaFin = $request->input('fecha_fin', now()->endOfMonth());

        $contadores = Envio::whereBetween('fecha_enviado', [$fechaInicio, $fechaFin])
            ->whereNotNull('flujo_id')
            ->select(
                'flujo_id',
                DB::raw('COUNT(*) as total_envios'),
                DB::raw("SUM(CASE WHEN estado = 'enviado' THEN 1 ELSE 0 END) as exitosos"),
                DB::raw("SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos")
            )
            ->with('flujo:id,nombre')
            ->groupBy('flujo_id')
            ->get();

        return response()->json([
            'error' => false,
            'data' => $contadores,
        ]);
    }
}
