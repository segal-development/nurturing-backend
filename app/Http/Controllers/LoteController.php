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
     * Cerrar/Finalizar un lote (no permite más archivos)
     */
    public function cerrar(Lote $lote): JsonResponse
    {
        if ($lote->estado === 'completado') {
            return response()->json([
                'mensaje' => 'El lote ya está completado',
            ], 422);
        }

        // Recalcular totales antes de cerrar
        $lote->recalcularTotales();

        // Si todas las importaciones están completas, marcar como completado
        $todasCompletadas = $lote->importaciones->every(fn($i) => $i->estado === 'completado');
        
        if ($todasCompletadas) {
            $lote->estado = 'completado';
            $lote->save();
        }

        return response()->json([
            'mensaje' => 'Lote actualizado',
            'data' => new LoteResource($lote->fresh(['importaciones'])),
        ]);
    }

    /**
     * Obtener progreso del lote (para polling)
     */
    public function progreso(Lote $lote): JsonResponse
    {
        $lote->recalcularTotales();
        
        return response()->json([
            'data' => [
                'id' => $lote->id,
                'nombre' => $lote->nombre,
                'estado' => $lote->estado,
                'total_archivos' => $lote->total_archivos,
                'total_registros' => $lote->total_registros,
                'registros_exitosos' => $lote->registros_exitosos,
                'registros_fallidos' => $lote->registros_fallidos,
                'importaciones' => $lote->importaciones->map(fn($i) => [
                    'id' => $i->id,
                    'nombre_archivo' => $i->nombre_archivo,
                    'estado' => $i->estado,
                    'total_registros' => $i->total_registros,
                    'registros_exitosos' => $i->registros_exitosos,
                    'progreso' => $i->metadata['last_processed_row'] ?? 0,
                ]),
            ],
        ]);
    }
}
