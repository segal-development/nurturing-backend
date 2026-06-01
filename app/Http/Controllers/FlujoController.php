<?php

namespace App\Http\Controllers;

use App\Enums\CanalEnvio;
use App\Models\Configuracion;
use App\Models\Flujo;
use App\Models\FlujoCondicion;
use App\Models\Lote;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Services\CanalEnvioResolver;
use App\Services\FlujoStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlujoController extends Controller
{
    public function __construct(
        private readonly CanalEnvioResolver $canalEnvioResolver,
        private readonly FlujoStatsService $flujoStatsService,
    ) {}

    /**
     * Display a listing of flujos.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Flujo::query()->with(['tipoProspecto', 'user']);

        if ($request->filled('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }

        if ($request->filled('tipo_prospecto_id')) {
            $query->where('tipo_prospecto_id', $request->input('tipo_prospecto_id'));
        }

        if ($request->filled('canal_envio')) {
            $query->where('canal_envio', $request->input('canal_envio'));
        }

        // Filtrar por origen si se proporciona
        if ($request->filled('origen')) {
            $query->porOrigen($request->input('origen'));
        }

        // Filtrar por origen_id si se proporciona
        // Inferir origen desde prospectos: flujos que tengan prospectos de ese origen
        if ($request->filled('origen_id')) {
            $origenId = $request->input('origen_id');

            if ($origenId === '_sin_origen') {
                // Flujos sin prospectos asignados
                $query->whereDoesntHave('prospectosEnFlujo');
            } elseif ($origenId !== '_todos') {
                // Flujos que tengan al menos un prospecto de este origen
                $query->whereHas('prospectosEnFlujo', function ($q) use ($origenId) {
                    $q->whereHas('prospecto', function ($q2) use ($origenId) {
                        $q2->whereHas('importacion', function ($q3) use ($origenId) {
                            $q3->where('origen', $origenId);
                        });
                    });
                });
            }
            // '_todos' = no filter, show all
        }

        $flujos = $query->withCount('prospectosEnFlujo')
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => $flujos->items(),
            'meta' => [
                'current_page' => $flujos->currentPage(),
                'total' => $flujos->total(),
                'per_page' => $flujos->perPage(),
                'last_page' => $flujos->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created flujo.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'tipo_prospecto_id' => 'required|exists:tipo_prospecto,id',
            'origen' => 'required|string|max:255',
            'origen_id' => 'nullable|string|max:255',
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'canal_envio' => 'required|in:email,sms,ambos',
        ]);

        // Si no viene origen_id, usar el valor de origen como origen_id
        $origenId = $request->input('origen_id') ?? $request->input('origen');

        $flujo = Flujo::create([
            'tipo_prospecto_id' => $request->input('tipo_prospecto_id'),
            'origen' => $request->input('origen'),
            'origen_id' => $origenId,
            'nombre' => $request->input('nombre'),
            'descripcion' => $request->input('descripcion'),
            'canal_envio' => $request->input('canal_envio'),
            'activo' => true,
            'user_id' => $request->user()->id,
        ]);

        $flujo->load(['tipoProspecto', 'user']);

        return response()->json([
            'mensaje' => 'Flujo creado exitosamente',
            'data' => $flujo,
        ], 201);
    }

    /**
     * Display the specified flujo with its prospectos.
     */
    public function show(Flujo $flujo): JsonResponse
    {
        $flujo->loadCount('prospectosEnFlujo');

        $flujo->load([
            'tipoProspecto',
            'user:id,name,email',
            'prospectosEnFlujo' => function ($query) {
                // Optimizado: solo campos necesarios del prospecto para la vista
                $query->with(['prospecto' => function ($q) {
                    $q->select('id', 'nombre', 'email', 'telefono', 'tipo_prospecto_id', 'created_at');
                }])->latest()->limit(100);
            },
            'flujoEtapas',
            'flujoCondiciones',
            'flujoRamificaciones',
            'flujoNodosFinales',
            'ejecuciones' => function ($query) {
                // Solo campos esenciales de ejecuciones, sin prospectos_ids (JSON grande)
                $query->select([
                    'id', 'flujo_id', 'origen_id', 'estado', 'prospectos_count',
                    'nodo_actual', 'proximo_nodo', 'fecha_proximo_nodo', 'created_at',
                ])->latest()->limit(50);
            },
        ]);

        $estadisticas = $this->flujoStatsService->getProspectoStats($flujo);

        return response()->json([
            'data' => $flujo,
            'estadisticas' => $estadisticas,
        ]);
    }

    /**
     * Update the specified flujo.
     */
    public function update(Request $request, Flujo $flujo): JsonResponse
    {
        $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'descripcion' => 'nullable|string',
            'canal_envio' => 'sometimes|in:email,sms,ambos',
            'activo' => 'sometimes|boolean',
            'auto_asignar_nuevos' => 'sometimes|boolean',
            'nivel_deuda_target' => 'sometimes|nullable|array',
            'nivel_deuda_target.*' => 'string|in:baja,media,alta,sin_informacion',
            'config_visual' => 'sometimes|array',
            'config_visual.nodes' => 'sometimes|array',
            'config_visual.edges' => 'sometimes|array',
            'config_structure' => 'sometimes|array',
            'config_structure.stages' => 'sometimes|array',
            'config_structure.conditions' => 'sometimes|array',
            'config_structure.branches' => 'sometimes|array',
            'config_structure.end_nodes' => 'sometimes|array',
        ]);

        // Normalize empty array to null for nivel_deuda_target
        if ($request->has('nivel_deuda_target') && is_array($request->input('nivel_deuda_target')) && empty($request->input('nivel_deuda_target'))) {
            $request->merge(['nivel_deuda_target' => null]);
        }

        try {
            DB::beginTransaction();

            // Actualizar campos básicos del flujo
            $flujo->update($request->only([
                'nombre',
                'descripcion',
                'canal_envio',
                'activo',
                'auto_asignar_nuevos',
                'nivel_deuda_target',
                'config_visual',
                'config_structure',
            ]));

            // Si se actualizó la estructura del FlowBuilder, actualizar las tablas relacionadas
            if ($request->has('config_structure')) {
                // Eliminar estructura anterior
                $flujo->flujoEtapas()->delete();
                $flujo->flujoCondiciones()->delete();
                $flujo->flujoRamificaciones()->delete();
                $flujo->flujoNodosFinales()->delete();

                // Guardar nueva estructura
                $this->guardarEstructuraFlowBuilder($flujo, $request->input('config_structure'));
            }

            DB::commit();

            $this->flujoStatsService->invalidateCache($flujo->id);

            $flujo->load(['tipoProspecto', 'user']);

            return response()->json([
                'mensaje' => 'Flujo actualizado exitosamente',
                'data' => $flujo,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'mensaje' => 'Error al actualizar el flujo',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified flujo and all its associated data.
     */
    public function destroy(Flujo $flujo): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Contar datos antes de eliminar para el mensaje
            // Optimizado: 1 query con withCount en lugar de 4 queries separadas
            $flujo->loadCount(['prospectosEnFlujo', 'flujoEtapas', 'flujoCondiciones', 'ejecuciones']);

            // Las foreign keys con onDelete('cascade') eliminarán automáticamente:
            // - flujo_etapas
            // - flujo_condiciones
            // - flujo_ramificaciones
            // - flujo_nodos_finales
            // - flujo_ejecuciones (y sus flujo_logs)
            // - prospecto_en_flujo
            // - envios

            $flujo->delete();

            DB::commit();

            return response()->json([
                'mensaje' => 'Flujo eliminado exitosamente',
                'detalles' => [
                    'prospectos_desvinculados' => $flujo->prospectos_en_flujo_count,
                    'etapas_eliminadas' => $flujo->flujo_etapas_count,
                    'condiciones_eliminadas' => $flujo->flujo_condiciones_count,
                    'ejecuciones_eliminadas' => $flujo->ejecuciones_count,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'mensaje' => 'Error al eliminar el flujo',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add prospectos to flujo based on criteria.
     *
     * Supports multiple modes:
     * 1. By prospecto_ids: Specific list of prospect IDs
     * 2. By origen + tipo_prospecto_id: All prospects matching criteria
     * 3. By select_all_from_origin: All prospects from a given origin
     *
     * For large volumes (>100), uses async processing via Job.
     */
    public function agregarProspectos(Request $request, Flujo $flujo): JsonResponse
    {
        $request->validate([
            'prospecto_ids' => 'nullable|array',
            'prospecto_ids.*' => 'integer',
            'lote_ids' => 'nullable|array',
            'lote_ids.*' => 'integer|exists:lotes,id',
            'origen' => 'nullable|string',
            'tipo_prospecto_id' => 'nullable|integer|exists:tipo_prospecto,id',
            'select_all_from_origin' => 'nullable|boolean',
            'canal_asignado' => 'nullable|in:email,sms',
            'metadata_filters' => 'nullable|array',
            'metadata_filters.*' => 'nullable|array',
        ]);

        try {
            $canalAsignado = $request->input('canal_asignado', 'email');
            $selectAllFromOrigin = $request->boolean('select_all_from_origin', false);
            $origen = $request->input('origen', $flujo->origen);
            $tipoProspectoId = $request->input('tipo_prospecto_id', $flujo->tipo_prospecto_id);
            $loteIds = $request->input('lote_ids', []);
            $metadataFilters = $request->input('metadata_filters', []);

            // CASE 0: Select by specific lote_ids (NEW)
            if (! empty($loteIds)) {
                // Update flujo with origen if not set
                if (! $flujo->origen && $origen) {
                    $flujo->update(['origen' => $origen]);
                }
                if (! $flujo->tipo_prospecto_id && $tipoProspectoId) {
                    $flujo->update(['tipo_prospecto_id' => $tipoProspectoId]);
                }

                // Build query for prospects in the selected lotes
                $query = Prospecto::query()
                    ->whereHas('importacion', fn ($q) => $q->whereIn('lote_id', $loteIds));

                // Filter by tipo if specified (and not "Todos")
                if ($tipoProspectoId) {
                    $tipoProspecto = TipoProspecto::find($tipoProspectoId);
                    if ($tipoProspecto && ! $tipoProspecto->esTipoTodos()) {
                        $query->where('tipo_prospecto_id', $tipoProspectoId);
                    }
                }

                // Apply metadata filters (e.g., nivel_deuda)
                $this->applyMetadataFiltersToQuery($query, $metadataFilters);

                $totalEstimado = $query->count();

                if ($totalEstimado === 0) {
                    return response()->json([
                        'mensaje' => 'No se encontraron prospectos en los lotes seleccionados',
                        'resumen' => ['total_encontrados' => 0, 'agregados' => 0],
                    ]);
                }

                // Async for large volumes
                if ($totalEstimado > 100) {
                    $criterios = new \App\DTOs\CriteriosSeleccionProspectos(
                        origen: $origen,
                        tipoProspectoId: $tipoProspectoId,
                        selectAllFromOrigin: false,
                        prospectoIds: [],
                        loteIds: $loteIds,
                        metadataFilters: $metadataFilters
                    );

                    \App\Jobs\AsignarProspectosAFlujoJob::dispatch($flujo, $criterios, $canalAsignado);
                    $flujo->update(['estado_procesamiento' => 'procesando']);

                    return response()->json([
                        'mensaje' => 'Procesamiento de prospectos iniciado en segundo plano',
                        'resumen' => [
                            'total_estimado' => $totalEstimado,
                            'lotes_seleccionados' => count($loteIds),
                            'procesamiento_async' => true,
                        ],
                    ]);
                }

                // Sync for small volumes
                $prospectoIds = $query->pluck('id')->toArray();

                return $this->agregarProspectosPorIds($flujo, $prospectoIds, $canalAsignado);
            }

            // CASE 1: Select all from origin (async processing for large volumes)
            if ($selectAllFromOrigin && $origen) {
                // Update flujo with origen if not set
                if (! $flujo->origen && $origen) {
                    $flujo->update(['origen' => $origen]);
                }
                if (! $flujo->tipo_prospecto_id && $tipoProspectoId) {
                    $flujo->update(['tipo_prospecto_id' => $tipoProspectoId]);
                }

                // Count prospects matching criteria
                $query = Prospecto::query()
                    ->whereHas('importacion', fn ($q) => $q->where('origen', $origen));

                if ($tipoProspectoId) {
                    // Check if it's the "Todos" type
                    $tipoProspecto = TipoProspecto::find($tipoProspectoId);
                    if ($tipoProspecto && ! $tipoProspecto->esTipoTodos()) {
                        $query->where('tipo_prospecto_id', $tipoProspectoId);
                    }
                }

                // Apply metadata filters (e.g., nivel_deuda)
                $this->applyMetadataFiltersToQuery($query, $metadataFilters);

                $totalEstimado = $query->count();

                if ($totalEstimado === 0) {
                    return response()->json([
                        'mensaje' => 'No se encontraron prospectos con los criterios especificados',
                        'resumen' => ['total_encontrados' => 0, 'agregados' => 0],
                    ]);
                }

                // Use async processing for very large volumes only (workers have timeout issues)
                if ($totalEstimado > 100000) {
                    $criterios = new \App\DTOs\CriteriosSeleccionProspectos(
                        origen: $origen,
                        tipoProspectoId: $tipoProspectoId,
                        selectAllFromOrigin: true,
                        prospectoIds: [],
                        loteIds: [],
                        metadataFilters: $metadataFilters
                    );

                    \App\Jobs\AsignarProspectosAFlujoJob::dispatch($flujo, $criterios, $canalAsignado);
                    $flujo->update(['estado_procesamiento' => 'procesando']);

                    return response()->json([
                        'mensaje' => 'Procesamiento de prospectos iniciado en segundo plano',
                        'resumen' => [
                            'total_estimado' => $totalEstimado,
                            'procesamiento_async' => true,
                        ],
                    ]);
                }

                // Sync processing for small volumes
                $prospectoIds = $query->pluck('id')->toArray();

                return $this->agregarProspectosPorIds($flujo, $prospectoIds, $canalAsignado);
            }

            // CASE 2: Specific prospect IDs provided
            if ($request->filled('prospecto_ids')) {
                $prospectoIds = $request->input('prospecto_ids');

                // Update flujo origen/tipo if needed
                if (! $flujo->origen && $origen) {
                    $flujo->update(['origen' => $origen]);
                }
                if (! $flujo->tipo_prospecto_id && $tipoProspectoId) {
                    $flujo->update(['tipo_prospecto_id' => $tipoProspectoId]);
                }

                // Async for large volumes
                if (count($prospectoIds) > 100) {
                    $criterios = \App\DTOs\CriteriosSeleccionProspectos::fromProspectoIds($prospectoIds);
                    \App\Jobs\AsignarProspectosAFlujoJob::dispatch($flujo, $criterios, $canalAsignado);
                    $flujo->update(['estado_procesamiento' => 'procesando']);

                    return response()->json([
                        'mensaje' => 'Procesamiento de prospectos iniciado en segundo plano',
                        'resumen' => [
                            'total_estimado' => count($prospectoIds),
                            'procesamiento_async' => true,
                        ],
                    ]);
                }

                return $this->agregarProspectosPorIds($flujo, $prospectoIds, $canalAsignado);
            }

            return response()->json([
                'mensaje' => 'Debe especificar prospecto_ids o usar select_all_from_origin',
                'error' => 'validation_error',
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => 'Error al agregar prospectos al flujo',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper: Add prospects by IDs synchronously
     *
     * OPTIMIZADO: Usa bulk insert con INSERT IGNORE en vez de N queries exists() + create()
     * Antes: 2N queries (exists + create por cada prospecto)
     * Ahora: 2 queries (1 para obtener existentes, 1 bulk insert)
     */
    private function agregarProspectosPorIds(Flujo $flujo, array $prospectoIds, string $canalAsignado): JsonResponse
    {
        DB::beginTransaction();
        try {
            // 1. Obtener IDs que ya existen en una sola query
            $existentes = ProspectoEnFlujo::where('flujo_id', $flujo->id)
                ->whereIn('prospecto_id', $prospectoIds)
                ->pluck('prospecto_id')
                ->toArray();

            $yaExistentes = count($existentes);

            // 2. Filtrar solo los nuevos
            $nuevosIds = array_diff($prospectoIds, $existentes);
            $agregados = count($nuevosIds);

            // 3. Bulk insert de los nuevos (si hay), vía crearBatch para poblar fecha_ingreso
            if (! empty($nuevosIds)) {
                $now = now();
                ProspectoEnFlujo::crearBatch($flujo, array_values($nuevosIds), $canalAsignado, $now);
            }

            $flujo->update(['estado_procesamiento' => 'completado']);
            DB::commit();

            return response()->json([
                'mensaje' => 'Prospectos agregados al flujo exitosamente',
                'resumen' => [
                    'total_encontrados' => count($prospectoIds),
                    'agregados' => $agregados,
                    'ya_existentes' => $yaExistentes,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Apply metadata filters to a query.
     *
     * Filters are in format: ['campo' => ['valor1', 'valor2']] or ['campo' => 'valor']
     * Uses PostgreSQL JSONB operators for efficient filtering.
     */
    private function applyMetadataFiltersToQuery(\Illuminate\Database\Eloquent\Builder $query, array $metadataFilters): void
    {
        if (empty($metadataFilters)) {
            return;
        }

        foreach ($metadataFilters as $campo => $valores) {
            if (empty($valores)) {
                continue;
            }

            // Normalize to array
            $valores = is_array($valores) ? $valores : [$valores];

            // PostgreSQL JSONB: metadata->>'campo' IN ('valor1', 'valor2')
            $query->whereRaw(
                'metadata->>? IN ('.implode(',', array_fill(0, count($valores), '?')).')',
                array_merge([$campo], $valores)
            );
        }
    }

    /**
     * Get processing progress for a flujo.
     * Used by frontend for polling during background prospect assignment.
     */
    public function progreso(Flujo $flujo): JsonResponse
    {
        $progreso = $flujo->metadata['progreso'] ?? null;
        $estadoBD = $flujo->estado_procesamiento ?? 'pendiente';

        // Si no hay progreso y estado es pendiente/completado, generar respuesta simple
        if ($progreso === null) {
            $totalProspectos = $flujo->prospectosEnFlujo()->count();

            return response()->json([
                'data' => [
                    'flujo_id' => $flujo->id,
                    'estado' => $estadoBD,
                    'en_proceso' => false,
                    'completado' => $estadoBD === 'completado',
                    'progreso' => [
                        'procesados' => $totalProspectos,
                        'total' => $totalProspectos,
                        'porcentaje' => 100,
                    ],
                ],
            ]);
        }

        // INTELIGENTE: Determinar estado real basado en actividad, no solo el campo de BD
        // Esto maneja el caso donde el job se reintenta pero el estado quedó "fallido"
        $porcentaje = $progreso['porcentaje'] ?? 0;
        $ultimaActualizacion = isset($progreso['ultima_actualizacion'])
            ? \Carbon\Carbon::parse($progreso['ultima_actualizacion'])
            : null;
        $tieneActividadReciente = $ultimaActualizacion && $ultimaActualizacion->diffInMinutes(now()) < 5;

        // Si porcentaje < 100 y hay actividad en los últimos 5 min, está procesando
        // aunque la BD diga "fallido" (puede ser un reintento en curso)
        if ($porcentaje < 100 && $tieneActividadReciente && $estadoBD === 'fallido') {
            $estado = 'procesando';
        } else {
            $estado = $estadoBD;
        }

        $enProceso = $estado === 'procesando';
        $completado = $estado === 'completado' || ($progreso['completado'] ?? false);

        return response()->json([
            'data' => [
                'flujo_id' => $flujo->id,
                'estado' => $estado,
                'en_proceso' => $enProceso,
                'completado' => $completado,
                'fallido' => $estado === 'fallido',
                'progreso' => [
                    'procesados' => $progreso['procesados'] ?? 0,
                    'total' => $progreso['total'] ?? 0,
                    'porcentaje' => $progreso['porcentaje'] ?? 0,
                    'chunk_actual' => $progreso['chunk_actual'] ?? 0,
                    'total_chunks' => $progreso['total_chunks'] ?? 0,
                    'velocidad_por_segundo' => $progreso['velocidad_por_segundo'] ?? 0,
                    'segundos_transcurridos' => $progreso['segundos_transcurridos'] ?? 0,
                    'segundos_restantes_estimados' => $progreso['segundos_restantes_estimados'] ?? null,
                    'inicio' => $progreso['inicio'] ?? null,
                    'fin' => $progreso['fin'] ?? null,
                    'ultima_actualizacion' => $progreso['ultima_actualizacion'] ?? null,
                ],
                'mensaje' => $this->generarMensajeProgreso($estado, $progreso),
            ],
        ]);
    }

    /**
     * Generate a human-readable progress message.
     */
    private function generarMensajeProgreso(string $estado, ?array $progreso): string
    {
        if ($estado === 'fallido') {
            return 'El procesamiento falló. Intenta de nuevo.';
        }

        if ($estado === 'completado' || ($progreso['completado'] ?? false)) {
            $duracion = $progreso['duracion_segundos'] ?? 0;

            return "Procesamiento completado en {$duracion} segundos.";
        }

        if ($estado === 'procesando' && $progreso) {
            $porcentaje = $progreso['porcentaje'] ?? 0;
            $procesados = number_format($progreso['procesados'] ?? 0);
            $total = number_format($progreso['total'] ?? 0);
            $restante = $progreso['segundos_restantes_estimados'] ?? null;

            $mensaje = "Procesando: {$procesados} de {$total} ({$porcentaje}%)";

            if ($restante !== null) {
                $mensaje .= " - ~{$restante}s restantes";
            }

            return $mensaje;
        }

        return 'Esperando inicio del procesamiento...';
    }

    /**
     * Get available filter options for creating flujos.
     */
    public function opcionesCreacion(): JsonResponse
    {
        // Obtener tipos de prospecto
        $tiposProspecto = \App\Models\TipoProspecto::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->get(['id', 'nombre', 'descripcion']);

        // Obtener orígenes disponibles con formato consistente
        $origenes = \App\Models\Importacion::query()
            ->select('origen')
            ->distinct()
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->origen,
                    'nombre' => $item->origen,
                    'value' => $item->origen,
                    'label' => $item->origen,
                ];
            })
            ->values();

        // Canales de envío
        $canalesEnvio = [
            ['value' => 'email', 'label' => 'Email'],
            ['value' => 'sms', 'label' => 'SMS'],
            ['value' => 'ambos', 'label' => 'Email y SMS'],
        ];

        return response()->json([
            'data' => [
                'tipos_prospecto' => $tiposProspecto,
                'origenes' => $origenes,
                'canales_envio' => $canalesEnvio,
            ],
        ]);
    }

    public function opcionesFiltrado(): JsonResponse
    {
        // Obtener display_names de fuentes DEPRECATED para filtrarlas
        // Solo filtramos las que tienen [DEPRECATED] en el nombre, NO todas las inactivas
        // (los sources de test son is_active=false pero deben aparecer)
        $deprecatedOrigins = DB::table('external_api_sources')
            ->where('display_name', 'like', '%[DEPRECATED]%')
            ->pluck('display_name')
            ->map(fn ($name) => str_replace('[DEPRECATED] ', '', $name))
            ->toArray();

        // Orígenes que tienen prospectos importados (para crear flujos nuevos)
        $origenesConProspectos = DB::table('importaciones')
            ->select('importaciones.origen')
            ->selectRaw('COUNT(DISTINCT prospecto_en_flujo.flujo_id) as total_flujos')
            ->join('prospectos', 'prospectos.importacion_id', '=', 'importaciones.id')
            ->leftJoin('prospecto_en_flujo', 'prospecto_en_flujo.prospecto_id', '=', 'prospectos.id')
            ->when(count($deprecatedOrigins) > 0, function ($query) use ($deprecatedOrigins) {
                $query->whereNotIn('importaciones.origen', $deprecatedOrigins);
            })
            ->groupBy('importaciones.origen')
            ->get()
            ->keyBy('origen');

        // Orígenes que tienen flujos existentes (para filtrar flujos aunque no tengan prospectos)
        $origenesConFlujos = DB::table('flujos')
            ->select('origen')
            ->selectRaw('COUNT(*) as total_flujos')
            ->whereNotNull('origen')
            ->where('origen', '!=', '')
            ->when(count($deprecatedOrigins) > 0, function ($query) use ($deprecatedOrigins) {
                $query->whereNotIn('origen', $deprecatedOrigins);
            })
            ->groupBy('origen')
            ->get()
            ->keyBy('origen');

        // Combinar ambos: orígenes con prospectos O con flujos
        $todosOrigenes = collect();

        // Agregar orígenes con prospectos
        foreach ($origenesConProspectos as $origen => $data) {
            $flujosDesdeProspectos = (int) $data->total_flujos;
            $flujosDirectos = isset($origenesConFlujos[$origen]) ? (int) $origenesConFlujos[$origen]->total_flujos : 0;
            // Usar el máximo entre ambos conteos (evitar duplicados)
            $todosOrigenes[$origen] = max($flujosDesdeProspectos, $flujosDirectos);
        }

        // Agregar orígenes con flujos que no tienen prospectos
        foreach ($origenesConFlujos as $origen => $data) {
            if (! isset($todosOrigenes[$origen])) {
                $todosOrigenes[$origen] = (int) $data->total_flujos;
            }
        }

        $origenes = $todosOrigenes
            ->map(fn ($totalFlujos, $origen) => [
                'id' => $origen,
                'nombre' => $origen,
                'total_flujos' => $totalFlujos,
            ])
            ->sortBy('nombre')
            ->values();

        // Obtener tipos de deudor (tipos de prospecto)
        $tiposDeudor = \App\Models\TipoProspecto::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->get()
            ->map(function ($tipo) {
                return [
                    'value' => $tipo->nombre,
                    'label' => $tipo->nombre,
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'origenes' => $origenes,
                'tipos_deudor' => $tiposDeudor,
            ],
        ]);
    }

    /**
     * Get lotes (batches) for a specific origen with prospect counts.
     *
     * GET /api/flujos/opciones-lotes?origen=Informes%20Comerciales
     *
     * Returns lotes grouped by origen, with:
     * - id, nombre, clasificacion_value
     * - total_prospectos (count of prospects in the lote)
     * - estado (completado, abierto, etc.)
     * - created_at
     */
    public function opcionesLotes(Request $request): JsonResponse
    {
        $request->validate([
            'origen' => 'required|string',
        ]);

        $origen = $request->input('origen');

        // Get lotes that have importaciones with the specified origen
        // Count REAL prospectos (not registros_exitosos which is a snapshot)
        $lotes = Lote::query()
            ->select('lotes.id', 'lotes.nombre', 'lotes.clasificacion_value', 'lotes.estado', 'lotes.created_at')
            ->selectRaw('(
                SELECT COUNT(*)
                FROM prospectos p
                INNER JOIN importaciones i ON p.importacion_id = i.id
                WHERE i.lote_id = lotes.id
                AND i.origen = ?
            ) as total_prospectos', [$origen])
            ->whereExists(function ($query) use ($origen) {
                $query->select(DB::raw(1))
                    ->from('importaciones')
                    ->whereColumn('importaciones.lote_id', 'lotes.id')
                    ->where('importaciones.origen', $origen)
                    ->where('importaciones.estado', 'completado');
            })
            ->orderBy('lotes.created_at', 'desc')
            ->get()
            ->map(fn ($lote) => [
                'id' => $lote->id,
                'nombre' => $lote->nombre,
                'clasificacion_value' => $lote->clasificacion_value,
                'total_prospectos' => (int) $lote->total_prospectos,
                'estado' => $lote->estado,
                'created_at' => $lote->created_at->toISOString(),
            ]);

        // Calculate total prospects across all lotes
        $totalProspectos = $lotes->sum('total_prospectos');

        return response()->json([
            'data' => [
                'origen' => $origen,
                'lotes' => $lotes,
                'total_lotes' => $lotes->count(),
                'total_prospectos' => $totalProspectos,
            ],
        ]);
    }

    /**
     * Get aggregated send statistics per node for a flujo.
     *
     * Returns stats grouped by node_id across ALL executions:
     * - For email: enviado, entregado, abierto, clickeado, fallido
     * - For SMS: enviado, fallido
     *
     * GET /api/flujos/{flujo}/estadisticas-nodos
     */
    public function estadisticasNodos(Flujo $flujo): JsonResponse
    {
        return response()->json([
            'error' => false,
            'data' => $this->flujoStatsService->getNodeStats($flujo),
        ]);
    }

    /**
     * Get comprehensive statistics for a flujo.
     *
     * Returns:
     * - Funnel metrics (prospectos → enviados → abiertos → clicks)
     * - Rates (apertura, click, fallo)
     * - Cost metrics
     * - Per-stage breakdown
     *
     * GET /api/flujos/{flujo}/estadisticas-completas
     */
    public function estadisticasCompletas(Flujo $flujo): JsonResponse
    {
        return response()->json([
            'error' => false,
            'data' => $this->flujoStatsService->getFullStats($flujo),
        ]);
    }

    public function estadisticasCostos(): JsonResponse
    {
        return response()->json([
            'data' => $this->flujoStatsService->getCostStats(),
        ]);
    }

    /**
     * Crear flujo con prospectos y distribución de canales (email/sms).
     * Soporta tanto el formato antiguo como el nuevo FlowBuilder.
     */
    public function crearFlujoConProspectos(\App\Http\Requests\CrearFlujoConProspectosRequest $request): JsonResponse
    {
        // Resolve tipo_prospecto: required only when prospects are being assigned
        $tipoProspectoInput = $request->input('flujo.tipo_prospecto');
        $hasProspects = ! empty($request->input('prospectos.ids_seleccionados'))
            || $request->boolean('prospectos.select_all_from_origin', false);

        $tipoProspecto = $tipoProspectoInput ? $this->findTipoProspecto($tipoProspectoInput) : null;

        if ($hasProspects && $tipoProspecto === null) {
            return $this->tipoProspectoNotFoundResponse($tipoProspectoInput);
        }

        // For flows without prospects, use first available tipo_prospecto as default
        if ($tipoProspecto === null) {
            $tipoProspecto = TipoProspecto::first();
            if ($tipoProspecto === null) {
                return response()->json([
                    'message' => 'No hay tipos de prospecto configurados en el sistema.',
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            $structure = $request->input('structure', []);
            $canalEnvioInferido = $this->inferirCanalEnvioDesdeEstructura($structure);

            $flujo = $this->crearFlujoBase($request, $tipoProspecto, $canalEnvioInferido);

            $this->guardarEstructuraSiExiste($flujo, $structure);

            $conteoProspectos = $this->asignarProspectosAlFlujo(
                $flujo,
                $request->input('prospectos.ids_seleccionados', []),
                $canalEnvioInferido,
                $request->boolean('prospectos.select_all_from_origin', false),
                $request->input('lote_ids', []),
                $request->input('metadata_filters', [])
            );

            $costos = $this->calcularYGuardarCostos($flujo, $conteoProspectos, $request);

            DB::commit();

            return $this->flujoCreatedResponse($flujo, $conteoProspectos, $costos);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->errorResponse('Error al crear el flujo', $e->getMessage());
        }
    }

    /**
     * Infiere el canal de envío basándose en las etapas de la estructura.
     */
    private function inferirCanalEnvioDesdeEstructura(array $structure): CanalEnvio
    {
        if (empty($structure)) {
            return CanalEnvio::EMAIL;
        }

        return $this->canalEnvioResolver->resolveFromStructure($structure);
    }

    /**
     * Busca el tipo de prospecto por ID, nombre o slug.
     */
    private function findTipoProspecto(mixed $input): ?TipoProspecto
    {
        if ($input === null) {
            return null;
        }

        // Si es numérico, buscar directamente por ID
        if (is_numeric($input)) {
            return TipoProspecto::find($input);
        }

        // Buscar por nombre exacto o similar
        return TipoProspecto::where('id', $input)
            ->orWhere('nombre', $input)
            ->orWhere('nombre', 'LIKE', '%'.str_replace('-', ' ', $input).'%')
            ->first();
    }

    /**
     * Crea el flujo base con los datos del request.
     */
    private function crearFlujoBase(
        \App\Http\Requests\CrearFlujoConProspectosRequest $request,
        TipoProspecto $tipoProspecto,
        CanalEnvio $canalEnvio
    ): Flujo {
        // Normalize empty array to null for nivel_deuda_target
        $nivelDeudaTarget = $request->input('nivel_deuda_target');
        if (is_array($nivelDeudaTarget) && empty($nivelDeudaTarget)) {
            $nivelDeudaTarget = null;
        }

        // Normalize lotes_ids: ensure it's an array or null
        $lotesIds = $request->input('lote_ids');
        if (is_array($lotesIds) && empty($lotesIds)) {
            $lotesIds = null;
        }

        // Fallback for origen_id: try origen_id first, then look up by origen_nombre
        $origenId = $request->input('origen_id');
        if (empty($origenId) && $request->input('origen_nombre')) {
            // Try to find lote by origen name to get its ID
            $lote = \App\Models\Lote::where('nombre', $request->input('origen_nombre'))->first();
            $origenId = $lote?->id;
        }

        // Validate initial_node in structure
        $structure = $request->input('structure');
        if (! empty($structure) && empty($structure['initial_node'])) {
            // Try to find initial node from visual config
            $visual = $request->input('visual');
            if (! empty($visual['nodes'])) {
                $initialNode = collect($visual['nodes'])->first(function ($node) {
                    return ($node['type'] ?? '') === 'initial' || str_starts_with($node['id'] ?? '', 'initial');
                });
                if ($initialNode) {
                    $structure['initial_node'] = $initialNode['id'];
                }
            }
        }

        return Flujo::create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'origen_id' => $origenId,
            'origen' => $request->input('origen_nombre'),
            'nombre' => $request->input('flujo.nombre'),
            'descripcion' => $request->input('flujo.descripcion'),
            'canal_envio' => $canalEnvio->value,
            'activo' => $request->input('flujo.activo', true),
            'user_id' => $request->user()->id,
            'config_visual' => $request->input('visual'),
            'config_structure' => $structure,
            'nivel_deuda_target' => $nivelDeudaTarget,
            'lotes_ids' => $lotesIds,
        ]);
    }

    /**
     * Guarda la estructura del FlowBuilder si está presente.
     */
    private function guardarEstructuraSiExiste(Flujo $flujo, array $structure): void
    {
        if (empty($structure)) {
            return;
        }

        $this->guardarEstructuraFlowBuilder($flujo, $structure);
    }

    /**
     * Asigna los prospectos al flujo con el canal correspondiente.
     *
     * Para grandes volúmenes o cuando se selecciona "todos del origen",
     * despacha un Job en background usando criterios de query en lugar de IDs.
     * Esto permite procesar millones de prospectos sin problemas de memoria.
     *
     * @param  array  $loteIds  IDs de lotes específicos (vacío = todos)
     * @param  array  $metadataFilters  Filtros de metadata (ej: ['nivel_deuda' => ['alta']])
     * @return array{total: int, email: int, sms: int, is_async: bool}
     */
    private function asignarProspectosAlFlujo(
        Flujo $flujo,
        array $prospectoIds,
        CanalEnvio $canalEnvio,
        bool $selectAllFromOrigin = false,
        array $loteIds = [],
        array $metadataFilters = []
    ): array {
        $conteo = ['total' => 0, 'email' => 0, 'sms' => 0, 'is_async' => false];
        $canalAsignado = $this->determinarCanalParaProspectos($canalEnvio);

        // CASO 1: Seleccionar todos del origen → Usar Job con criterios (NO cargar IDs)
        if ($selectAllFromOrigin) {
            return $this->asignarProspectosAsync($flujo, $canalAsignado, $loteIds, $metadataFilters);
        }

        // CASO 2: IDs específicos seleccionados manualmente
        if (empty($prospectoIds)) {
            return $conteo;
        }

        $totalProspectos = count($prospectoIds);

        // Si son más de 100, procesar async
        if ($totalProspectos > 100) {
            return $this->asignarProspectosAsyncConIds($flujo, $prospectoIds, $canalAsignado);
        }

        // Procesamiento síncrono para cantidades pequeñas (<= 100)
        return $this->asignarProspectosSync($flujo, $prospectoIds, $canalAsignado);
    }

    /**
     * Asigna prospectos de forma asíncrona usando criterios (sin cargar IDs en memoria).
     * Ideal para "seleccionar todos del origen" con cientos de miles de prospectos.
     *
     * @param  array  $loteIds  IDs de lotes específicos (vacío = todos)
     * @param  array  $metadataFilters  Filtros de metadata (ej: ['nivel_deuda' => ['alta']])
     */
    private function asignarProspectosAsync(
        Flujo $flujo,
        string $canalAsignado,
        array $loteIds = [],
        array $metadataFilters = []
    ): array {
        // Crear criterios de selección CON filtros (el Job construirá la query)
        $criterios = \App\DTOs\CriteriosSeleccionProspectos::fromFlujoWithFilters(
            $flujo,
            $loteIds,
            $metadataFilters
        );

        // Obtener conteo estimado SIN cargar IDs en memoria
        $totalEstimado = $this->contarProspectosPorCriterios($criterios);

        if ($totalEstimado === 0) {
            return ['total' => 0, 'email' => 0, 'sms' => 0, 'is_async' => false];
        }

        // Despachar Job con criterios (payload liviano)
        \App\Jobs\AsignarProspectosAFlujoJob::dispatch($flujo, $criterios, $canalAsignado)
            ->afterCommit();

        $flujo->update(['estado_procesamiento' => 'procesando']);

        return [
            'total' => $totalEstimado,
            'email' => $canalAsignado === 'email' ? $totalEstimado : 0,
            'sms' => $canalAsignado === 'sms' ? $totalEstimado : 0,
            'is_async' => true,
        ];
    }

    /**
     * Asigna prospectos de forma asíncrona con IDs específicos.
     * Para selecciones manuales grandes (100-10000 prospectos).
     */
    private function asignarProspectosAsyncConIds(Flujo $flujo, array $prospectoIds, string $canalAsignado): array
    {
        $criterios = \App\DTOs\CriteriosSeleccionProspectos::fromProspectoIds($prospectoIds);
        $totalProspectos = count($prospectoIds);

        \App\Jobs\AsignarProspectosAFlujoJob::dispatch($flujo, $criterios, $canalAsignado)
            ->afterCommit();

        $flujo->update(['estado_procesamiento' => 'procesando']);

        return [
            'total' => $totalProspectos,
            'email' => $canalAsignado === 'email' ? $totalProspectos : 0,
            'sms' => $canalAsignado === 'sms' ? $totalProspectos : 0,
            'is_async' => true,
        ];
    }

    /**
     * Asigna prospectos de forma síncrona (para cantidades pequeñas).
     * Optimizado: usa bulk insert en lugar de N queries individuales.
     */
    private function asignarProspectosSync(Flujo $flujo, array $prospectoIds, string $canalAsignado): array
    {
        $totalProspectos = count($prospectoIds);
        $now = now();

        // crearBatch centraliza insert + fecha_ingreso por origen + normalización canal
        ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, $canalAsignado, $now);

        $flujo->update(['estado_procesamiento' => 'completado']);

        return [
            'total' => $totalProspectos,
            'email' => $canalAsignado === 'email' ? $totalProspectos : 0,
            'sms' => $canalAsignado === 'sms' ? $totalProspectos : 0,
            'is_async' => false,
        ];
    }

    /**
     * Cuenta prospectos según criterios SIN cargar IDs.
     * Soporta filtros de lotes y metadata.
     */
    private function contarProspectosPorCriterios(\App\DTOs\CriteriosSeleccionProspectos $criterios): int
    {
        $query = Prospecto::query();

        // Filtrar por lotes específicos o por origen
        if ($criterios->usarFiltroLotes()) {
            $query->whereHas('importacion', function ($q) use ($criterios) {
                $q->whereIn('lote_id', $criterios->loteIds);
            });
        } elseif (! empty($criterios->origen)) {
            $query->whereHas('importacion', function ($q) use ($criterios) {
                $q->where('origen', $criterios->origen);
            });
        }

        // Filtrar por tipo de prospecto si no es "Todos"
        if ($criterios->tipoProspectoId !== null) {
            $query->where('tipo_prospecto_id', $criterios->tipoProspectoId);
        }

        // Aplicar filtros de metadata (ej: nivel_deuda)
        if ($criterios->usarFiltroMetadata()) {
            foreach ($criterios->metadataFilters as $campo => $valor) {
                $campoSanitizado = preg_replace('/[^a-zA-Z0-9_]/', '', $campo);
                $expresion = "metadata->>'{$campoSanitizado}'";

                if (is_array($valor)) {
                    $placeholders = implode(',', array_fill(0, count($valor), '?'));
                    $query->whereRaw("{$expresion} IN ({$placeholders})", $valor);
                } else {
                    $query->whereRaw("{$expresion} = ?", [$valor]);
                }
            }
        }

        return $query->count();
    }

    /**
     * Determina el canal a asignar a los prospectos.
     * Si es 'ambos', default a 'email' ya que el canal real se define en cada etapa.
     */
    private function determinarCanalParaProspectos(CanalEnvio $canalEnvio): string
    {
        return match ($canalEnvio) {
            CanalEnvio::EMAIL => 'email',
            CanalEnvio::SMS => 'sms',
            CanalEnvio::AMBOS => 'email', // Default para flujos mixtos
        };
    }

    /**
     * Calcula los costos y guarda la metadata del flujo.
     *
     * @return array{email_unitario: float, sms_unitario: float, total_email: float, total_sms: float, total: float}
     */
    private function calcularYGuardarCostos(
        Flujo $flujo,
        array $conteoProspectos,
        \App\Http\Requests\CrearFlujoConProspectosRequest $request
    ): array {
        $configuracion = Configuracion::get();

        $costoTotalEmail = $conteoProspectos['email'] * $configuracion->email_costo;
        $costoTotalSms = $conteoProspectos['sms'] * $configuracion->sms_costo;
        $costoTotal = $costoTotalEmail + $costoTotalSms;

        $costos = [
            'email_unitario' => (float) $configuracion->email_costo,
            'sms_unitario' => (float) $configuracion->sms_costo,
            'total_email' => (float) $costoTotalEmail,
            'total_sms' => (float) $costoTotalSms,
            'total' => (float) $costoTotal,
        ];

        $flujo->update([
            'metadata' => $this->buildMetadata($request, $conteoProspectos, $costos),
        ]);

        return $costos;
    }

    /**
     * Construye el array de metadata para el flujo.
     */
    private function buildMetadata(
        \App\Http\Requests\CrearFlujoConProspectosRequest $request,
        array $conteoProspectos,
        array $costos
    ): array {
        return [
            'distribucion' => $request->input('distribucion'),
            'metadata_creacion' => $request->input('metadata'),
            'resumen' => [
                'total_prospectos' => $conteoProspectos['total'],
                'prospectos_email' => $conteoProspectos['email'],
                'prospectos_sms' => $conteoProspectos['sms'],
            ],
            'costos_vigentes' => [
                'email_costo_unitario' => $costos['email_unitario'],
                'sms_costo_unitario' => $costos['sms_unitario'],
                'cantidad_emails' => $conteoProspectos['email'],
                'cantidad_sms' => $conteoProspectos['sms'],
                'costo_total_email' => $costos['total_email'],
                'costo_total_sms' => $costos['total_sms'],
                'costo_total' => $costos['total'],
                'fecha_calculo' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Response para tipo de prospecto no encontrado.
     */
    private function tipoProspectoNotFoundResponse(mixed $tipoProspectoInput): JsonResponse
    {
        return response()->json([
            'mensaje' => 'Tipo de prospecto no encontrado',
            'error' => "No se encontró el tipo de prospecto: '{$tipoProspectoInput}'",
            'tipos_disponibles' => TipoProspecto::pluck('nombre', 'id'),
        ], 422);
    }

    /**
     * Response exitoso para flujo creado.
     */
    private function flujoCreatedResponse(Flujo $flujo, array $conteoProspectos, array $costos): JsonResponse
    {
        $flujo->load(['tipoProspecto', 'user']);

        $mensaje = $conteoProspectos['is_async'] ?? false
            ? 'Flujo creado exitosamente. Los prospectos se están asignando en segundo plano.'
            : 'Flujo creado exitosamente';

        return response()->json([
            'mensaje' => $mensaje,
            'data' => $flujo,
            'resumen' => [
                'total_prospectos' => $conteoProspectos['total'],
                'prospectos_email' => $conteoProspectos['email'],
                'prospectos_sms' => $conteoProspectos['sms'],
                'procesamiento_async' => $conteoProspectos['is_async'] ?? false,
            ],
            'costos' => [
                'email_costo_unitario' => $costos['email_unitario'],
                'sms_costo_unitario' => $costos['sms_unitario'],
                'costo_total_email' => $costos['total_email'],
                'costo_total_sms' => $costos['total_sms'],
                'costo_total' => $costos['total'],
            ],
        ], 201);
    }

    /**
     * Response de error genérico.
     */
    private function errorResponse(string $mensaje, string $error): JsonResponse
    {
        return response()->json([
            'mensaje' => $mensaje,
            'error' => $error,
        ], 500);
    }

    /**
     * Distribuir prospectos entre email y SMS según porcentajes.
     */
    private function distribuirProspectosPorCanal(
        array $prospectoIds,
        string $tipoMensaje,
        int $emailPercentage,
        int $smsPercentage
    ): array {
        // Mezclar aleatoriamente para distribución equitativa
        shuffle($prospectoIds);

        if ($tipoMensaje === 'email') {
            return [
                'email' => $prospectoIds,
                'sms' => [],
            ];
        }

        if ($tipoMensaje === 'sms') {
            return [
                'email' => [],
                'sms' => $prospectoIds,
            ];
        }

        // Tipo 'ambos' - distribuir según porcentajes
        $totalProspectos = count($prospectoIds);
        $cantidadEmail = (int) round(($emailPercentage / 100) * $totalProspectos);
        $cantidadSms = $totalProspectos - $cantidadEmail;

        return [
            'email' => array_slice($prospectoIds, 0, $cantidadEmail),
            'sms' => array_slice($prospectoIds, $cantidadEmail),
        ];
    }

    /**
     * Guardar la estructura del FlowBuilder en la base de datos.
     */
    /**
     * Guarda la estructura del FlowBuilder usando bulk inserts.
     * Optimizado: 4 queries en lugar de N queries por elemento.
     */
    protected function guardarEstructuraFlowBuilder(Flujo $flujo, array $structure): void
    {
        $now = now();

        // 1. Bulk insert etapas (stages)
        if (! empty($structure['stages'])) {
            $etapasData = array_map(fn ($stage) => [
                'id' => $stage['id'],
                'flujo_id' => $flujo->id,
                'orden' => $stage['orden'] ?? 0,
                'label' => $stage['label'] ?? '',
                'dia_envio' => $stage['dia_envio'] ?? 0,
                'tipo_mensaje' => $stage['tipo_mensaje'] ?? 'email',
                'plantilla_mensaje' => $stage['plantilla_mensaje'] ?? '',
                'plantilla_id' => $stage['plantilla_id'] ?? null,
                'plantilla_id_email' => $stage['plantilla_id_email'] ?? null,
                'plantilla_type' => $stage['plantilla_type'] ?? 'inline',
                'fecha_inicio_personalizada' => $stage['fecha_inicio_personalizada'] ?? null,
                'activo' => $stage['activo'] ?? true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $structure['stages']);

            \App\Models\FlujoEtapa::insert($etapasData);
        }

        // 2. Bulk insert condiciones (conditions)
        if (! empty($structure['conditions'])) {
            $condicionesData = array_map(function ($condition) use ($flujo, $now) {
                $conditionType = $condition['condition_type'] ?? 'email_opened';
                $defaultCheckParam = $this->getDefaultCheckParamForConditionType($conditionType);

                return [
                    'id' => $condition['id'],
                    'flujo_id' => $flujo->id,
                    'label' => $condition['label'] ?? '',
                    'description' => $condition['description'] ?? null,
                    'condition_type' => $conditionType,
                    'condition_label' => $condition['condition_label'] ?? '',
                    'yes_label' => $condition['yes_label'] ?? 'Sí',
                    'no_label' => $condition['no_label'] ?? 'No',
                    'check_param' => $condition['check_param'] ?? $defaultCheckParam,
                    'check_operator' => $condition['check_operator'] ?? '>',
                    'check_value' => $condition['check_value'] ?? '0',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $structure['conditions']);

            FlujoCondicion::insert($condicionesData);
        }

        // 3. Bulk insert ramificaciones (branches)
        if (! empty($structure['branches'])) {
            $branchesData = array_map(fn ($branch) => [
                'flujo_id' => $flujo->id,
                'edge_id' => $branch['edge_id'] ?? '',
                'source_node_id' => $branch['source_node_id'] ?? '',
                'target_node_id' => $branch['target_node_id'] ?? '',
                'source_handle' => $branch['source_handle'] ?? null,
                'target_handle' => $branch['target_handle'] ?? null,
                'condition_branch' => $branch['condition_branch'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $structure['branches']);

            \App\Models\FlujoRamificacion::insert($branchesData);
        }

        // 4. Bulk insert nodos finales (end_nodes)
        if (! empty($structure['end_nodes'])) {
            $endNodesData = [];

            foreach ($structure['end_nodes'] as $endNode) {
                // Soportar end_node como string (ID) o como objeto
                if (is_string($endNode)) {
                    $endNodeId = $endNode;
                    $label = 'Fin';
                    $description = null;
                } else {
                    $endNodeId = $endNode['id'] ?? null;
                    $label = $endNode['data']['label'] ?? 'Fin';
                    $description = $endNode['data']['description'] ?? null;
                }

                if ($endNodeId) {
                    $endNodesData[] = [
                        'node_id' => $endNodeId,
                        'flujo_id' => $flujo->id,
                        'label' => $label,
                        'description' => $description,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (! empty($endNodesData)) {
                \App\Models\FlujoNodoFinal::insert($endNodesData);
            }
        }
    }

    /**
     * Obtiene el check_param por defecto según el tipo de condición.
     * Usado cuando el frontend no envía el campo explícitamente.
     */
    private function getDefaultCheckParamForConditionType(string $conditionType): string
    {
        return match ($conditionType) {
            'email_opened' => 'Views',
            'link_clicked' => 'Clicks',
            'email_bounced' => 'Bounces',
            'unsubscribed' => 'Unsubscribes',
            default => 'Views',
        };
    }

    /**
     * Obtiene el resumen de cohortes activas para un flujo.
     *
     * Retorna información sobre todas las ejecuciones activas (cohortes) del flujo,
     * con estadísticas agregadas por nodo para mostrar en la UI.
     */
    public function cohortesActivas(Flujo $flujo): JsonResponse
    {
        // Obtener ejecuciones activas SIN cargar prospectos_ids (evita memory exhaustion)
        // Usamos prospectos_count en lugar de contar el JSON en memoria
        // - Para flujos NO perpetuos: in_progress, paused, waiting (excluye completed/failed/cancelled)
        // - Para flujos PERPETUOS: incluir también completed, porque la ejecución sigue viva
        //   esperando nuevos prospectos. Si alguna vez quedó marcada completed por bug previo,
        //   sigue siendo la "cohorte activa" del flujo.
        $estadosVisibles = $flujo->es_perpetuo
            ? ['in_progress', 'paused', 'waiting', 'completed']
            : ['in_progress', 'paused', 'waiting'];

        $ejecucionesActivas = $flujo->ejecuciones()
            ->whereIn('estado', $estadosVisibles)
            ->select([
                'id',
                'flujo_id',
                'estado',
                'es_perpetuo', // Required for progress calculation logic
                'prospectos_count',
                'nodo_actual',
                'proximo_nodo',
                'fecha_proximo_nodo',
                'config',
                'created_at',
            ])
            ->with(['etapas' => function ($query) {
                // NO cargar prospectos_ids de etapas - usar prospectos_count
                $query->select([
                    'id',
                    'flujo_ejecucion_id',
                    'node_id',
                    'estado',
                    'fecha_programada',
                    'fecha_ejecucion',
                    'prospectos_count',
                    'primer_envio_at', // Required for perpetual flow progress calculation
                ]);
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        if ($ejecucionesActivas->isEmpty()) {
            return response()->json([
                'error' => false,
                'data' => [
                    'total_cohortes' => 0,
                    'cohortes' => [],
                    'resumen_por_nodo' => [],
                ],
            ]);
        }

        // Count total stages from the FLUJO's config_structure (not from execution etapas)
        // This is the actual number of stages defined in the flow builder
        $etapasTotalFlujo = count($flujo->config_structure['stages'] ?? []);

        // Construir resumen de cada cohorte (usando prospectos_count, no el JSON)
        $cohortes = $ejecucionesActivas->map(function ($ejecucion) use ($etapasTotalFlujo) {
            $prospectosCount = $ejecucion->prospectos_count ?? 0;

            // Calcular progreso basado en etapas completadas vs total del flujo
            // Use max() to handle cases where flow was modified after execution started
            // Para flujos perpetuos, contar etapas que han procesado envíos (primer_envio_at)
            // Para flujos normales, contar etapas con estado 'completed'
            if ($ejecucion->es_perpetuo) {
                $etapasCompletadas = $ejecucion->etapas->whereNotNull('primer_envio_at')->count();
            } else {
                $etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
            }
            $etapasEjecutando = $ejecucion->etapas->where('estado', 'executing')->count();
            $etapasEnEjecucion = $ejecucion->etapas->count();
            $etapasTotal = max($etapasTotalFlujo, $etapasEnEjecucion);
            $progreso = $etapasTotal > 0 ? min(100, round(($etapasCompletadas / $etapasTotal) * 100)) : 0;

            // Determinar estado legible
            $estadoLegible = match ($ejecucion->estado) {
                'in_progress' => $etapasEjecutando > 0 ? 'Procesando' : 'En progreso',
                'paused' => 'Pausado',
                'waiting' => 'Esperando nuevos',
                default => $ejecucion->estado,
            };

            return [
                'id' => $ejecucion->id,
                'created_at' => $ejecucion->created_at->toISOString(),
                'estado' => $ejecucion->estado,
                'estado_legible' => $estadoLegible,
                'prospectos_count' => $prospectosCount,
                'progreso' => $progreso,
                'etapas_completadas' => $etapasCompletadas,
                'etapas_total' => $etapasTotal,
                'nodo_actual' => $ejecucion->nodo_actual,
                'proximo_nodo' => $ejecucion->proximo_nodo,
                'fecha_proximo_nodo' => $ejecucion->fecha_proximo_nodo?->toISOString(),
                'origen' => $ejecucion->config['created_from'] ?? 'manual',
            ];
        });

        // Construir resumen por nodo (para mostrar en cada StageNode)
        $resumenPorNodo = [];

        foreach ($ejecucionesActivas as $ejecucion) {
            foreach ($ejecucion->etapas as $etapa) {
                $nodeId = $etapa->node_id;

                if (! isset($resumenPorNodo[$nodeId])) {
                    $resumenPorNodo[$nodeId] = [
                        'node_id' => $nodeId,
                        'total_cohortes' => 0,
                        'cohortes_completadas' => 0,
                        'cohortes_procesando' => 0,
                        'cohortes_pendientes' => 0,
                        'total_prospectos' => 0,
                        'prospectos_procesados' => 0,
                        'prospectos_pendientes' => 0,
                        'detalle_cohortes' => [],
                    ];
                }

                $resumenPorNodo[$nodeId]['total_cohortes']++;

                // Usar prospectos_count (columna) en lugar de contar el JSON en memoria
                $prospectosEtapa = $etapa->prospectos_count ?? 0;

                // Si la etapa no tiene count, usar el de la ejecución (para primera etapa)
                if ($prospectosEtapa === 0) {
                    $prospectosEtapa = $ejecucion->prospectos_count ?? 0;
                }

                $resumenPorNodo[$nodeId]['total_prospectos'] += $prospectosEtapa;

                // Clasificar por estado
                switch ($etapa->estado) {
                    case 'completed':
                        $resumenPorNodo[$nodeId]['cohortes_completadas']++;
                        $resumenPorNodo[$nodeId]['prospectos_procesados'] += $prospectosEtapa;
                        break;
                    case 'executing':
                        $resumenPorNodo[$nodeId]['cohortes_procesando']++;
                        break;
                    case 'pending':
                    case 'paused':
                        $resumenPorNodo[$nodeId]['cohortes_pendientes']++;
                        $resumenPorNodo[$nodeId]['prospectos_pendientes'] += $prospectosEtapa;
                        break;
                }

                // Agregar detalle de esta cohorte
                $resumenPorNodo[$nodeId]['detalle_cohortes'][] = [
                    'ejecucion_id' => $ejecucion->id,
                    'estado' => $etapa->estado,
                    'prospectos' => $prospectosEtapa,
                    'fecha_programada' => $etapa->fecha_programada?->toISOString(),
                    'created_at' => $ejecucion->created_at->toISOString(),
                ];
            }
        }

        // Construir lista de últimos ingresos (para mostrar en panel lateral)
        // Muestra las cohortes más recientes con su fecha y cantidad de prospectos
        $ultimosIngresos = $ejecucionesActivas
            ->sortByDesc('created_at')
            ->take(5)
            ->map(function ($ejecucion) {
                $config = $ejecucion->config ?? [];
                $origen = $config['created_from'] ?? 'manual';
                $nivelDeuda = $config['nivel_deuda'] ?? null;

                // Determinar label legible del origen
                $origenLabel = match ($origen) {
                    'auto_asignar_sysgal' => 'Sysgal',
                    'manual' => 'Manual',
                    'import' => 'Importación',
                    default => ucfirst($origen),
                };

                // Agregar nivel de deuda si existe
                if ($nivelDeuda) {
                    $origenLabel .= ' ('.ucfirst($nivelDeuda).')';
                }

                return [
                    'ejecucion_id' => $ejecucion->id,
                    'fecha' => $ejecucion->created_at->toISOString(),
                    'fecha_legible' => $ejecucion->created_at->format('d/m/Y H:i'),
                    'prospectos_count' => $ejecucion->prospectos_count ?? 0,
                    'origen' => $origen,
                    'origen_label' => $origenLabel,
                    'estado' => $ejecucion->estado,
                ];
            })
            ->values();

        // Calcular prospectos nuevos del último sync de Sysgal para este flujo
        $nuevosUltimoSync = $this->calcularNuevosSysgalPorFlujo($flujo);

        // Calcular estadísticas de envíos totales del flujo
        $etapaIds = $ejecucionesActivas->flatMap(fn ($e) => $e->etapas->pluck('id'))->unique();
        $estadisticasEnvios = null;

        if ($etapaIds->isNotEmpty()) {
            $stats = \DB::table('envios')
                ->whereIn('flujo_ejecucion_etapa_id', $etapaIds)
                ->selectRaw("
                    COUNT(DISTINCT prospecto_id) as total_prospectos,
                    SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as enviados,
                    SUM(CASE WHEN estado IN ('abierto', 'clickeado') THEN 1 ELSE 0 END) as abiertos,
                    SUM(CASE WHEN estado = 'clickeado' THEN 1 ELSE 0 END) as clicks
                ")
                ->first();

            $enviados = $stats->enviados ?? 0;
            $abiertos = $stats->abiertos ?? 0;
            $clicks = $stats->clicks ?? 0;

            $estadisticasEnvios = [
                'total_prospectos' => $stats->total_prospectos ?? 0,
                'enviados' => $enviados,
                'abiertos' => $abiertos,
                'clicks' => $clicks,
                'tasa_apertura' => $enviados > 0 ? round(($abiertos / $enviados) * 100, 1) : 0,
                'tasa_clicks' => $enviados > 0 ? round(($clicks / $enviados) * 100, 1) : 0,
            ];
        }

        return response()->json([
            'error' => false,
            'data' => [
                'total_cohortes' => $ejecucionesActivas->count(),
                'cohortes' => $cohortes,
                'resumen_por_nodo' => $resumenPorNodo,
                'ultimos_ingresos' => $ultimosIngresos,
                'nuevos_ultimo_sync' => $nuevosUltimoSync,
                'estadisticas_envios' => $estadisticasEnvios,
            ],
        ]);
    }

    /**
     * Calcula cuántos prospectos NUEVOS de Sysgal se agregaron en el último sync
     * para el nivel de deuda que corresponde a este flujo.
     * Incluye el estado de progreso de esos prospectos en el flujo.
     */
    private function calcularNuevosSysgalPorFlujo(Flujo $flujo): ?array
    {
        // Mapeo de flujos de segmento a niveles de deuda
        // Flujo 39 (Segmento 1) → deuda baja
        // Flujo 40 (Segmento 2) → deuda media
        // Flujo 41 (Segmento 3) → deuda alta
        $flujosSegmento = [
            39 => 'baja',
            40 => 'media',
            41 => 'alta',
        ];

        // Si no es un flujo de segmento Sysgal, no mostrar esta info
        if (! isset($flujosSegmento[$flujo->id])) {
            return null;
        }

        $nivelDeudaKey = $flujosSegmento[$flujo->id];

        // Obtener la última importación del lote SYSGAL
        $loteSysgal = \App\Models\Lote::where('nombre', 'SYSGAL')->first();
        if (! $loteSysgal) {
            return null;
        }

        $ultimaImportacion = $loteSysgal->importaciones()->orderBy('created_at', 'desc')->first();
        if (! $ultimaImportacion) {
            return null;
        }

        $metadata = is_array($ultimaImportacion->metadata)
            ? $ultimaImportacion->metadata
            : json_decode($ultimaImportacion->metadata, true);

        // Verificar si existe el desglose de nuevos por nivel
        $nuevosPorNivel = $metadata['nuevos_por_nivel'] ?? null;
        $count = 0;

        if ($nuevosPorNivel && isset($nuevosPorNivel[$nivelDeudaKey])) {
            $count = $nuevosPorNivel[$nivelDeudaKey];
        }

        // Obtener fecha del último sync
        $sysgalSource = \App\Models\ExternalApiSource::where('name', 'sysgal')->first();
        $fechaSync = $sysgalSource?->last_synced_at ?? $ultimaImportacion->created_at;

        // Determinar label del nivel de deuda
        $nivelLabel = match ($flujo->id) {
            39 => 'Deuda Baja',
            40 => 'Deuda Media',
            41 => 'Deuda Alta',
            default => 'Sysgal',
        };

        // Calcular el estado de progreso de los prospectos del último sync
        $estadoProgreso = $this->calcularEstadoProgresoSync($flujo, $fechaSync);

        return [
            'count' => $count,
            'fecha' => $fechaSync->toISOString(),
            'fecha_legible' => $fechaSync->timezone('America/Santiago')->format('d/m/Y H:i'),
            'nivel_deuda' => $nivelLabel,
            'origen' => 'Sysgal',
            'progreso' => $estadoProgreso,
        ];
    }

    /**
     * Calcula el estado de progreso de los prospectos que entraron desde una fecha específica.
     * Muestra cuántos ya alcanzaron el nodo actual vs cuántos están en nodos anteriores.
     */
    private function calcularEstadoProgresoSync(Flujo $flujo, $fechaSync): array
    {
        // Obtener la ejecución perpetua activa del flujo
        $ejecucionActiva = \App\Models\FlujoEjecucion::where('flujo_id', $flujo->id)
            ->where('estado', 'in_progress')
            ->where('es_perpetuo', true)
            ->first();

        if (! $ejecucionActiva) {
            return [
                'tiene_datos' => false,
                'mensaje' => 'Sin ejecución activa',
            ];
        }

        $nodoActual = $ejecucionActiva->nodo_actual;

        // Obtener el orden de etapas del flujo
        $stageOrder = app(\App\Services\StageOrderResolver::class)->getStageOrder($flujo);
        $nodoActualIndex = $nodoActual ? array_search($nodoActual, $stageOrder) : 0;

        if ($nodoActualIndex === false) {
            $nodoActualIndex = 0;
        }

        // Buscar prospectos que entraron al flujo desde la fecha del sync
        // (fecha_inicio >= fechaSync)
        $prospectosSyncQuery = \App\Models\ProspectoEnFlujo::where('flujo_id', $flujo->id)
            ->where('fecha_inicio', '>=', $fechaSync)
            ->where('completado', false)
            ->where('cancelado', false);

        $totalProspectosSync = $prospectosSyncQuery->count();

        if ($totalProspectosSync === 0) {
            return [
                'tiene_datos' => false,
                'mensaje' => 'Prospectos aún no asignados al flujo',
            ];
        }

        // Contar prospectos por estado de avance
        // 1. Sin empezar (ultima_etapa_node_id = NULL)
        $sinEmpezar = (clone $prospectosSyncQuery)->whereNull('ultima_etapa_node_id')->count();

        // 2. En el nodo actual (ya alcanzaron)
        $enNodoActual = $nodoActual
            ? (clone $prospectosSyncQuery)->where('ultima_etapa_node_id', $nodoActual)->count()
            : 0;

        // 3. En nodos anteriores (alcanzando)
        $enNodosAnteriores = 0;
        if ($nodoActualIndex > 0) {
            $nodosAnteriores = array_slice($stageOrder, 0, $nodoActualIndex);
            $enNodosAnteriores = (clone $prospectosSyncQuery)
                ->whereIn('ultima_etapa_node_id', $nodosAnteriores)
                ->count();
        }

        // Calcular estadísticas de envíos para estos prospectos
        $prospectoIds = $prospectosSyncQuery->pluck('prospecto_id')->toArray();

        $enviosStats = \App\Models\Envio::where('flujo_id', $flujo->id)
            ->whereIn('prospecto_id', $prospectoIds)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as enviados,
                SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes
            ")
            ->first();

        // Calcular porcentaje de alcance
        $yaAlcanzaron = $enNodoActual;
        $alcanzando = $sinEmpezar + $enNodosAnteriores;
        $porcentajeAlcance = $totalProspectosSync > 0
            ? round(($yaAlcanzaron / $totalProspectosSync) * 100, 1)
            : 0;

        // Obtener label del nodo actual
        $stages = $flujo->config_structure['stages'] ?? [];
        $nodoActualData = collect($stages)->firstWhere('id', $nodoActual);
        $nodoActualLabel = $nodoActualData['label'] ?? $nodoActual ?? 'Inicio';

        return [
            'tiene_datos' => true,
            'total_prospectos' => $totalProspectosSync,
            'ya_alcanzaron' => $yaAlcanzaron,
            'alcanzando' => $alcanzando,
            'sin_empezar' => $sinEmpezar,
            'en_nodos_anteriores' => $enNodosAnteriores,
            'porcentaje_alcance' => $porcentajeAlcance,
            'nodo_actual' => $nodoActual,
            'nodo_actual_label' => $nodoActualLabel,
            'envios' => [
                'total' => (int) ($enviosStats->total ?? 0),
                'enviados' => (int) ($enviosStats->enviados ?? 0),
                'fallidos' => (int) ($enviosStats->fallidos ?? 0),
                'pendientes' => (int) ($enviosStats->pendientes ?? 0),
            ],
        ];
    }
}
