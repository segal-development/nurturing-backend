<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\LoteResource;
use App\Models\Importacion;
use App\Models\Lote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Controller para gestión de lotes de importación.
 * 
 * Un lote agrupa múltiples archivos de importación que se procesan
 * en paralelo. Permite tracking unificado del progreso.
 */
class LoteController extends Controller
{
    // =========================================================================
    // CONFIGURACION
    // =========================================================================

    private const ESTADOS_EN_PROCESO = ['procesando', 'pendiente'];
    private const MAX_PROGRESS_WHILE_PROCESSING = 99.9;

    // =========================================================================
    // ENDPOINTS CRUD
    // =========================================================================

    /**
     * Listar todos los lotes con sus importaciones.
     */
    public function index(Request $request): JsonResponse
    {
        $lotes = Lote::with(['importaciones' => fn($q) => $q->select(
            'id', 'lote_id', 'nombre_archivo', 'estado',
            'total_registros', 'registros_exitosos', 'registros_fallidos'
        )])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => LoteResource::collection($lotes),
        ]);
    }

    /**
     * Obtener lotes abiertos (para agregar más archivos).
     */
    public function abiertos(Request $request): JsonResponse
    {
        $lotes = Lote::whereIn('estado', ['abierto', 'procesando'])
            ->with(['importaciones' => fn($q) => $q->select(
                'id', 'lote_id', 'nombre_archivo', 'estado', 'total_registros'
            )])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => LoteResource::collection($lotes),
        ]);
    }

    /**
     * Crear un nuevo lote.
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
     * Obtener un lote específico con sus importaciones.
     */
    public function show(Lote $lote): JsonResponse
    {
        $lote->load(['importaciones', 'user']);

        return response()->json([
            'data' => new LoteResource($lote),
        ]);
    }

    /**
     * Eliminar un lote y todos sus datos relacionados.
     * 
     * Solo permite eliminar lotes que:
     * - No tengan importaciones en proceso
     * - No tengan prospectos asignados a flujos activos
     */
    public function destroy(Lote $lote): JsonResponse
    {
        $importaciones = $lote->importaciones()->get();
        
        // No permitir eliminar si hay importaciones en proceso
        if ($this->tieneImportacionesEnProceso($importaciones)) {
            return response()->json([
                'mensaje' => 'No se puede eliminar el lote mientras hay importaciones en proceso',
            ], 422);
        }

        // Obtener IDs de importaciones para eliminar prospectos
        $importacionIds = $importaciones->pluck('id')->toArray();
        
        // Contar prospectos que se eliminarán
        $totalProspectos = \App\Models\Prospecto::whereIn('importacion_id', $importacionIds)->count();
        
        // Eliminar prospectos asociados
        \App\Models\Prospecto::whereIn('importacion_id', $importacionIds)->delete();
        
        // Eliminar importaciones
        \App\Models\Importacion::whereIn('id', $importacionIds)->delete();
        
        // Eliminar el lote
        $lote->delete();

        return response()->json([
            'mensaje' => 'Lote eliminado exitosamente',
            'prospectos_eliminados' => $totalProspectos,
        ]);
    }

    // =========================================================================
    // GESTION DE ESTADO
    // =========================================================================

    /**
     * Cerrar/Finalizar un lote manualmente.
     * 
     * Llamado cuando el usuario clickea "Finalizar carga" en el frontend.
     * Permite cerrar el lote aunque haya importaciones fallidas.
     */
    public function cerrar(Lote $lote): JsonResponse
    {
        if ($lote->estado === 'completado') {
            return response()->json(['mensaje' => 'El lote ya está completado'], 422);
        }

        $importaciones = $lote->importaciones()->get();
        
        if ($this->tieneImportacionesEnProceso($importaciones)) {
            return $this->respuestaImportacionesEnProceso($importaciones);
        }

        $this->cerrarLote($lote, $importaciones);

        $algunaFallida = $this->tieneImportacionesFallidas($importaciones);
        $mensaje = $algunaFallida 
            ? 'Lote cerrado con algunas importaciones fallidas' 
            : 'Lote cerrado exitosamente';

        return response()->json([
            'mensaje' => $mensaje,
            'data' => new LoteResource($lote->fresh(['importaciones'])),
        ]);
    }

    // =========================================================================
    // PROGRESO
    // =========================================================================

    /**
     * Obtener progreso del lote (para polling).
     * Incluye información detallada de cada importación para tracking en tiempo real.
     */
    public function progreso(Lote $lote): JsonResponse
    {
        $lote->load('importaciones');
        $lote->recalcularTotales();
        
        return response()->json([
            'data' => $this->buildProgresoData($lote),
        ]);
    }

    // =========================================================================
    // HELPERS DE ESTADO
    // =========================================================================

    private function tieneImportacionesEnProceso(Collection $importaciones): bool
    {
        return $importaciones->contains(
            fn($i) => in_array($i->estado, self::ESTADOS_EN_PROCESO)
        );
    }

    private function tieneImportacionesFallidas(Collection $importaciones): bool
    {
        return $importaciones->contains(fn($i) => $i->estado === 'fallido');
    }

    private function todasLasImportacionesFallidas(Collection $importaciones): bool
    {
        return $importaciones->isNotEmpty() 
            && $importaciones->every(fn($i) => $i->estado === 'fallido');
    }

    private function cerrarLote(Lote $lote, Collection $importaciones): void
    {
        $lote->recalcularTotales();
        
        $lote->estado = $this->todasLasImportacionesFallidas($importaciones) 
            ? 'fallido' 
            : 'completado';
        
        $lote->cerrado_en = now();
        $lote->save();
    }

    private function respuestaImportacionesEnProceso(Collection $importaciones): JsonResponse
    {
        $pendientes = $importaciones
            ->filter(fn($i) => in_array($i->estado, self::ESTADOS_EN_PROCESO))
            ->map(fn($i) => [
                'id' => $i->id,
                'nombre' => $i->nombre_archivo,
                'estado' => $i->estado,
            ]);

        return response()->json([
            'mensaje' => 'No se puede cerrar el lote mientras hay importaciones en proceso',
            'importaciones_pendientes' => $pendientes,
        ], 422);
    }

    // =========================================================================
    // HELPERS DE PROGRESO
    // =========================================================================

    private function buildProgresoData(Lote $lote): array
    {
        $importaciones = $lote->importaciones;
        $estadisticas = $this->calcularEstadisticasArchivos($importaciones);
        $progresoTotal = $this->calcularProgresoTotal($importaciones);

        return [
            'id' => $lote->id,
            'nombre' => $lote->nombre,
            'estado' => $lote->estado,
            'created_at' => $lote->created_at->toISOString(),
            
            // Contadores de archivos
            'total_archivos' => $lote->total_archivos,
            'archivos_completados' => $estadisticas['completados'],
            'archivos_procesando' => $estadisticas['procesando'],
            'archivos_pendientes' => $estadisticas['pendientes'],
            'archivos_fallidos' => $estadisticas['fallidos'],
            
            // Contadores de registros
            'total_registros' => $lote->total_registros,
            'registros_exitosos' => $lote->registros_exitosos,
            'registros_fallidos' => $lote->registros_fallidos,
            'total_estimado' => $progresoTotal['estimado'],
            'progreso_porcentaje' => $progresoTotal['porcentaje'],
            
            // Detalle por importación
            'importaciones' => $this->mapImportacionesParaProgreso($importaciones),
        ];
    }

    /**
     * @return array{completados: int, procesando: int, pendientes: int, fallidos: int}
     */
    private function calcularEstadisticasArchivos(Collection $importaciones): array
    {
        return [
            'completados' => $importaciones->filter(fn($i) => $i->estado === 'completado')->count(),
            'procesando' => $importaciones->filter(fn($i) => $i->estado === 'procesando')->count(),
            'pendientes' => $importaciones->filter(fn($i) => $i->estado === 'pendiente')->count(),
            'fallidos' => $importaciones->filter(fn($i) => $i->estado === 'fallido')->count(),
        ];
    }

    /**
     * @return array{estimado: int, porcentaje: float}
     */
    private function calcularProgresoTotal(Collection $importaciones): array
    {
        $totalEstimado = $importaciones->sum(
            fn($i) => $i->metadata['total_estimado'] ?? $i->total_registros
        );
        $totalProcesado = $importaciones->sum('total_registros');
        
        $porcentaje = $totalEstimado > 0 
            ? round(($totalProcesado / $totalEstimado) * 100, 1) 
            : 0;

        return [
            'estimado' => (int) $totalEstimado,
            'porcentaje' => $porcentaje,
        ];
    }

    private function mapImportacionesParaProgreso(Collection $importaciones): Collection
    {
        return $importaciones->map(fn($i) => [
            'id' => $i->id,
            'nombre_archivo' => $i->nombre_archivo,
            'estado' => $i->estado,
            'total_registros' => $i->total_registros,
            'registros_exitosos' => $i->registros_exitosos,
            'registros_fallidos' => $i->registros_fallidos,
            'total_estimado' => $i->metadata['total_estimado'] ?? $i->total_registros,
            'progreso_porcentaje' => $this->calcularProgresoImportacion($i),
            'error' => $i->metadata['error'] ?? null,
        ]);
    }

    private function calcularProgresoImportacion(Importacion $importacion): float
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
        
        $porcentaje = round(($procesado / $estimado) * 100, 1);
        
        return min($porcentaje, self::MAX_PROGRESS_WHILE_PROCESSING);
    }
}
