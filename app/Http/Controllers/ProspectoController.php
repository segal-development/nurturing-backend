<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProspectoRequest;
use App\Http\Requests\UpdateProspectoRequest;
use App\Http\Resources\ProspectoResource;
use App\Models\Prospecto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProspectoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Prospecto::query()->with(['tipoProspecto', 'importacion']);

        // Filtrar por lote (agrupa múltiples importaciones)
        if ($request->filled('lote_id')) {
            $query->whereHas('importacion', function ($q) use ($request) {
                $q->where('lote_id', $request->input('lote_id'));
            });
        }

        // Filtrar por importación específica (ID) - mantener compatibilidad
        if ($request->filled('importacion_id')) {
            $query->where('importacion_id', $request->input('importacion_id'));
        }

        // Filtrar por origen de la importación
        if ($request->filled('origen')) {
            $query->whereHas('importacion', function ($q) use ($request) {
                $q->where('origen', $request->input('origen'));
            });
        }

        // Filtrar por estado
        if ($request->filled('estado')) {
            $query->where('estado', $request->input('estado'));
        }

        // Filtrar por tipo de prospecto (tipo de deuda)
        if ($request->filled('tipo_prospecto_id')) {
            $query->where('tipo_prospecto_id', $request->input('tipo_prospecto_id'));
        }

        // Filtrar por rango de monto de deuda
        if ($request->filled('monto_deuda_min')) {
            $query->where('monto_deuda', '>=', $request->input('monto_deuda_min'));
        }

        if ($request->filled('monto_deuda_max')) {
            $query->where('monto_deuda', '<=', $request->input('monto_deuda_max'));
        }

        // Búsqueda de texto
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('telefono', 'like', "%{$search}%");
            });
        }

        // Ordenamiento
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginación
        $perPage = $request->input('per_page', 15);
        $prospectos = $query->paginate($perPage);

        return response()->json([
            'data' => ProspectoResource::collection($prospectos),
            'meta' => [
                'current_page' => $prospectos->currentPage(),
                'total' => $prospectos->total(),
                'per_page' => $prospectos->perPage(),
                'last_page' => $prospectos->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProspectoRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! isset($data['tipo_prospecto_id']) && isset($data['monto_deuda'])) {
            $tipoProspecto = \App\Models\TipoProspecto::findByMonto((float) $data['monto_deuda']);

            if (! $tipoProspecto) {
                return response()->json([
                    'mensaje' => 'No se encontró un tipo de prospecto para el monto de deuda especificado',
                    'monto_deuda' => $data['monto_deuda'],
                ], 422);
            }

            $data['tipo_prospecto_id'] = $tipoProspecto->id;
        }

        if (! isset($data['tipo_prospecto_id'])) {
            return response()->json([
                'mensaje' => 'Debe proporcionar un tipo de prospecto o un monto de deuda para determinar el tipo automáticamente',
            ], 422);
        }

        $prospecto = Prospecto::create($data);

        $prospecto->load(['tipoProspecto', 'importacion']);

        return response()->json([
            'mensaje' => 'Prospecto creado exitosamente',
            'data' => new ProspectoResource($prospecto),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Prospecto $prospecto): JsonResponse
    {
        $prospecto->load([
            'tipoProspecto',
            'importacion',
            'prospectosEnFlujo.flujo',
            'envios' => function ($query) {
                $query->latest()->limit(10);
            },
        ]);

        return response()->json([
            'data' => new ProspectoResource($prospecto),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProspectoRequest $request, Prospecto $prospecto): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['monto_deuda']) && ! isset($data['tipo_prospecto_id'])) {
            $tipoProspecto = \App\Models\TipoProspecto::findByMonto((float) $data['monto_deuda']);

            if ($tipoProspecto) {
                $data['tipo_prospecto_id'] = $tipoProspecto->id;
            }
        }

        $prospecto->update($data);

        $prospecto->load(['tipoProspecto', 'importacion']);

        return response()->json([
            'mensaje' => 'Prospecto actualizado exitosamente',
            'data' => new ProspectoResource($prospecto),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Prospecto $prospecto): JsonResponse
    {
        if ($prospecto->prospectosEnFlujo()->exists()) {
            return response()->json([
                'mensaje' => 'No se puede eliminar un prospecto que está en un flujo activo',
            ], 422);
        }

        $prospecto->delete();

        return response()->json([
            'mensaje' => 'Prospecto eliminado exitosamente',
        ]);
    }

    /**
     * Get statistics about prospectos.
     */
    public function estadisticas(): JsonResponse
    {
        $totalProspectos = Prospecto::count();
        $prospectosPorEstado = Prospecto::query()
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->get()
            ->pluck('total', 'estado');

        $prospectosPorTipo = Prospecto::query()
            ->join('tipo_prospecto', 'prospectos.tipo_prospecto_id', '=', 'tipo_prospecto.id')
            ->selectRaw('tipo_prospecto.nombre, COUNT(prospectos.id) as total')
            ->groupBy('tipo_prospecto.nombre')
            ->get();

        $montoTotalDeuda = Prospecto::sum('monto_deuda');

        return response()->json([
            'data' => [
                'total_prospectos' => $totalProspectos,
                'por_estado' => $prospectosPorEstado,
                'por_tipo' => $prospectosPorTipo,
                'monto_total_deuda' => $montoTotalDeuda,
            ],
        ]);
    }

    /**
     * Get filter options for prospectos.
     * Ahora devuelve lotes en lugar de importaciones individuales.
     */
    public function opcionesFiltrado(): JsonResponse
    {
        // Obtener todos los lotes con sus importaciones y conteo de prospectos
        $lotes = \App\Models\Lote::query()
            ->with(['importaciones' => function ($query) {
                $query->withCount('prospectos');
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($lote) {
                $totalProspectos = $lote->importaciones->sum('prospectos_count');
                return [
                    'id' => $lote->id,
                    'nombre' => $lote->nombre,
                    'estado' => $lote->estado,
                    'total_archivos' => $lote->importaciones->count(),
                    'total_prospectos' => $totalProspectos,
                    'total_registros' => $lote->total_registros,
                    'registros_exitosos' => $lote->registros_exitosos,
                    'created_at' => $lote->created_at?->timezone('America/Santiago')->format('d/m/Y H:i:s'),
                    'importaciones' => $lote->importaciones->map(fn($i) => [
                        'id' => $i->id,
                        'nombre_archivo' => $i->nombre_archivo,
                        'estado' => $i->estado,
                        'total_prospectos' => $i->prospectos_count,
                    ]),
                ];
            });

        // Obtener orígenes únicos (ahora son los nombres de los lotes)
        $origenes = \App\Models\Lote::query()
            ->select('nombre')
            ->distinct()
            ->pluck('nombre')
            ->filter()
            ->values();

        // Obtener estados disponibles
        $estados = Prospecto::query()
            ->select('estado')
            ->distinct()
            ->pluck('estado')
            ->filter()
            ->values();

        // Obtener tipos de prospecto
        $tiposProspecto = \App\Models\TipoProspecto::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->get()
            ->map(function ($tipo) {
                return [
                    'id' => $tipo->id,
                    'nombre' => $tipo->nombre,
                    'descripcion' => $tipo->descripcion,
                    'monto_min' => $tipo->monto_min,
                    'monto_max' => $tipo->monto_max,
                ];
            });

        return response()->json([
            'data' => [
                'lotes' => $lotes,
                'origenes' => $origenes,
                'estados' => $estados,
                'tipos_prospecto' => $tiposProspecto,
            ],
        ]);
    }
}
