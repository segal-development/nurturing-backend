<?php

namespace App\Http\Controllers;

use App\Http\Resources\LoteResource;
use App\Models\Lote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoteController extends Controller
{
    /**
     * Listar todos los lotes del usuario (para el selector)
     */
    public function index(Request $request): JsonResponse
    {
        $lotes = Lote::with(['importaciones' => function ($query) {
                $query->select('id', 'lote_id', 'nombre_archivo', 'estado', 'total_registros', 'registros_exitosos', 'registros_fallidos');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => LoteResource::collection($lotes),
        ]);
    }

    /**
     * Obtener lotes abiertos (para agregar más archivos)
     */
    public function abiertos(Request $request): JsonResponse
    {
        $lotes = Lote::where('estado', 'abierto')
            ->orWhere('estado', 'procesando')
            ->with(['importaciones' => function ($query) {
                $query->select('id', 'lote_id', 'nombre_archivo', 'estado', 'total_registros');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => LoteResource::collection($lotes),
        ]);
    }

    /**
     * Crear un nuevo lote
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|min:3|max:100',
        ]);

        $lote = Lote::create([
            'nombre' => $request->input('nombre'),
            'user_id' => $request->user()->id,
            'estado' => 'abierto',
        ]);

        return response()->json([
            'mensaje' => 'Lote creado exitosamente',
            'data' => new LoteResource($lote),
        ], 201);
    }

    /**
     * Obtener un lote específico con sus importaciones
     */
    public function show(Lote $lote): JsonResponse
    {
        $lote->load(['importaciones', 'user']);

        return response()->json([
            'data' => new LoteResource($lote),
        ]);
    }

    /**
     * Cerrar/Finalizar un lote manualmente (no permite más archivos)
     * 
     * Este método es llamado cuando el usuario clickea "Finalizar carga" en el frontend.
     * Permite cerrar el lote aunque haya importaciones fallidas.
     */
    public function cerrar(Lote $lote): JsonResponse
    {
        if ($lote->estado === 'completado') {
            return response()->json([
                'mensaje' => 'El lote ya está completado',
            ], 422);
        }

        // Verificar si hay importaciones procesando o pendientes
        $importaciones = $lote->importaciones()->get();
        $hayProcesando = $importaciones->contains(fn($i) => in_array($i->estado, ['procesando', 'pendiente']));
        
        if ($hayProcesando) {
            return response()->json([
                'mensaje' => 'No se puede cerrar el lote mientras hay importaciones en proceso',
                'importaciones_pendientes' => $importaciones
                    ->filter(fn($i) => in_array($i->estado, ['procesando', 'pendiente']))
                    ->map(fn($i) => ['id' => $i->id, 'nombre' => $i->nombre_archivo, 'estado' => $i->estado]),
            ], 422);
        }

        // Recalcular totales antes de cerrar
        $lote->recalcularTotales();

        // Determinar estado final basado en las importaciones
        $algunaFallida = $importaciones->contains(fn($i) => $i->estado === 'fallido');
        $todasFallidas = $importaciones->every(fn($i) => $i->estado === 'fallido');
        
        // Si todas fallaron -> fallido, si algunas fallaron -> completado (parcial), si ninguna falló -> completado
        if ($todasFallidas && $importaciones->count() > 0) {
            $lote->estado = 'fallido';
        } else {
            $lote->estado = 'completado';
        }
        
        $lote->cerrado_en = now();
        $lote->save();

        return response()->json([
            'mensaje' => $algunaFallida 
                ? 'Lote cerrado con algunas importaciones fallidas' 
                : 'Lote cerrado exitosamente',
            'data' => new LoteResource($lote->fresh(['importaciones'])),
        ]);
    }

    /**
     * Obtener progreso del lote (para polling)
     * Incluye información detallada de cada importación para tracking en tiempo real
     */
    public function progreso(Lote $lote): JsonResponse
    {
        // Recargar importaciones frescas
        $lote->load('importaciones');
        $lote->recalcularTotales();
        
        $importaciones = $lote->importaciones;
        
        // Calcular estadísticas agregadas
        $archivosCompletados = $importaciones->filter(fn($i) => $i->estado === 'completado')->count();
        $archivosProcesando = $importaciones->filter(fn($i) => $i->estado === 'procesando')->count();
        $archivosPendientes = $importaciones->filter(fn($i) => $i->estado === 'pendiente')->count();
        $archivosFallidos = $importaciones->filter(fn($i) => $i->estado === 'fallido')->count();
        
        // Calcular progreso total estimado
        $totalEstimado = $importaciones->sum(fn($i) => $i->metadata['total_estimado'] ?? $i->total_registros);
        $totalProcesado = $importaciones->sum('total_registros');
        $progresoPorcentaje = $totalEstimado > 0 ? round(($totalProcesado / $totalEstimado) * 100, 1) : 0;
        
        return response()->json([
            'data' => [
                'id' => $lote->id,
                'nombre' => $lote->nombre,
                'estado' => $lote->estado,
                'created_at' => $lote->created_at->toISOString(),
                
                // Contadores de archivos
                'total_archivos' => $lote->total_archivos,
                'archivos_completados' => $archivosCompletados,
                'archivos_procesando' => $archivosProcesando,
                'archivos_pendientes' => $archivosPendientes,
                'archivos_fallidos' => $archivosFallidos,
                
                // Contadores de registros
                'total_registros' => $lote->total_registros,
                'registros_exitosos' => $lote->registros_exitosos,
                'registros_fallidos' => $lote->registros_fallidos,
                'total_estimado' => $totalEstimado,
                'progreso_porcentaje' => $progresoPorcentaje,
                
                // Detalle por importación
                'importaciones' => $importaciones->map(fn($i) => [
                    'id' => $i->id,
                    'nombre_archivo' => $i->nombre_archivo,
                    'estado' => $i->estado,
                    'total_registros' => $i->total_registros,
                    'registros_exitosos' => $i->registros_exitosos,
                    'registros_fallidos' => $i->registros_fallidos,
                    'total_estimado' => $i->metadata['total_estimado'] ?? $i->total_registros,
                    'progreso_porcentaje' => $this->calcularProgresoImportacion($i),
                    'error' => $i->metadata['error'] ?? null,
                ]),
            ],
        ]);
    }

    /**
     * Calcula el porcentaje de progreso de una importación individual
     */
    private function calcularProgresoImportacion($importacion): float
    {
        if ($importacion->estado === 'completado') {
            return 100;
        }
        
        if ($importacion->estado === 'fallido') {
            return 0;
        }
        
        $estimado = $importacion->metadata['total_estimado'] ?? 0;
        $procesado = $importacion->total_registros ?? 0;
        
        if ($estimado <= 0) {
            return 0;
        }
        
        return min(round(($procesado / $estimado) * 100, 1), 99.9); // Max 99.9 hasta que complete
    }
}
