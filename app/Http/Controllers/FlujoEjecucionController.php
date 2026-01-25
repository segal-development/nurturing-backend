<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarEtapaJob;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FlujoEjecucionController extends Controller
{
    /**
     * Ejecuta un flujo para un conjunto de prospectos
     *
     * POST /api/flujos/{flujo}/ejecutar
     *
     * Body:
     * {
     *   "origen_id": "manual|segmentacion_123",
     *   "prospectos_ids": [1, 2, 3, ...],
     *   "fecha_inicio_programada": "2025-11-27 10:00:00" (opcional, default: now)
     * }
     */
    public function execute(Request $request, Flujo $flujo): JsonResponse
    {
        // Validar request - prospectos_ids es opcional si use_all_prospectos es true
        $validator = Validator::make($request->all(), [
            'origen_id' => 'nullable|string',
            'prospectos_ids' => 'nullable|array',
            'prospectos_ids.*' => 'integer|exists:prospectos,id',
            'use_all_prospectos' => 'nullable|boolean',
            'fecha_inicio_programada' => 'nullable|date|after_or_equal:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'mensaje' => 'Errores de validación',
                'errores' => $validator->errors(),
            ], 422);
        }

        // Si use_all_prospectos es true, obtener IDs de la BD
        $prospectoIds = $request->prospectos_ids ?? [];
        
        if ($request->boolean('use_all_prospectos', false)) {
            // Obtener todos los prospectos_ids del flujo de la BD (sin cargar modelos)
            $prospectoIds = $flujo->prospectosEnFlujo()->pluck('prospecto_id')->toArray();
            
            Log::info('FlujoEjecucion: Usando todos los prospectos del flujo', [
                'flujo_id' => $flujo->id,
                'total_prospectos' => count($prospectoIds),
            ]);
        }

        if (empty($prospectoIds)) {
            return response()->json([
                'error' => true,
                'mensaje' => 'El flujo no tiene prospectos configurados',
            ], 422);
        }

        // Reemplazar prospectos_ids en el request para el resto del código
        $request->merge(['prospectos_ids' => $prospectoIds]);

        // Verificar que no haya una ejecución activa para este flujo
        $ejecucionActiva = FlujoEjecucion::where('flujo_id', $flujo->id)
            ->activas()
            ->first();

        if ($ejecucionActiva) {
            return response()->json([
                'error' => true,
                'mensaje' => 'Ya existe una ejecución activa para este flujo',
                'data' => [
                    'ejecucion_activa_id' => $ejecucionActiva->id,
                    'estado' => $ejecucionActiva->estado,
                    'fecha_inicio' => $ejecucionActiva->fecha_inicio_programada,
                ],
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Verificar que el flujo tenga datos válidos
            if (empty($flujo->flujo_data)) {
                throw new \Exception('El flujo no tiene datos de configuración');
            }

            $flujoData = $flujo->flujo_data;

            // ✅ NORMALIZAR ESTRUCTURA: Soportar nueva estructura (structure + visual) y antigua (root)
            $structure = null;
            $visual = null;

            if (isset($flujoData['structure']) && isset($flujoData['visual'])) {
                // Nueva estructura del frontend
                Log::info('FlujoEjecucion: Detectada nueva estructura (structure + visual)');
                $structure = $flujoData['structure'];
                $visual = $flujoData['visual'];
                $stages = $structure['stages'] ?? [];
                $branches = $structure['branches'] ?? [];
            } else {
                // Estructura antigua (todo en root)
                Log::info('FlujoEjecucion: Detectada estructura antigua (root)');
                $stages = $flujoData['stages'] ?? [];
                $branches = $flujoData['branches'] ?? [];
            }

            // ✅ NORMALIZAR CONEXIONES: Si branches está vacío pero edges existe, convertir edges a branches
            if (empty($branches) && isset($flujoData['edges']) && ! empty($flujoData['edges'])) {
                Log::info('FlujoEjecucion: Convirtiendo edges a branches');
                $branches = collect($flujoData['edges'])->map(function ($edge) {
                    return [
                        'source_node_id' => $edge['source'] ?? null,
                        'target_node_id' => $edge['target'] ?? null,
                        'source_handle' => $edge['sourceHandle'] ?? null,
                        'target_handle' => $edge['targetHandle'] ?? null,
                    ];
                })->filter(function ($branch) {
                    return ! empty($branch['source_node_id']) && ! empty($branch['target_node_id']);
                })->values()->toArray();

                Log::info('FlujoEjecucion: Branches normalizados', [
                    'total_branches' => count($branches),
                ]);
            }

            if (empty($stages)) {
                throw new \Exception('El flujo no tiene etapas definidas');
            }

            // ✅ OBTENER NODO INICIAL según estructura
            $startNodeId = null;

            if ($structure && isset($structure['initial_node'])) {
                // Nueva estructura: initial_node es un STRING (ID)
                $startNodeId = $structure['initial_node'];

                Log::info('FlujoEjecucion: Nodo inicial desde structure.initial_node', [
                    'initial_node_id' => $startNodeId,
                ]);

                // Validar que no sea null o vacío
                if (empty($startNodeId)) {
                    throw new \Exception('El flujo no tiene un nodo de inicio (structure.initial_node está vacío)');
                }

                // Opcional: Buscar el nodo completo en visual.nodes si necesitamos más datos
                if ($visual && isset($visual['nodes'])) {
                    $initialNodeCompleto = collect($visual['nodes'])->firstWhere('id', $startNodeId);

                    if (! $initialNodeCompleto) {
                        Log::warning('FlujoEjecucion: Nodo inicial no encontrado en visual.nodes', [
                            'initial_node_id' => $startNodeId,
                        ]);
                    } else {
                        Log::info('FlujoEjecucion: Nodo inicial encontrado en visual.nodes', [
                            'node_type' => $initialNodeCompleto['type'] ?? 'unknown',
                        ]);
                    }
                }
            } else {
                // Estructura antigua: buscar nodo inicial en stages o initial_node
                $startNode = collect($stages)->first(function ($stage) {
                    $type = $stage['type'] ?? null;

                    return $type === 'start' || $type === 'initial';
                });

                // Si no está en stages, buscar en initial_node (root)
                if (! $startNode && isset($flujoData['initial_node'])) {
                    $startNode = $flujoData['initial_node'];
                }

                if (! $startNode) {
                    throw new \Exception('El flujo no tiene un nodo de inicio');
                }

                // Obtener el ID del nodo inicial (puede ser string o array)
                $startNodeId = is_array($startNode) ? $startNode['id'] : $startNode;

                Log::info('FlujoEjecucion: Nodo inicial desde estructura antigua', [
                    'initial_node_id' => $startNodeId,
                ]);
            }

            // Buscar la primera conexión desde el nodo inicial
            $primeraConexion = collect($branches)->firstWhere('source_node_id', $startNodeId);

            // Si no hay conexión, usar la primera etapa por orden
            if (! $primeraConexion) {
                Log::warning('FlujoEjecucion: No hay conexión desde nodo inicial, usando primera etapa por orden');
                $primeraEtapa = collect($stages)
                    ->sortBy('orden')
                    ->first();

                if (! $primeraEtapa) {
                    throw new \Exception('No se encontró ninguna etapa en el flujo');
                }

                $primeraEtapaId = $primeraEtapa['id'];
            } else {
                $primeraEtapaId = $primeraConexion['target_node_id'];
                $primeraEtapa = collect($stages)->firstWhere('id', $primeraEtapaId);

                if (! $primeraEtapa) {
                    throw new \Exception('No se encontró la primera etapa del flujo');
                }
            }

            Log::info('FlujoEjecucion: Iniciando ejecución', [
                'flujo_id' => $flujo->id,
                'origen_id' => $request->origen_id,
                'prospectos_count' => count($request->prospectos_ids),
                'primera_etapa_id' => $primeraEtapaId,
            ]);

            // Fecha de inicio (ahora o programada)
            $fechaInicioProgramada = $request->fecha_inicio_programada
                ? \Carbon\Carbon::parse($request->fecha_inicio_programada)
                : now();

            // Calcular cuándo ejecutar la primera etapa
            $tiempoEsperaPrimeraEtapa = $primeraEtapa['tiempo_espera'] ?? 0; // días desde el inicio
            $fechaEjecucionPrimeraEtapa = $fechaInicioProgramada->copy()->addDays($tiempoEsperaPrimeraEtapa);

            // Crear ejecución
            $ejecucion = FlujoEjecucion::create([
                'flujo_id' => $flujo->id,
                'origen_id' => $request->origen_id,
                'prospectos_ids' => $request->prospectos_ids,
                'fecha_inicio_programada' => $fechaInicioProgramada,
                'fecha_inicio_real' => now(),
                'estado' => 'in_progress',
                'nodo_actual' => null,
                'proximo_nodo' => $primeraEtapaId,
                'fecha_proximo_nodo' => $fechaEjecucionPrimeraEtapa,
                'config' => [
                    'user_id' => $request->user()->id,
                    'created_from' => 'manual',
                ],
            ]);

            // ✅ CREAR REGISTROS PARA TODAS LAS ETAPAS DEL FLUJO
            // Filtrar solo etapas ejecutables (email, sms, stage, condition, end)
            $etapasEjecutables = collect($stages)->filter(function ($stage) {
                $type = $stage['type'] ?? null;

                return in_array($type, ['email', 'sms', 'stage', 'condition', 'end']);
            });

            // Construir el orden de ejecución siguiendo las conexiones
            $ordenEjecucion = $this->construirOrdenEjecucion($stages, $branches, $primeraEtapaId);

            // Crear registros de etapas en el orden correcto
            $primeraEtapaEjecucion = null;
            $fechaBase = $fechaInicioProgramada->copy();

            foreach ($ordenEjecucion as $index => $stageId) {
                $stage = collect($stages)->firstWhere('id', $stageId);
                if (! $stage) {
                    continue;
                }

                // Calcular fecha programada acumulativa
                $tiempoEspera = $stage['tiempo_espera'] ?? 0;
                $fechaProgramada = $fechaBase->copy()->addDays($tiempoEspera);

                $etapaEjecucion = FlujoEjecucionEtapa::create([
                    'flujo_ejecucion_id' => $ejecucion->id,
                    'etapa_id' => null,
                    'node_id' => $stageId,
                    'fecha_programada' => $fechaProgramada,
                    'estado' => 'pending',
                    'ejecutado' => false,
                ]);

                // Guardar referencia a la primera etapa
                if ($index === 0) {
                    $primeraEtapaEjecucion = $etapaEjecucion;
                }

                // La fecha base para la siguiente etapa es la fecha programada de esta
                $fechaBase = $fechaProgramada->copy();
            }

            Log::info('FlujoEjecucion: Todas las etapas creadas', [
                'ejecucion_id' => $ejecucion->id,
                'total_etapas' => count($ordenEjecucion),
                'primera_etapa_id' => $primeraEtapaEjecucion?->id,
            ]);

            // Verificar que se haya creado al menos una etapa
            if (! $primeraEtapaEjecucion) {
                throw new \Exception('No se pudo crear ninguna etapa ejecutable. Verifica que las etapas del flujo tengan un tipo válido (email, sms, stage, condition, end).');
            }

            // ✅ ARQUITECTURA SIMPLIFICADA: No despachamos jobs con delay
            // El cron EjecutarNodosProgramados es el ÚNICO que ejecuta nodos
            // cuando fecha_proximo_nodo <= now()
            // 
            // Esto elimina:
            // - Race conditions entre batch callbacks y cron
            // - Jobs stuck porque available_at está en el futuro (Cloud Run no tiene worker permanente)
            // - Complejidad de dos sistemas compitiendo
            if ($primeraEtapaEjecucion) {
                // Guardar prospectos_ids en la primera etapa para que el cron los use
                $primeraEtapaEjecucion->update([
                    'prospectos_ids' => $request->prospectos_ids,
                ]);

                // Configurar la ejecución para que el cron la encuentre
                $ejecucion->update([
                    'proximo_nodo' => $primeraEtapa['id'],
                    'fecha_proximo_nodo' => $fechaEjecucionPrimeraEtapa,
                ]);

                Log::info('FlujoEjecucion: Primera etapa configurada para ejecución por cron', [
                    'etapa_id' => $primeraEtapaEjecucion->id,
                    'proximo_nodo' => $primeraEtapa['id'],
                    'fecha_proximo_nodo' => $fechaEjecucionPrimeraEtapa,
                    'prospectos_count' => count($request->prospectos_ids),
                    'sera_ejecutado_inmediatamente' => !$fechaEjecucionPrimeraEtapa->isFuture(),
                ]);
            }

            DB::commit();

            Log::info('FlujoEjecucion: Ejecución creada exitosamente', [
                'ejecucion_id' => $ejecucion->id,
            ]);

            return response()->json([
                'error' => false,
                'mensaje' => 'Ejecución de flujo iniciada exitosamente',
                'data' => [
                    'ejecucion_id' => $ejecucion->id,
                    'estado' => $ejecucion->estado,
                    'fecha_inicio_programada' => $ejecucion->fecha_inicio_programada,
                    'prospectos_count' => count($request->prospectos_ids),
                    'primera_etapa' => [
                        'id' => $primeraEtapaEjecucion->id,
                        'fecha_programada' => $primeraEtapaEjecucion->fecha_programada,
                    ],
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('FlujoEjecucion: Error al crear ejecución', [
                'flujo_id' => $flujo->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => true,
                'mensaje' => 'Error al iniciar la ejecución del flujo',
                'detalle' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lista todas las ejecuciones de un flujo
     *
     * GET /api/flujos/{flujo}/ejecuciones
     */
    public function index(Flujo $flujo): JsonResponse
    {
        $ejecuciones = $flujo->ejecuciones()
            ->with(['etapas', 'condiciones', 'jobs'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'error' => false,
            'data' => $ejecuciones,
        ]);
    }

    /**
     * Obtiene el detalle de una ejecución específica
     *
     * GET /api/flujos/{flujo}/ejecuciones/{ejecucion}
     */
    public function show(Flujo $flujo, FlujoEjecucion $ejecucion): JsonResponse
    {
        // Verificar que la ejecución pertenece al flujo
        if ($ejecucion->flujo_id !== $flujo->id) {
            return response()->json([
                'error' => true,
                'mensaje' => 'La ejecución no pertenece a este flujo',
            ], 404);
        }

        $ejecucion->load(['etapas', 'condiciones', 'jobs']);

        // Obtener estadísticas de envíos por etapa
        $enviosPorEtapa = \DB::table('envios')
            ->select('flujo_ejecucion_etapa_id', 'estado', \DB::raw('count(*) as total'))
            ->whereIn('flujo_ejecucion_etapa_id', $ejecucion->etapas->pluck('id'))
            ->groupBy('flujo_ejecucion_etapa_id', 'estado')
            ->get()
            ->groupBy('flujo_ejecucion_etapa_id');

        // Enriquecer etapas con estadísticas
        $etapasConEstadisticas = $ejecucion->etapas->map(function ($etapa) use ($enviosPorEtapa) {
            $estadisticas = $enviosPorEtapa->get($etapa->id, collect());

            // Conteos por estado
            $enviado = $estadisticas->firstWhere('estado', 'enviado')?->total ?? 0;
            $abierto = $estadisticas->firstWhere('estado', 'abierto')?->total ?? 0;
            $clickeado = $estadisticas->firstWhere('estado', 'clickeado')?->total ?? 0;

            return [
                'id' => $etapa->id,
                'node_id' => $etapa->node_id,
                'estado' => $etapa->estado,
                'ejecutado' => $etapa->ejecutado,
                'fecha_programada' => $etapa->fecha_programada,
                'fecha_ejecucion' => $etapa->fecha_ejecucion,
                'error_mensaje' => $etapa->error_mensaje,
                'envios' => [
                    'pendiente' => $estadisticas->firstWhere('estado', 'pendiente')?->total ?? 0,
                    // Enviado = total de éxitos (enviado + abierto + clickeado)
                    'enviado' => $enviado + $abierto + $clickeado,
                    'fallido' => $estadisticas->firstWhere('estado', 'fallido')?->total ?? 0,
                    'abierto' => $abierto,
                    'clickeado' => $clickeado,
                ],
            ];
        });

        // Calcular progreso general
        $totalEtapas = $ejecucion->etapas->count();
        $etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
        $etapasFallidas = $ejecucion->etapas->where('estado', 'failed')->count();
        $etapasEnEjecucion = $ejecucion->etapas->where('estado', 'executing')->count();

        // Calcular progreso de envíos (más detallado)
        $totalProspectos = count($ejecucion->prospectos_ids ?? []);
        $envioStats = \DB::table('envios')
            ->whereIn('flujo_ejecucion_etapa_id', $ejecucion->etapas->pluck('id'))
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as exitosos,
                SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes
            ")
            ->first();

        $exitosos = $envioStats->exitosos ?? 0;
        $fallidos = $envioStats->fallidos ?? 0;
        $pendientesEnvio = $envioStats->pendientes ?? 0;
        $procesados = $exitosos + $fallidos;

        // Calcular velocidad (envíos creados en última hora)
        $enviosUltimaHora = \DB::table('envios')
            ->whereIn('flujo_ejecucion_etapa_id', $ejecucion->etapas->pluck('id'))
            ->where('created_at', '>=', now()->subHour())
            ->whereIn('estado', ['enviado', 'abierto', 'clickeado', 'fallido'])
            ->count();

        // Tiempo estimado restante
        $restantes = $totalProspectos - $procesados;
        
        // Si hay muy pocos envíos en la última hora pero quedan muchos pendientes,
        // puede ser que el proceso se detuvo o los restantes son prospectos sin email
        $velocidadPorHora = $enviosUltimaHora > 100 ? $enviosUltimaHora : 9000;
        $horasRestantes = $restantes > 0 ? round($restantes / $velocidadPorHora, 1) : 0;
        
        // Determinar texto de tiempo restante
        $tiempoTexto = 'Completado';
        if ($restantes > 0) {
            if ($enviosUltimaHora < 100 && $pendientesEnvio < 100) {
                // Velocidad muy baja y pocos pendientes = probablemente terminó (restantes son prospectos sin email)
                $tiempoTexto = 'Finalizando...';
            } elseif ($horasRestantes < 1) {
                $tiempoTexto = 'Menos de 1 hora';
            } else {
                $tiempoTexto = "{$horasRestantes} horas";
            }
        }

        $progresoEnvios = [
            'total_prospectos' => $totalProspectos,
            'procesados' => $procesados,
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'pendientes' => $pendientesEnvio,
            'porcentaje' => $totalProspectos > 0 ? round(($procesados / $totalProspectos) * 100, 2) : 0,
            'velocidad_por_hora' => $enviosUltimaHora,
            'tiempo_restante_horas' => $horasRestantes,
            'tiempo_restante_texto' => $tiempoTexto,
        ];

        // Construir timeline con orden de ejecución
        $timeline = $ejecucion->etapas
            ->sortBy('created_at')
            ->values()
            ->map(function ($etapa, $index) {
                $fechaInicio = $etapa->estado === 'pending' ? null : ($etapa->fecha_ejecucion ?? $etapa->updated_at);
                $fechaFin = in_array($etapa->estado, ['completed', 'failed']) ? $etapa->updated_at : null;

                $duracionSegundos = null;
                if ($fechaInicio && $fechaFin) {
                    $duracionSegundos = $fechaInicio->diffInSeconds($fechaFin);
                } elseif ($fechaInicio && $etapa->estado === 'executing') {
                    $duracionSegundos = $fechaInicio->diffInSeconds(now());
                }

                return [
                    'node_id' => $etapa->node_id,
                    'orden_ejecucion' => $index + 1,
                    'estado' => $etapa->estado,
                    'fecha_inicio' => $fechaInicio?->toIso8601String(),
                    'fecha_fin' => $fechaFin?->toIso8601String(),
                    'duracion_segundos' => $duracionSegundos,
                ];
            });

        // Identificar nodo actual y próximo nodo
        $etapaActual = $ejecucion->etapas->firstWhere('estado', 'executing');
        $nodoActual = $etapaActual?->node_id;

        // Obtener próximo nodo desde flujo_data
        $proximoNodo = null;
        if ($nodoActual) {
            $flujoData = $ejecucion->flujo->flujo_data;
            $branches = $flujoData['branches'] ?? [];

            $proximaConexion = collect($branches)->firstWhere('source_node_id', $nodoActual);
            $proximoNodo = $proximaConexion['target_node_id'] ?? null;
        }

        // Obtener condiciones evaluadas
        $condicionesEvaluadas = $ejecucion->condiciones->map(function ($condicion) {
            return [
                'node_id' => $condicion->node_id,
                'resultado' => $condicion->resultado,
                'proxima_etapa_node_id' => $condicion->target_node_id,
                'fecha_evaluacion' => $condicion->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'error' => false,
            'data' => [
                'id' => $ejecucion->id,
                'flujo_id' => $ejecucion->flujo_id,
                'estado' => $ejecucion->estado,
                'fecha_inicio_programada' => $ejecucion->fecha_inicio_programada,
                'fecha_inicio_real' => $ejecucion->fecha_inicio_real,
                'fecha_fin' => $ejecucion->fecha_fin,
                'error_message' => $ejecucion->error_message,
                'prospectos_ids' => $ejecucion->prospectos_ids,
                'progreso' => [
                    'total_etapas' => $totalEtapas,
                    'completadas' => $etapasCompletadas,
                    'fallidas' => $etapasFallidas,
                    'en_ejecucion' => $etapasEnEjecucion,
                    'pendientes' => $totalEtapas - $etapasCompletadas - $etapasFallidas - $etapasEnEjecucion,
                    'porcentaje' => $totalEtapas > 0 ? round(($etapasCompletadas / $totalEtapas) * 100, 2) : 0,
                ],
                'progreso_envios' => $progresoEnvios,
                'etapas' => $etapasConEstadisticas,
                'timeline' => $timeline,
                'nodo_actual' => $nodoActual,
                'proximo_nodo' => $proximoNodo,
                'condiciones_evaluadas' => $condicionesEvaluadas,
                'jobs' => $ejecucion->jobs,
                'created_at' => $ejecucion->created_at,
                'updated_at' => $ejecucion->updated_at,
            ],
        ]);
    }

    /**
     * Obtiene la ejecución activa de un flujo (in_progress o paused)
     *
     * GET /api/flujos/{flujo}/ejecuciones/activa
     */
    public function getActiveExecution(Flujo $flujo): JsonResponse
    {
        $ejecucion = FlujoEjecucion::where('flujo_id', $flujo->id)
            ->whereIn('estado', ['in_progress', 'paused'])
            ->with(['etapas' => function ($query) {
                $query->orderBy('fecha_programada', 'asc');
            }])
            ->first();

        $tieneActiva = $ejecucion !== null;

        // Obtener estadísticas de envíos por etapa si hay ejecución activa
        $enviosPorEtapa = collect();
        if ($ejecucion && $ejecucion->etapas->isNotEmpty()) {
            $enviosPorEtapa = \DB::table('envios')
                ->select('flujo_ejecucion_etapa_id', 'estado', \DB::raw('count(*) as total'))
                ->whereIn('flujo_ejecucion_etapa_id', $ejecucion->etapas->pluck('id'))
                ->groupBy('flujo_ejecucion_etapa_id', 'estado')
                ->get()
                ->groupBy('flujo_ejecucion_etapa_id');
        }

        // Calcular progreso si hay ejecución activa
        $progreso = null;
        $progresoEnvios = null;
        if ($ejecucion) {
            $totalEtapas = $ejecucion->etapas->count();
            $etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
            $etapasFallidas = $ejecucion->etapas->where('estado', 'failed')->count();
            $etapasEnEjecucion = $ejecucion->etapas->where('estado', 'executing')->count();
            $etapasPendientes = $totalEtapas - $etapasCompletadas - $etapasFallidas - $etapasEnEjecucion;

            $progreso = [
                'porcentaje' => $totalEtapas > 0 ? round(($etapasCompletadas / $totalEtapas) * 100, 2) : 0,
                'completadas' => $etapasCompletadas,
                'total' => $totalEtapas,
                'en_ejecucion' => $etapasEnEjecucion,
                'pendientes' => $etapasPendientes,
                'fallidas' => $etapasFallidas,
            ];

            // Calcular progreso de envíos basado en la etapa en ejecución
            $totalProspectos = count($ejecucion->prospectos_ids ?? []);
            
            // Buscar la etapa actualmente en ejecución
            $etapaEnEjecucion = $ejecucion->etapas->firstWhere('estado', 'executing');
            
            // Si hay una etapa ejecutando, mostrar progreso de ESA etapa
            // Si no, mostrar el progreso general (todas las etapas completadas)
            if ($etapaEnEjecucion) {
                $envioStats = \DB::table('envios')
                    ->where('flujo_ejecucion_etapa_id', $etapaEnEjecucion->id)
                    ->selectRaw("
                        COUNT(*) as total,
                        SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as exitosos,
                        SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos,
                        SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes
                    ")
                    ->first();
                
                $baseProspectos = $totalProspectos; // La etapa actual envía a todos los prospectos del flujo
            } else {
                // No hay etapa ejecutando - mostrar totales de etapas completadas
                $envioStats = \DB::table('envios')
                    ->whereIn('flujo_ejecucion_etapa_id', $ejecucion->etapas->pluck('id'))
                    ->selectRaw("
                        COUNT(*) as total,
                        SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as exitosos,
                        SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos,
                        SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes
                    ")
                    ->first();
                
                // Base = prospectos × etapas completadas (para que el % tenga sentido)
                $etapasCompletadasCount = $ejecucion->etapas->whereIn('estado', ['completed', 'executing'])->count();
                $baseProspectos = $totalProspectos * max(1, $etapasCompletadasCount);
            }

            $exitosos = $envioStats->exitosos ?? 0;
            $fallidos = $envioStats->fallidos ?? 0;
            $pendientesEnvio = $envioStats->pendientes ?? 0;
            $procesados = $exitosos + $fallidos;

            // Calcular velocidad (envíos de la etapa actual en última hora)
            $etapaIdsParaVelocidad = $etapaEnEjecucion 
                ? [$etapaEnEjecucion->id] 
                : $ejecucion->etapas->pluck('id')->toArray();
            
            $enviosUltimaHora = \DB::table('envios')
                ->whereIn('flujo_ejecucion_etapa_id', $etapaIdsParaVelocidad)
                ->where('created_at', '>=', now()->subHour())
                ->whereIn('estado', ['enviado', 'abierto', 'clickeado', 'fallido'])
                ->count();

            // Tiempo estimado restante (basado en la etapa actual)
            $restantes = $baseProspectos - $procesados;
            
            // Si hay muy pocos envíos en la última hora pero quedan muchos pendientes,
            // puede ser que el proceso se detuvo o los restantes son prospectos sin email
            $velocidadPorHora = $enviosUltimaHora > 100 ? $enviosUltimaHora : 9000;
            $horasRestantes = $restantes > 0 ? round($restantes / $velocidadPorHora, 1) : 0;
            
            // Determinar texto de tiempo restante
            $tiempoTexto = 'Completado';
            if ($restantes > 0) {
                if ($enviosUltimaHora < 100 && $pendientesEnvio < 100) {
                    // Velocidad muy baja y pocos pendientes = probablemente terminó (restantes son prospectos sin email)
                    $tiempoTexto = 'Finalizando...';
                } elseif ($horasRestantes < 1) {
                    $tiempoTexto = 'Menos de 1 hora';
                } else {
                    $tiempoTexto = "{$horasRestantes} horas";
                }
            }

            $progresoEnvios = [
                'total_prospectos' => $baseProspectos,
                'procesados' => $procesados,
                'exitosos' => $exitosos,
                'fallidos' => $fallidos,
                'pendientes' => $pendientesEnvio,
                'porcentaje' => $baseProspectos > 0 ? round(($procesados / $baseProspectos) * 100, 2) : 0,
                'velocidad_por_hora' => $enviosUltimaHora,
                'tiempo_restante_horas' => $horasRestantes,
                'tiempo_restante_texto' => $tiempoTexto,
                'etapa_actual' => $etapaEnEjecucion ? $etapaEnEjecucion->node_id : null,
            ];
        }

        return response()->json([
            'tiene_ejecucion_activa' => $tieneActiva,
            'ejecucion' => $ejecucion ? [
                'id' => $ejecucion->id,
                'flujo_id' => $ejecucion->flujo_id,
                'origen_id' => $ejecucion->origen_id,
                'estado' => $ejecucion->estado,
                'nodo_actual' => $ejecucion->nodo_actual,
                'proximo_nodo' => $ejecucion->proximo_nodo,
                'fecha_proximo_nodo' => $ejecucion->fecha_proximo_nodo,
                'fecha_inicio_programada' => $ejecucion->fecha_inicio_programada,
                'fecha_inicio_real' => $ejecucion->fecha_inicio_real,
                'fecha_fin' => $ejecucion->fecha_fin,
                'prospectos_ids' => $ejecucion->prospectos_ids,
                'error_message' => $ejecucion->error_message,
                'progreso' => $progreso,
                'progreso_envios' => $progresoEnvios,
                'etapas' => $ejecucion->etapas->map(function ($etapa) use ($enviosPorEtapa) {
                    $estadisticas = $enviosPorEtapa->get($etapa->id, collect());

                    // Conteos por estado
                    $enviado = $estadisticas->firstWhere('estado', 'enviado')?->total ?? 0;
                    $abierto = $estadisticas->firstWhere('estado', 'abierto')?->total ?? 0;
                    $clickeado = $estadisticas->firstWhere('estado', 'clickeado')?->total ?? 0;

                    return [
                        'id' => $etapa->id,
                        'node_id' => $etapa->node_id,
                        'estado' => $etapa->estado,
                        'ejecutado' => $etapa->ejecutado,
                        'fecha_programada' => $etapa->fecha_programada,
                        'fecha_ejecucion' => $etapa->fecha_ejecucion,
                        'envios' => [
                            'pendiente' => $estadisticas->firstWhere('estado', 'pendiente')?->total ?? 0,
                            // Enviado = total de éxitos (enviado + abierto + clickeado)
                            'enviado' => $enviado + $abierto + $clickeado,
                            'fallido' => $estadisticas->firstWhere('estado', 'fallido')?->total ?? 0,
                            'abierto' => $abierto,
                            'clickeado' => $clickeado,
                        ],
                    ];
                }),
                'created_at' => $ejecucion->created_at,
                'updated_at' => $ejecucion->updated_at,
            ] : null,
        ]);
    }

    /**
     * Pausa una ejecución en progreso
     *
     * POST /api/flujos/{flujo}/ejecuciones/{ejecucion}/pausar
     */
    public function pause(Flujo $flujo, FlujoEjecucion $ejecucion): JsonResponse
    {
        if ($ejecucion->flujo_id !== $flujo->id) {
            return response()->json([
                'error' => true,
                'mensaje' => 'La ejecución no pertenece a este flujo',
            ], 404);
        }

        if ($ejecucion->estado !== 'in_progress') {
            return response()->json([
                'error' => true,
                'mensaje' => 'Solo se pueden pausar ejecuciones en progreso',
            ], 422);
        }

        $ejecucion->update(['estado' => 'paused']);

        Log::info('FlujoEjecucion: Ejecución pausada', [
            'ejecucion_id' => $ejecucion->id,
        ]);

        return response()->json([
            'error' => false,
            'mensaje' => 'Ejecución pausada exitosamente',
            'data' => $ejecucion,
        ]);
    }

    /**
     * Reanuda una ejecución pausada
     *
     * POST /api/flujos/{flujo}/ejecuciones/{ejecucion}/reanudar
     */
    public function resume(Flujo $flujo, FlujoEjecucion $ejecucion): JsonResponse
    {
        if ($ejecucion->flujo_id !== $flujo->id) {
            return response()->json([
                'error' => true,
                'mensaje' => 'La ejecución no pertenece a este flujo',
            ], 404);
        }

        if ($ejecucion->estado !== 'paused') {
            return response()->json([
                'error' => true,
                'mensaje' => 'Solo se pueden reanudar ejecuciones pausadas',
            ], 422);
        }

        $ejecucion->update(['estado' => 'in_progress']);

        Log::info('FlujoEjecucion: Ejecución reanudada', [
            'ejecucion_id' => $ejecucion->id,
        ]);

        return response()->json([
            'error' => false,
            'mensaje' => 'Ejecución reanudada exitosamente',
            'data' => $ejecucion,
        ]);
    }

    /**
     * Cancela una ejecución
     *
     * DELETE /api/flujos/{flujo}/ejecuciones/{ejecucion}
     */
    public function destroy(Flujo $flujo, FlujoEjecucion $ejecucion): JsonResponse
    {
        if ($ejecucion->flujo_id !== $flujo->id) {
            return response()->json([
                'error' => true,
                'mensaje' => 'La ejecución no pertenece a este flujo',
            ], 404);
        }

        if (in_array($ejecucion->estado, ['completed', 'failed'])) {
            return response()->json([
                'error' => true,
                'mensaje' => 'No se pueden cancelar ejecuciones completadas o fallidas',
            ], 422);
        }

        $ejecucion->update(['estado' => 'failed', 'error_message' => 'Cancelada por el usuario']);

        Log::info('FlujoEjecucion: Ejecución cancelada', [
            'ejecucion_id' => $ejecucion->id,
        ]);

        return response()->json([
            'error' => false,
            'mensaje' => 'Ejecución cancelada exitosamente',
        ]);
    }

    /**
     * Construye el orden de ejecución de etapas siguiendo las conexiones
     *
     * @param  array  $stages  Todas las etapas del flujo
     * @param  array  $branches  Conexiones entre etapas
     * @param  string  $primeraEtapaId  ID de la primera etapa
     * @return array Array de IDs de etapas en orden de ejecución
     */
    private function construirOrdenEjecucion(array $stages, array $branches, string $primeraEtapaId): array
    {
        $orden = [];
        $visitados = [];
        $nodoActual = $primeraEtapaId;

        Log::info('construirOrdenEjecucion: Iniciando', [
            'primera_etapa_id' => $primeraEtapaId,
            'total_stages' => count($stages),
            'total_branches' => count($branches),
        ]);

        // Recorrer el flujo siguiendo las conexiones
        while ($nodoActual && ! in_array($nodoActual, $visitados)) {
            // Verificar que el nodo sea una etapa ejecutable
            $stage = collect($stages)->firstWhere('id', $nodoActual);

            if ($stage) {
                $type = $stage['type'] ?? null;

                Log::info('construirOrdenEjecucion: Procesando nodo', [
                    'node_id' => $nodoActual,
                    'type' => $type,
                    'stage_keys' => array_keys($stage),
                ]);

                if (in_array($type, ['email', 'sms', 'stage', 'condition', 'end'])) {
                    $orden[] = $nodoActual;
                    Log::info('construirOrdenEjecucion: Nodo agregado al orden', ['node_id' => $nodoActual]);
                } else {
                    Log::warning('construirOrdenEjecucion: Nodo ignorado - tipo no válido', [
                        'node_id' => $nodoActual,
                        'type' => $type,
                    ]);
                }
            } else {
                Log::warning('construirOrdenEjecucion: Nodo no encontrado en stages', [
                    'node_id' => $nodoActual,
                ]);
            }

            $visitados[] = $nodoActual;

            // Buscar siguiente conexión
            $siguienteConexion = collect($branches)->firstWhere('source_node_id', $nodoActual);
            $nodoActual = $siguienteConexion['target_node_id'] ?? null;
        }

        Log::info('construirOrdenEjecucion: Completado', [
            'total_orden' => count($orden),
            'orden' => $orden,
        ]);

        return $orden;
    }
}
