<?php

namespace App\Http\Controllers;

use App\Enums\CanalEnvio;
use App\Models\Configuracion;
use App\Models\Flujo;
use App\Models\FlujoCondicion;
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
            $query->where('origen_id', $request->input('origen_id'));
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
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'canal_envio' => 'required|in:email,sms,ambos',
        ]);

        $flujo = Flujo::create([
            'tipo_prospecto_id' => $request->input('tipo_prospecto_id'),
            'origen' => $request->input('origen'),
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

        // Calcular estadísticas del flujo
        $estadisticas = [
            'total_prospectos' => $flujo->prospectosEnFlujo()->count(),
            'prospectos_pendientes' => $flujo->prospectosEnFlujo()->where('estado', 'pendiente')->count(),
            'prospectos_en_proceso' => $flujo->prospectosEnFlujo()->where('estado', 'en_proceso')->count(),
            'prospectos_completados' => $flujo->prospectosEnFlujo()->where('completado', true)->count(),
            'prospectos_cancelados' => $flujo->prospectosEnFlujo()->where('cancelado', true)->count(),
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
     */
    public function agregarProspectos(Request $request, Flujo $flujo): JsonResponse
    {
        $request->validate([
            'importacion_id' => 'nullable|exists:importaciones,id',
            'prospecto_ids' => 'nullable|array',
            'prospecto_ids.*' => 'exists:prospectos,id',
        ]);

        try {
            DB::beginTransaction();

            $prospectosQuery = Prospecto::query()
                ->where('tipo_prospecto_id', $flujo->tipo_prospecto_id)
                ->whereHas('importacion', function ($query) use ($flujo) {
                    $query->where('origen', $flujo->origen);
                });

            // Si se especifica una importación, filtrar por ella
            if ($request->filled('importacion_id')) {
                $prospectosQuery->where('importacion_id', $request->input('importacion_id'));
            }

            // Si se especifican IDs específicos, usarlos
            if ($request->filled('prospecto_ids')) {
                $prospectosQuery->whereIn('id', $request->input('prospecto_ids'));
            }

            $prospectos = $prospectosQuery->get();

            $agregados = 0;
            $yaExistentes = 0;

            foreach ($prospectos as $prospecto) {
                $existe = ProspectoEnFlujo::where('flujo_id', $flujo->id)
                    ->where('prospecto_id', $prospecto->id)
                    ->exists();

                if (! $existe) {
                    ProspectoEnFlujo::create([
                        'flujo_id' => $flujo->id,
                        'prospecto_id' => $prospecto->id,
                        'estado' => 'pendiente',
                        'etapa_actual_id' => null,
                    ]);
                    $agregados++;
                } else {
                    $yaExistentes++;
                }
            }

            DB::commit();

            return response()->json([
                'mensaje' => 'Prospectos agregados al flujo exitosamente',
                'resumen' => [
                    'total_encontrados' => $prospectos->count(),
                    'agregados' => $agregados,
                    'ya_existentes' => $yaExistentes,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'mensaje' => 'Error al agregar prospectos al flujo',
                'error' => $e->getMessage(),
            ], 500);
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
        // Obtener todos los orígenes de importaciones con conteo de flujos
        $origenes = \App\Models\Importacion::query()
            ->select('origen')
            ->distinct()
            ->get()
            ->map(function ($importacion) {
                // Contar cuántos flujos tienen este origen
                $totalFlujos = Flujo::where('origen', $importacion->origen)->count();

                return [
                    'id' => $importacion->origen,
                    'nombre' => $importacion->origen,
                    'total_flujos' => $totalFlujos,
                ];
            })
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
     * Get cost statistics for all flujos.
     */
    public function estadisticasCostos(): JsonResponse
    {
        $flujos = Flujo::all();

        $totalGastado = 0;
        $totalEmails = 0;
        $totalSms = 0;
        $totalProspectos = 0;
        $costosPorFlujo = [];

        foreach ($flujos as $flujo) {
            $costos = $flujo->metadata['costos_vigentes'] ?? null;

            if ($costos) {
                $totalGastado += $costos['costo_total'];
                $totalEmails += $costos['cantidad_emails'];
                $totalSms += $costos['cantidad_sms'];
                $totalProspectos += ($costos['cantidad_emails'] + $costos['cantidad_sms']);

                $costosPorFlujo[] = [
                    'flujo_id' => $flujo->id,
                    'nombre' => $flujo->nombre,
                    'fecha_creacion' => $flujo->created_at->toISOString(),
                    'costo_total' => $costos['costo_total'],
                    'email_unitario' => $costos['email_costo_unitario'],
                    'sms_unitario' => $costos['sms_costo_unitario'],
                    'cantidad_emails' => $costos['cantidad_emails'],
                    'cantidad_sms' => $costos['cantidad_sms'],
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
     * Debug: Ver qué datos llegan al endpoint (TEMPORAL).
     */
    public function debugPayload(Request $request): JsonResponse
    {
        return response()->json([
            'content_type' => $request->header('Content-Type'),
            'all_data' => $request->all(),
            'keys' => array_keys($request->all()),
            'raw' => $request->getContent(),
        ]);
    }

    /**
     * Crear flujo con prospectos y distribución de canales (email/sms).
     * Soporta tanto el formato antiguo como el nuevo FlowBuilder.
     */
    public function crearFlujoConProspectos(\App\Http\Requests\CrearFlujoConProspectosRequest $request): JsonResponse
    {
        // Early return: Validar tipo de prospecto antes de iniciar transacción
        $tipoProspecto = $this->findTipoProspecto($request->input('flujo.tipo_prospecto'));

        if ($tipoProspecto === null) {
            return $this->tipoProspectoNotFoundResponse($request->input('flujo.tipo_prospecto'));
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
                $request->boolean('prospectos.select_all_from_origin', false)
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
     * Para grandes volúmenes (>100), despacha un Job en background.
     *
     * @return array{total: int, email: int, sms: int, is_async: bool}
     */
    private function asignarProspectosAlFlujo(Flujo $flujo, array $prospectoIds, CanalEnvio $canalEnvio, bool $selectAllFromOrigin = false): array
    {
        $conteo = ['total' => 0, 'email' => 0, 'sms' => 0, 'is_async' => false];

        // Si se solicita seleccionar todos del origen, buscarlos automáticamente
        if ($selectAllFromOrigin) {
            $prospectoIds = Prospecto::query()
                ->where('tipo_prospecto_id', $flujo->tipo_prospecto_id)
                ->whereHas('importacion', function ($query) use ($flujo) {
                    $query->where('origen', $flujo->origen);
                })
                ->pluck('id')
                ->toArray();
        }

        if (empty($prospectoIds)) {
            return $conteo;
        }

        $canalAsignado = $this->determinarCanalParaProspectos($canalEnvio);
        $totalProspectos = count($prospectoIds);

        // Umbral para procesamiento async: más de 100 prospectos
        if ($totalProspectos > 100) {
            // Procesar en background - afterCommit() espera a que la transacción termine
            // para evitar race condition donde el job busca un flujo que aún no existe
            \App\Jobs\AsignarProspectosAFlujoJob::dispatch($flujo, $prospectoIds, $canalAsignado)
                ->afterCommit();

            // Marcar flujo como "procesando"
            $flujo->update(['estado_procesamiento' => 'procesando']);

            // Retornar conteo estimado
            $conteo['total'] = $totalProspectos;
            $conteo[$canalAsignado] = $totalProspectos;
            $conteo['is_async'] = true;

            return $conteo;
        }

        // Procesamiento síncrono para cantidades pequeñas
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
}
