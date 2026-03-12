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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlujoController extends Controller
{
    public function __construct(
        private readonly CanalEnvioResolver $canalEnvioResolver
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
        if ($request->filled('origen_id')) {
            $origenId = $request->input('origen_id');

            if ($origenId === '_sin_origen') {
                $query->whereNull('origen_id');
            } elseif ($origenId !== '_todos') {
                $query->where('origen_id', $origenId);
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
            'user',
            'prospectosEnFlujo' => function ($query) {
                $query->with('prospecto')->latest()->limit(100);
            },
            'flujoEtapas',
            'flujoCondiciones',
            'flujoRamificaciones',
            'flujoNodosFinales',
            'ejecuciones' => function ($query) {
                $query->latest()->limit(50);
            },
        ]);

        // Calcular estadísticas del flujo con UNA SOLA query (optimizado)
        // Antes: 5 queries separadas. Ahora: 1 query con conditional aggregation
        $stats = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes,
                COUNT(CASE WHEN estado = 'en_proceso' THEN 1 END) as en_proceso,
                COUNT(CASE WHEN completado = true THEN 1 END) as completados,
                COUNT(CASE WHEN cancelado = true THEN 1 END) as cancelados
            ")
            ->first();

        $estadisticas = [
            'total_prospectos' => $stats->total ?? 0,
            'prospectos_pendientes' => $stats->pendientes ?? 0,
            'prospectos_en_proceso' => $stats->en_proceso ?? 0,
            'prospectos_completados' => $stats->completados ?? 0,
            'prospectos_cancelados' => $stats->cancelados ?? 0,
            'total_etapas' => $flujo->flujoEtapas->count(),
            'total_condiciones' => $flujo->flujoCondiciones->count(),
            'total_ramificaciones' => $flujo->flujoRamificaciones->count(),
            'total_nodos_finales' => $flujo->flujoNodosFinales->count(),
        ];

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
            'config_visual' => 'sometimes|array',
            'config_visual.nodes' => 'sometimes|array',
            'config_visual.edges' => 'sometimes|array',
            'config_structure' => 'sometimes|array',
            'config_structure.stages' => 'sometimes|array',
            'config_structure.conditions' => 'sometimes|array',
            'config_structure.branches' => 'sometimes|array',
            'config_structure.end_nodes' => 'sometimes|array',
        ]);

        try {
            DB::beginTransaction();

            // Actualizar campos básicos del flujo
            $flujo->update($request->only([
                'nombre',
                'descripcion',
                'canal_envio',
                'activo',
                'auto_asignar_nuevos',
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
            $totalProspectos = $flujo->prospectosEnFlujo()->count();
            $totalEtapas = $flujo->flujoEtapas()->count();
            $totalCondiciones = $flujo->flujoCondiciones()->count();
            $totalEjecuciones = $flujo->ejecuciones()->count();

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
                    'prospectos_desvinculados' => $totalProspectos,
                    'etapas_eliminadas' => $totalEtapas,
                    'condiciones_eliminadas' => $totalCondiciones,
                    'ejecuciones_eliminadas' => $totalEjecuciones,
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

            // 3. Bulk insert de los nuevos (si hay)
            if (! empty($nuevosIds)) {
                $now = now();
                $registros = array_map(fn ($id) => [
                    'flujo_id' => $flujo->id,
                    'prospecto_id' => $id,
                    'canal_asignado' => $canalAsignado,
                    'estado' => 'pendiente',
                    'etapa_actual_id' => null,
                    'fecha_inicio' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $nuevosIds);

                // Insertar en chunks para evitar límites de MySQL/PostgreSQL
                foreach (array_chunk($registros, 1000) as $chunk) {
                    ProspectoEnFlujo::insert($chunk);
                }
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
        // Obtener display_names de fuentes INACTIVAS (deprecated) para filtrarlas
        // Removemos el prefijo [DEPRECATED] para hacer match con los nombres en importaciones
        $deprecatedOrigins = DB::table('external_api_sources')
            ->where('is_active', false)
            ->pluck('display_name')
            ->map(fn ($name) => str_replace('[DEPRECATED] ', '', $name))
            ->toArray();

        // OPTIMIZADO: Una sola query con subquery en vez de N+1
        // Antes: 1 query para orígenes + N queries para contar flujos
        // Ahora: 1 query con LEFT JOIN y GROUP BY
        // Filtramos orígenes que vienen de fuentes deprecated/inactivas
        $origenes = DB::table('importaciones')
            ->select('importaciones.origen')
            ->selectRaw('COUNT(DISTINCT flujos.id) as total_flujos')
            ->leftJoin('flujos', 'flujos.origen', '=', 'importaciones.origen')
            ->when(count($deprecatedOrigins) > 0, function ($query) use ($deprecatedOrigins) {
                $query->whereNotIn('importaciones.origen', $deprecatedOrigins);
            })
            ->groupBy('importaciones.origen')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->origen,
                'nombre' => $row->origen,
                'total_flujos' => (int) $row->total_flujos,
            ])
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
        // Get all node_ids from config_visual
        $configVisual = $flujo->config_visual;
        if (! $configVisual || empty($configVisual['nodes'])) {
            return response()->json([
                'error' => false,
                'data' => [],
            ]);
        }

        // Extract node info (id, type, tipo_mensaje) from config
        $nodesInfo = collect($configVisual['nodes'])->mapWithKeys(function ($node) {
            return [
                $node['id'] => [
                    'type' => $node['type'] ?? 'stage',
                    'tipo_mensaje' => $node['data']['tipo_mensaje'] ?? 'email',
                    'label' => $node['data']['label'] ?? $node['id'],
                ],
            ];
        });

        // Get all flujo_ejecucion_etapa IDs for this flujo, mapped by node_id
        $etapasPorNodeId = DB::table('flujo_ejecucion_etapas as fee')
            ->join('flujo_ejecuciones as fe', 'fee.flujo_ejecucion_id', '=', 'fe.id')
            ->where('fe.flujo_id', $flujo->id)
            ->select('fee.id as etapa_id', 'fee.node_id')
            ->get()
            ->groupBy('node_id');

        if ($etapasPorNodeId->isEmpty()) {
            // No executions yet, return empty stats for each node
            $emptyStats = $nodesInfo->map(function ($info, $nodeId) {
                $isEmail = in_array($info['tipo_mensaje'], ['email', 'ambos']);
                $isSms = in_array($info['tipo_mensaje'], ['sms', 'ambos']);

                return [
                    'node_id' => $nodeId,
                    'tipo_mensaje' => $info['tipo_mensaje'],
                    'label' => $info['label'],
                    'total_enviado' => 0,
                    'total_fallido' => 0,
                    'total_abierto' => $isEmail ? 0 : null,
                    'total_clickeado' => $isEmail ? 0 : null,
                ];
            })->values();

            return response()->json([
                'error' => false,
                'data' => $emptyStats,
            ]);
        }

        // Get all etapa IDs
        $allEtapaIds = $etapasPorNodeId->flatten()->pluck('etapa_id')->toArray();

        // Query aggregated stats from envios table
        $stats = DB::table('envios')
            ->select(
                'flujo_ejecucion_etapa_id',
                'canal',
                'estado',
                DB::raw('count(*) as total')
            )
            ->whereIn('flujo_ejecucion_etapa_id', $allEtapaIds)
            ->groupBy('flujo_ejecucion_etapa_id', 'canal', 'estado')
            ->get();

        // Build node_id -> stats mapping
        $statsByNodeId = [];

        foreach ($etapasPorNodeId as $nodeId => $etapas) {
            $etapaIds = $etapas->pluck('etapa_id')->toArray();
            $nodeStats = $stats->whereIn('flujo_ejecucion_etapa_id', $etapaIds);

            // Aggregate by estado
            $enviado = $nodeStats->whereIn('estado', ['enviado', 'abierto', 'clickeado'])->sum('total');
            $fallido = $nodeStats->where('estado', 'fallido')->sum('total');
            $abierto = $nodeStats->whereIn('estado', ['abierto', 'clickeado'])->sum('total');
            $clickeado = $nodeStats->where('estado', 'clickeado')->sum('total');
            $pendiente = $nodeStats->where('estado', 'pendiente')->sum('total');

            $nodeInfo = $nodesInfo[$nodeId] ?? ['tipo_mensaje' => 'email', 'label' => $nodeId];
            $isEmail = in_array($nodeInfo['tipo_mensaje'], ['email', 'ambos']);

            $statsByNodeId[$nodeId] = [
                'node_id' => $nodeId,
                'tipo_mensaje' => $nodeInfo['tipo_mensaje'],
                'label' => $nodeInfo['label'],
                'total_pendiente' => $pendiente,
                'total_enviado' => $enviado,
                'total_fallido' => $fallido,
                // Only include email-specific stats if it's an email node
                'total_abierto' => $isEmail ? $abierto : null,
                'total_clickeado' => $isEmail ? $clickeado : null,
            ];
        }

        // Include nodes without executions
        foreach ($nodesInfo as $nodeId => $info) {
            if (! isset($statsByNodeId[$nodeId]) && $info['type'] === 'stage') {
                $isEmail = in_array($info['tipo_mensaje'], ['email', 'ambos']);
                $statsByNodeId[$nodeId] = [
                    'node_id' => $nodeId,
                    'tipo_mensaje' => $info['tipo_mensaje'],
                    'label' => $info['label'],
                    'total_pendiente' => 0,
                    'total_enviado' => 0,
                    'total_fallido' => 0,
                    'total_abierto' => $isEmail ? 0 : null,
                    'total_clickeado' => $isEmail ? 0 : null,
                ];
            }
        }

        return response()->json([
            'error' => false,
            'data' => array_values($statsByNodeId),
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
        // Get aggregated send stats for this flujo
        $enviosStats = DB::table('envios')
            ->where('flujo_id', $flujo->id)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as enviados"),
                DB::raw("SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos"),
                DB::raw("SUM(CASE WHEN estado IN ('abierto', 'clickeado') THEN 1 ELSE 0 END) as abiertos"),
                DB::raw("SUM(CASE WHEN estado = 'clickeado' THEN 1 ELSE 0 END) as clickeados"),
                DB::raw("SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes"),
                DB::raw("SUM(CASE WHEN canal = 'email' AND estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as emails_enviados"),
                DB::raw("SUM(CASE WHEN canal = 'sms' AND estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as sms_enviados")
            )
            ->first();

        // Get prospect stats
        $prospectosStats = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completados"),
                DB::raw("SUM(CASE WHEN estado = 'en_proceso' THEN 1 ELSE 0 END) as en_proceso"),
                DB::raw("SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes"),
                DB::raw("SUM(CASE WHEN estado = 'cancelado' THEN 1 ELSE 0 END) as cancelados")
            )
            ->first();

        // Get cost info from flujo metadata
        $costos = $flujo->metadata['costos_vigentes'] ?? null;
        $costoTotal = $costos['costo_total'] ?? 0;

        // Get per-stage stats
        $configVisual = $flujo->config_visual;
        $stageStats = [];

        if ($configVisual && ! empty($configVisual['nodes'])) {
            // Get all etapa IDs grouped by node_id
            $etapasPorNodeId = DB::table('flujo_ejecucion_etapas as fee')
                ->join('flujo_ejecuciones as fe', 'fee.flujo_ejecucion_id', '=', 'fe.id')
                ->where('fe.flujo_id', $flujo->id)
                ->select('fee.id as etapa_id', 'fee.node_id')
                ->get()
                ->groupBy('node_id');

            // Get stats for all etapas
            $allEtapaIds = $etapasPorNodeId->flatten()->pluck('etapa_id')->toArray();

            $enviosPorEtapa = [];
            if (! empty($allEtapaIds)) {
                $enviosPorEtapa = DB::table('envios')
                    ->select(
                        'flujo_ejecucion_etapa_id',
                        'estado',
                        DB::raw('count(*) as total')
                    )
                    ->whereIn('flujo_ejecucion_etapa_id', $allEtapaIds)
                    ->groupBy('flujo_ejecucion_etapa_id', 'estado')
                    ->get()
                    ->groupBy('flujo_ejecucion_etapa_id');
            }

            // Build per-stage stats
            foreach ($configVisual['nodes'] as $node) {
                if (($node['type'] ?? '') !== 'stage') {
                    continue;
                }

                $nodeId = $node['id'];
                $label = $node['data']['label'] ?? $nodeId;
                $tipoMensaje = $node['data']['tipo_mensaje'] ?? 'email';
                $orden = $node['data']['orden'] ?? 0;

                // Get etapa IDs for this node
                $nodeEtapaIds = ($etapasPorNodeId[$nodeId] ?? collect())->pluck('etapa_id')->toArray();

                // Aggregate stats
                $enviado = 0;
                $fallido = 0;
                $abierto = 0;
                $clickeado = 0;

                foreach ($nodeEtapaIds as $etapaId) {
                    $stats = $enviosPorEtapa[$etapaId] ?? collect();
                    $enviado += $stats->whereIn('estado', ['enviado', 'abierto', 'clickeado'])->sum('total');
                    $fallido += $stats->where('estado', 'fallido')->sum('total');
                    $abierto += $stats->whereIn('estado', ['abierto', 'clickeado'])->sum('total');
                    $clickeado += $stats->where('estado', 'clickeado')->sum('total');
                }

                $tasaApertura = $enviado > 0 ? round(($abierto / $enviado) * 100, 1) : 0;
                $tasaClick = $enviado > 0 ? round(($clickeado / $enviado) * 100, 1) : 0;

                $stageStats[] = [
                    'node_id' => $nodeId,
                    'label' => $label,
                    'tipo_mensaje' => $tipoMensaje,
                    'orden' => $orden,
                    'enviados' => $enviado,
                    'fallidos' => $fallido,
                    'abiertos' => in_array($tipoMensaje, ['email', 'ambos']) ? $abierto : null,
                    'clickeados' => in_array($tipoMensaje, ['email', 'ambos']) ? $clickeado : null,
                    'tasa_apertura' => in_array($tipoMensaje, ['email', 'ambos']) ? $tasaApertura : null,
                    'tasa_click' => in_array($tipoMensaje, ['email', 'ambos']) ? $tasaClick : null,
                ];
            }

            // Sort by orden
            usort($stageStats, fn ($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));
        }

        // Calculate rates
        $totalEnviados = $enviosStats->enviados ?? 0;
        $totalAbiertos = $enviosStats->abiertos ?? 0;
        $totalClickeados = $enviosStats->clickeados ?? 0;
        $totalFallidos = $enviosStats->fallidos ?? 0;
        $totalProspectos = $prospectosStats->total ?? 0;
        $prospectosCompletados = $prospectosStats->completados ?? 0;

        $tasaApertura = $totalEnviados > 0 ? round(($totalAbiertos / $totalEnviados) * 100, 1) : 0;
        $tasaClick = $totalEnviados > 0 ? round(($totalClickeados / $totalEnviados) * 100, 1) : 0;
        $tasaFallo = ($totalEnviados + $totalFallidos) > 0
            ? round(($totalFallidos / ($totalEnviados + $totalFallidos)) * 100, 1)
            : 0;
        $costoPorConversion = $prospectosCompletados > 0
            ? round($costoTotal / $prospectosCompletados, 2)
            : 0;

        return response()->json([
            'error' => false,
            'data' => [
                // Summary metrics (cards)
                'resumen' => [
                    'tasa_apertura' => $tasaApertura,
                    'tasa_click' => $tasaClick,
                    'tasa_fallo' => $tasaFallo,
                    'costo_total' => round($costoTotal, 2),
                    'costo_por_conversion' => $costoPorConversion,
                    'emails_enviados' => $enviosStats->emails_enviados ?? 0,
                    'sms_enviados' => $enviosStats->sms_enviados ?? 0,
                ],
                // Funnel data
                'funnel' => [
                    'prospectos' => $totalProspectos,
                    'enviados' => $totalEnviados,
                    'abiertos' => $totalAbiertos,
                    'clickeados' => $totalClickeados,
                    'conversiones' => $prospectosCompletados,
                    // Rates between steps (tasa_envio removed: can exceed 100% since 1 prospect = N messages)
                    'tasa_apertura' => $tasaApertura,
                    'tasa_click_sobre_abiertos' => $totalAbiertos > 0
                        ? round(($totalClickeados / $totalAbiertos) * 100, 1)
                        : 0,
                    'tasa_conversion' => $totalProspectos > 0
                        ? round(($prospectosCompletados / $totalProspectos) * 100, 1)
                        : 0,
                ],
                // Per-stage breakdown
                'etapas' => $stageStats,
                // Totals
                'totales' => [
                    'envios' => [
                        'total' => $enviosStats->total ?? 0,
                        'enviados' => $totalEnviados,
                        'fallidos' => $totalFallidos,
                        'pendientes' => $enviosStats->pendientes ?? 0,
                        'abiertos' => $totalAbiertos,
                        'clickeados' => $totalClickeados,
                    ],
                    'prospectos' => [
                        'total' => $totalProspectos,
                        'completados' => $prospectosCompletados,
                        'en_proceso' => $prospectosStats->en_proceso ?? 0,
                        'pendientes' => $prospectosStats->pendientes ?? 0,
                        'cancelados' => $prospectosStats->cancelados ?? 0,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Get cost statistics for all flujos.
     *
     * OPTIMIZADO: Solo selecciona columnas necesarias en vez de cargar
     * todos los modelos completos en memoria.
     */
    public function estadisticasCostos(): JsonResponse
    {
        // Solo traer las columnas necesarias (id, nombre, metadata, created_at)
        // Evita cargar toda la tabla flujos con campos grandes como config_visual
        $flujos = Flujo::query()
            ->select('id', 'nombre', 'metadata', 'created_at')
            ->whereNotNull('metadata')
            ->get();

        $totalGastado = 0;
        $totalEmails = 0;
        $totalSms = 0;
        $totalProspectos = 0;
        $costosPorFlujo = [];

        foreach ($flujos as $flujo) {
            $costos = $flujo->metadata['costos_vigentes'] ?? null;

            if ($costos) {
                $costoTotal = (float) ($costos['costo_total'] ?? 0);
                $cantidadEmails = (int) ($costos['cantidad_emails'] ?? 0);
                $cantidadSms = (int) ($costos['cantidad_sms'] ?? 0);

                $totalGastado += $costoTotal;
                $totalEmails += $cantidadEmails;
                $totalSms += $cantidadSms;
                $totalProspectos += ($cantidadEmails + $cantidadSms);

                $costosPorFlujo[] = [
                    'flujo_id' => $flujo->id,
                    'nombre' => $flujo->nombre,
                    'fecha_creacion' => $flujo->created_at->toISOString(),
                    'costo_total' => $costoTotal,
                    'email_unitario' => (float) ($costos['email_costo_unitario'] ?? 0),
                    'sms_unitario' => (float) ($costos['sms_costo_unitario'] ?? 0),
                    'cantidad_emails' => $cantidadEmails,
                    'cantidad_sms' => $cantidadSms,
                ];
            }
        }

        return response()->json([
            'data' => [
                'resumen' => [
                    'total_gastado' => round($totalGastado, 2),
                    'total_emails_enviados' => $totalEmails,
                    'total_sms_enviados' => $totalSms,
                    'total_prospectos_contactados' => $totalProspectos,
                    'costo_promedio_por_prospecto' => $totalProspectos > 0 ? round($totalGastado / $totalProspectos, 2) : 0,
                ],
                'flujos' => $costosPorFlujo,
            ],
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
        return Flujo::create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'origen_id' => $request->input('origen_id'),
            'origen' => $request->input('origen_nombre'),
            'nombre' => $request->input('flujo.nombre'),
            'descripcion' => $request->input('flujo.descripcion'),
            'canal_envio' => $canalEnvio->value,
            'activo' => $request->input('flujo.activo', true),
            'user_id' => $request->user()->id,
            'config_visual' => $request->input('visual'),
            'config_structure' => $request->input('structure'),
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
     */
    private function asignarProspectosSync(Flujo $flujo, array $prospectoIds, string $canalAsignado): array
    {
        $conteo = ['total' => 0, 'email' => 0, 'sms' => 0, 'is_async' => false];

        foreach ($prospectoIds as $prospectoId) {
            ProspectoEnFlujo::create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospectoId,
                'canal_asignado' => $canalAsignado,
                'estado' => 'pendiente',
                'etapa_actual_id' => null,
                'fecha_inicio' => now(),
            ]);

            $conteo['total']++;
            $conteo[$canalAsignado]++;
        }

        $flujo->update(['estado_procesamiento' => 'completado']);

        return $conteo;
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
    protected function guardarEstructuraFlowBuilder(Flujo $flujo, array $structure): void
    {
        // Guardar etapas (stages)
        if (isset($structure['stages']) && is_array($structure['stages'])) {
            foreach ($structure['stages'] as $stage) {
                \App\Models\FlujoEtapa::create([
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
                ]);
            }
        }

        // Guardar condiciones (conditions)
        if (isset($structure['conditions']) && is_array($structure['conditions'])) {
            foreach ($structure['conditions'] as $condition) {
                // Inferir check_param según condition_type si no viene
                $conditionType = $condition['condition_type'] ?? 'email_opened';
                $defaultCheckParam = $this->getDefaultCheckParamForConditionType($conditionType);

                FlujoCondicion::create([
                    'id' => $condition['id'],
                    'flujo_id' => $flujo->id,
                    'label' => $condition['label'] ?? '',
                    'description' => $condition['description'] ?? null,
                    'condition_type' => $conditionType,
                    'condition_label' => $condition['condition_label'] ?? '',
                    'yes_label' => $condition['yes_label'] ?? 'Sí',
                    'no_label' => $condition['no_label'] ?? 'No',
                    // Campos de evaluación para VerificarCondicionJob
                    'check_param' => $condition['check_param'] ?? $defaultCheckParam,
                    'check_operator' => $condition['check_operator'] ?? '>',
                    'check_value' => $condition['check_value'] ?? '0',
                ]);
            }
        }

        // Guardar ramificaciones (branches)
        if (isset($structure['branches']) && is_array($structure['branches'])) {
            foreach ($structure['branches'] as $branch) {
                \App\Models\FlujoRamificacion::create([
                    'flujo_id' => $flujo->id,
                    'edge_id' => $branch['edge_id'] ?? '',
                    'source_node_id' => $branch['source_node_id'] ?? '',
                    'target_node_id' => $branch['target_node_id'] ?? '',
                    'source_handle' => $branch['source_handle'] ?? null,
                    'target_handle' => $branch['target_handle'] ?? null,
                    'condition_branch' => $branch['condition_branch'] ?? null,
                ]);
            }
        }

        // Guardar nodos finales (end_nodes)
        if (isset($structure['end_nodes']) && is_array($structure['end_nodes'])) {
            foreach ($structure['end_nodes'] as $endNode) {
                // ✅ Soportar end_node como string (ID) o como objeto
                if (is_string($endNode)) {
                    // Frontend envía array de strings: ["end-1"]
                    $endNodeId = $endNode;
                    $label = 'Fin';
                    $description = null;
                } else {
                    // Frontend envía array de objetos: [{"id": "end-1", "data": {...}}]
                    $endNodeId = $endNode['id'] ?? null;
                    $label = $endNode['data']['label'] ?? 'Fin';
                    $description = $endNode['data']['description'] ?? null;
                }

                if ($endNodeId) {
                    \App\Models\FlujoNodoFinal::create([
                        'node_id' => $endNodeId,
                        'flujo_id' => $flujo->id,
                        'label' => $label,
                        'description' => $description,
                    ]);
                }
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
        // Obtener todas las ejecuciones activas (in_progress o paused)
        $ejecucionesActivas = $flujo->ejecuciones()
            ->whereIn('estado', ['in_progress', 'paused'])
            ->with(['etapas' => function ($query) {
                $query->select([
                    'id',
                    'flujo_ejecucion_id',
                    'node_id',
                    'estado',
                    'fecha_programada',
                    'fecha_ejecucion',
                    'prospectos_ids',
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

        // Construir resumen de cada cohorte
        $cohortes = $ejecucionesActivas->map(function ($ejecucion) {
            $prospectosCount = is_array($ejecucion->prospectos_ids)
                ? count($ejecucion->prospectos_ids)
                : ($ejecucion->prospectos_count ?? 0);

            // Calcular progreso basado en etapas
            $etapasTotal = $ejecucion->etapas->count();
            $etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
            $etapasEjecutando = $ejecucion->etapas->where('estado', 'executing')->count();
            $progreso = $etapasTotal > 0 ? round(($etapasCompletadas / $etapasTotal) * 100) : 0;

            // Determinar estado legible
            $estadoLegible = match ($ejecucion->estado) {
                'in_progress' => $etapasEjecutando > 0 ? 'Procesando' : 'En progreso',
                'paused' => 'Pausado',
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
                        'detalle_cohortes' => [],
                    ];
                }

                $resumenPorNodo[$nodeId]['total_cohortes']++;

                // Contar prospectos de esta etapa
                $prospectosEtapa = is_array($etapa->prospectos_ids) ? count($etapa->prospectos_ids) : 0;

                // Si no tiene prospectos_ids, usar los de la ejecución (para primera etapa)
                if ($prospectosEtapa === 0 && is_array($ejecucion->prospectos_ids)) {
                    $prospectosEtapa = count($ejecucion->prospectos_ids);
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

        return response()->json([
            'error' => false,
            'data' => [
                'total_cohortes' => $ejecucionesActivas->count(),
                'cohortes' => $cohortes,
                'resumen_por_nodo' => $resumenPorNodo,
            ],
        ]);
    }
}
