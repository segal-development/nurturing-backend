<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Services\EnvioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EjecutarNodosProgramados implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     * CRÍTICO: Previene que transacciones queden colgadas si Cloud Run mata la instancia.
     */
    public $timeout = 60;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public $failOnTimeout = true;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * Este job se ejecuta periódicamente (cada minuto) y:
     * 1. Busca ejecuciones activas con nodos programados listos para ejecutar
     * 2. Ejecuta cada nodo (envía email/SMS)
     * 3. Actualiza el estado de la ejecución
     * 4. Programa el siguiente nodo si existe
     */
    public function handle(EnvioService $envioService): void
    {
        Log::debug('EjecutarNodosProgramados: Iniciando verificación');

        // ✅ PASO 1: Verificar etapas en 'executing' que pueden haber terminado
        // Esto es CRÍTICO para volúmenes grandes que no tienen callback
        $this->verificarEtapasEjecutando();

        // PASO 2: Obtener ejecuciones con nodos programados listos para ejecutar
        $query = FlujoEjecucion::conNodosProgramados();

        // Apply throttling if enabled
        $throttleEnabled = config('nurturing.throttle.enabled', true);
        $maxPerCycle = config('nurturing.throttle.max_etapas_per_minute', 100);
        $totalReady = $query->count();

        if ($throttleEnabled && $totalReady > $maxPerCycle) {
            $ejecuciones = $query->limit($maxPerCycle)->get();

            Log::warning('EjecutarNodosProgramados: Throttling active', [
                'total_ready' => $totalReady,
                'processing' => $ejecuciones->count(),
                'deferred' => $totalReady - $ejecuciones->count(),
                'max_per_cycle' => $maxPerCycle,
            ]);
        } else {
            $ejecuciones = $query->get();
        }

        if ($ejecuciones->isEmpty()) {
            Log::debug('EjecutarNodosProgramados: No hay nodos programados listos');

            return;
        }

        Log::info('EjecutarNodosProgramados: Encontradas ejecuciones con nodos programados', [
            'cantidad' => $ejecuciones->count(),
            'throttled' => $throttleEnabled && $totalReady > $maxPerCycle,
        ]);

        foreach ($ejecuciones as $ejecucion) {
            try {
                $this->ejecutarProximoNodo($ejecucion, $envioService);
            } catch (\Exception $e) {
                Log::error('EjecutarNodosProgramados: Error al ejecutar nodo', [
                    'ejecucion_id' => $ejecucion->id,
                    'error' => $e->getMessage(),
                ]);

                // Marcar ejecución como fallida
                $ejecucion->update([
                    'estado' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        Log::info('EjecutarNodosProgramados: Verificación completada');
    }

    /**
     * Verifica etapas en 'executing' que pueden haber terminado de procesar.
     *
     * CRÍTICO para volúmenes grandes (large_volume_chunked) que no tienen
     * callback global - el cron debe detectar cuando terminaron.
     */
    /**
     * Verifica etapas en estado 'executing' para detectar si ya terminaron.
     * Optimizado: 1 query con eager loading en lugar de N+1 queries.
     */
    private function verificarEtapasEjecutando(): void
    {
        // 1 sola query: traer ejecuciones con sus etapas en executing (eager loaded)
        $ejecucionesConEtapasEjecutando = FlujoEjecucion::where('estado', 'in_progress')
            ->whereHas('etapas', fn ($q) => $q->where('estado', 'executing'))
            ->with(['etapas' => fn ($q) => $q->where('estado', 'executing')])
            ->get();

        if ($ejecucionesConEtapasEjecutando->isEmpty()) {
            return;
        }

        Log::debug('EjecutarNodosProgramados: Verificando etapas en executing', [
            'cantidad' => $ejecucionesConEtapasEjecutando->count(),
        ]);

        foreach ($ejecucionesConEtapasEjecutando as $ejecucion) {
            // Las etapas ya vienen cargadas por el eager loading
            foreach ($ejecucion->etapas as $etapa) {
                $this->verificarSiEtapaTermino($etapa, $ejecucion);
            }
        }
    }

    /**
     * Verifica si una etapa en 'executing' ya terminó de procesar todos sus envíos.
     */
    private function verificarSiEtapaTermino(FlujoEjecucionEtapa $etapa, FlujoEjecucion $ejecucion): void
    {
        $batchInfo = $etapa->response_athenacampaign ?? [];
        $esVolumenGrande = isset($batchInfo['modo']) && $batchInfo['modo'] === 'large_volume_chunked';

        if ($esVolumenGrande) {
            $this->verificarYCompletarEtapaVolumenGrande($etapa, $ejecucion, $batchInfo);
        } else {
            // Para volúmenes normales, verificar por envíos
            $this->verificarYCompletarEtapaPorEnvios($etapa, $ejecucion);
        }
    }

    /**
     * Ejecuta el próximo nodo de una ejecución
     *
     * IMPORTANTE: NO usamos transacciones largas aquí.
     * Cada operación es atómica para evitar locks si Cloud Run mata la instancia.
     */
    private function ejecutarProximoNodo(FlujoEjecucion $ejecucion, EnvioService $envioService): void
    {
        // ✅ VERIFICACIÓN CRÍTICA: No ejecutar si hay etapas anteriores en 'executing'
        // Esto previene que el cron avance mientras un batch sigue procesando
        $etapasEnEjecucion = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('estado', 'executing')
            ->where('node_id', '!=', $ejecucion->proximo_nodo) // No contar el nodo actual
            ->first();

        if ($etapasEnEjecucion) {
            // Verificar si la etapa lleva mucho tiempo en 'executing' (posible stuck)
            $tiempoEnEjecucion = now()->diffInMinutes($etapasEnEjecucion->fecha_ejecucion ?? $etapasEnEjecucion->created_at);

            // ✅ Para volúmenes grandes, verificar más frecuentemente si ya terminó
            $batchInfo = $etapasEnEjecucion->response_athenacampaign ?? [];
            $esVolumenGrande = isset($batchInfo['modo']) && $batchInfo['modo'] === 'large_volume_chunked';

            // Tiempo mínimo antes de verificar: 10 min para volumen grande, 30 min para normal
            $tiempoMinimoStuck = $esVolumenGrande ? 10 : 30;

            if ($tiempoEnEjecucion > $tiempoMinimoStuck) {
                // Etapa posiblemente stuck - intentar recuperar
                Log::warning('EjecutarNodosProgramados: Etapa anterior posiblemente stuck, verificando', [
                    'ejecucion_id' => $ejecucion->id,
                    'etapa_stuck_id' => $etapasEnEjecucion->id,
                    'node_id' => $etapasEnEjecucion->node_id,
                    'minutos_en_executing' => $tiempoEnEjecucion,
                    'es_volumen_grande' => $esVolumenGrande,
                ]);

                $this->recuperarEtapaStuck($etapasEnEjecucion, $ejecucion);

                return;
            }

            Log::debug('EjecutarNodosProgramados: Esperando que etapa anterior complete', [
                'ejecucion_id' => $ejecucion->id,
                'etapa_en_ejecucion' => $etapasEnEjecucion->node_id,
                'proximo_nodo' => $ejecucion->proximo_nodo,
                'minutos_en_executing' => $tiempoEnEjecucion,
                'es_volumen_grande' => $esVolumenGrande,
            ]);

            return;
        }

        $flujo = $ejecucion->flujo;
        $flujoData = $flujo->flujo_data;
        $stages = $flujoData['stages'] ?? [];
        $conditions = $flujoData['conditions'] ?? [];
        $branches = $flujoData['branches'] ?? [];

        // ✅ NORMALIZAR: Convertir edges a branches si es necesario
        if (empty($branches) && isset($flujoData['edges']) && ! empty($flujoData['edges'])) {
            $branches = collect($flujoData['edges'])->map(function ($edge) {
                return [
                    'source_node_id' => $edge['source'] ?? null,
                    'target_node_id' => $edge['target'] ?? null,
                    'source_handle' => $edge['sourceHandle'] ?? null,
                ];
            })->filter(function ($branch) {
                return ! empty($branch['source_node_id']) && ! empty($branch['target_node_id']);
            })->values()->toArray();
        }

        // Obtener el nodo que se debe ejecutar
        $nodoId = $ejecucion->proximo_nodo;

        // Buscar en stages primero, luego en conditions
        $stage = collect($stages)->firstWhere('id', $nodoId);

        if (! $stage) {
            // Buscar en conditions si no está en stages
            $stage = collect($conditions)->firstWhere('id', $nodoId);
        }

        if (! $stage) {
            throw new \Exception("No se encontró el nodo {$nodoId} en el flujo");
        }

        Log::debug('EjecutarNodosProgramados: Ejecutando nodo', [
            'ejecucion_id' => $ejecucion->id,
            'nodo_id' => $nodoId,
            'tipo' => $stage['type'] ?? 'unknown',
        ]);

        // Verificar si ya existe una etapa para este nodo
        $etapaExistente = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $nodoId)
            ->first();

        // Si la etapa ya está ejecutada o en proceso, NO volver a ejecutar
        if ($etapaExistente && ($etapaExistente->ejecutado || in_array($etapaExistente->estado, ['executing', 'completed']))) {
            Log::debug('EjecutarNodosProgramados: Nodo ya fue ejecutado o está en proceso, saltando', [
                'ejecucion_id' => $ejecucion->id,
                'nodo_id' => $nodoId,
                'estado_etapa' => $etapaExistente->estado,
                'ejecutado' => $etapaExistente->ejecutado,
            ]);

            // ✅ FIX: Si la etapa está en 'executing', NO avanzar al siguiente nodo todavía
            // Esperar a que termine (el cron de recuperación lo manejará)
            if ($etapaExistente->estado === 'executing') {
                Log::debug('EjecutarNodosProgramados: Etapa en executing, esperando', [
                    'ejecucion_id' => $ejecucion->id,
                    'nodo_id' => $nodoId,
                ]);

                return;
            }

            // Solo avanzar si la etapa está 'completed'
            if ($etapaExistente->estado === 'completed') {
                $this->programarSiguienteNodo($ejecucion, $stage['id'], $branches);
            }

            return;
        }

        if (! $etapaExistente) {
            // Usar firstOrCreate para evitar race conditions con otros jobs
            $etapaExistente = FlujoEjecucionEtapa::firstOrCreate(
                [
                    'flujo_ejecucion_id' => $ejecucion->id,
                    'node_id' => $nodoId,
                ],
                [
                    'fecha_programada' => now(),
                    'estado' => 'pending',
                    'ejecutado' => false,
                ]
            );
        }

        // Actualizar nodo actual en la ejecución (operación atómica)
        $ejecucion->update(['nodo_actual' => $nodoId]);

        // Ejecutar el nodo según su tipo
        // ✅ NORMALIZAR: Detectar tipo desde 'type' o 'tipo_mensaje'
        $tipoNodo = $stage['type'] ?? null;

        // Si no hay 'type', intentar inferir desde otros campos
        if (! $tipoNodo) {
            if (isset($stage['tipo_mensaje'])) {
                // Es una etapa de envío (email/sms)
                $tipoNodo = 'stage';
            } elseif (isset($stage['check_param'])) {
                // Es una condición
                $tipoNodo = 'condition';
            }
        }

        if (in_array($tipoNodo, ['email', 'sms', 'stage', 'ambos'])) {
            $this->ejecutarNodoEnvio($ejecucion, $etapaExistente, $stage, $branches, $envioService);
        } elseif ($tipoNodo === 'condition') {
            $this->ejecutarNodoCondicion($ejecucion, $etapaExistente, $stage, $branches);
        } elseif ($tipoNodo === 'end') {
            $this->ejecutarNodoFin($ejecucion, $etapaExistente);
        } else {
            Log::warning('EjecutarNodosProgramados: Tipo de nodo no reconocido', [
                'nodo_id' => $nodoId,
                'tipo_detectado' => $tipoNodo,
                'stage_keys' => array_keys($stage),
            ]);
        }
    }

    /**
     * Ejecuta un nodo de envío (email o SMS)
     *
     * ✅ ARQUITECTURA PARA ENVÍOS MASIVOS:
     * En lugar de llamar a EnvioService directamente (que hace foreach síncrono),
     * despachamos EnviarEtapaJob que tiene:
     * - Batching para procesar en paralelo
     * - Rate limiting para no saturar SMTP
     * - Timeout apropiado para volúmenes grandes (350k+)
     *
     * El cron solo ORQUESTA, no ejecuta envíos directamente.
     */
    private function ejecutarNodoEnvio(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapa,
        array $stage,
        array $branches,
        EnvioService $envioService
    ): void {
        // Marcar etapa como ejecutando
        $etapa->update([
            'estado' => 'executing',
            'fecha_ejecucion' => now(),
        ]);

        $prospectoIds = $etapa->prospectos()->pluck('prospectos.id')->toArray();
        $usaProspectosEtapa = ! empty($prospectoIds);
        if (empty($prospectoIds)) {
            $prospectoIds = $ejecucion->prospectos()->pluck('prospectos.id')->toArray();
        }

        Log::debug('EjecutarNodosProgramados: Despachando EnviarEtapaJob', [
            'ejecucion_id' => $ejecucion->id,
            'etapa_id' => $etapa->id,
            'stage_id' => $stage['id'] ?? 'unknown',
            'usa_prospectos_etapa' => $usaProspectosEtapa,
            'total_prospectos' => count($prospectoIds),
        ]);

        // ✅ Despachar job con batching en lugar de envío síncrono
        // EnviarEtapaJob maneja:
        // - Creación de batch de jobs individuales
        // - Rate limiting via RateLimitedMiddleware
        // - Callbacks para marcar etapa como completed
        // - Programación del siguiente nodo
        EnviarEtapaJob::dispatch(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapa->id,
            stage: $stage,
            prospectoIds: $prospectoIds,
            branches: $branches
        );

        Log::debug('EjecutarNodosProgramados: EnviarEtapaJob despachado', [
            'ejecucion_id' => $ejecucion->id,
            'etapa_id' => $etapa->id,
        ]);

        // NOTA: NO programamos siguiente nodo aquí
        // EnviarEtapaJob lo hace en su callback onBatchCompleted
    }

    /**
     * Ejecuta un nodo de condición
     */
    private function ejecutarNodoCondicion(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapa,
        array $stage,
        array $branches
    ): void {
        // ✅ VERIFICACIÓN: La etapa de email anterior debe estar COMPLETADA
        // Buscar la etapa de email que conecta con esta condición
        $conexionHaciaCondicion = collect($branches)->first(function ($branch) use ($stage) {
            return $branch['target_node_id'] === $stage['id'];
        });

        if ($conexionHaciaCondicion) {
            $nodoEmailAnteriorId = $conexionHaciaCondicion['source_node_id'];
            $etapaEmailDirecta = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
                ->where('node_id', $nodoEmailAnteriorId)
                ->first();

            if ($etapaEmailDirecta && $etapaEmailDirecta->estado === 'executing') {
                Log::debug('EjecutarNodosProgramados: Condición esperando email anterior', [
                    'ejecucion_id' => $ejecucion->id,
                    'condicion_node_id' => $stage['id'],
                    'email_node_id' => $nodoEmailAnteriorId,
                    'email_estado' => $etapaEmailDirecta->estado,
                ]);

                // No ejecutar la condición todavía - el email aún está procesando
                // El nodo se volverá a intentar en la próxima ejecución del cron
                return;
            }
        }

        // ✅ PRIORIDAD 1: Usar source_message_id guardado por EnviarEtapaJob
        // (cuando la condición fue programada desde el batch callback)
        $responseData = $etapa->response_athenacampaign ?? [];
        $messageId = $responseData['source_message_id'] ?? null;

        if ($messageId) {
            Log::debug('EjecutarNodosProgramados: Usando source_message_id', [
                'message_id' => $messageId,
                'etapa_id' => $etapa->id,
            ]);
        } else {
            // ✅ PRIORIDAD 2: Buscar message_id de la etapa de email anterior completada
            $etapaEmailAnterior = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
                ->whereNotNull('message_id')
                ->where('ejecutado', true)
                ->orderBy('fecha_ejecucion', 'desc')
                ->first();

            if (! $etapaEmailAnterior || ! $etapaEmailAnterior->message_id) {
                Log::error('EjecutarNodosProgramados: No se encontró etapa de email anterior con message_id', [
                    'ejecucion_id' => $ejecucion->id,
                    'nodo_id' => $stage['id'],
                    'etapas_completadas' => FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
                        ->where('ejecutado', true)
                        ->pluck('node_id', 'estado')
                        ->toArray(),
                ]);

                // Marcar etapa como fallida
                $etapa->update([
                    'estado' => 'failed',
                    'ejecutado' => true,
                    'fecha_ejecucion' => now(),
                ]);

                return;
            }

            $messageId = (int) $etapaEmailAnterior->message_id;

            Log::debug('EjecutarNodosProgramados: Usando message_id de etapa anterior', [
                'message_id' => $messageId,
                'etapa_email_id' => $etapaEmailAnterior->id,
                'etapa_email_node_id' => $etapaEmailAnterior->node_id,
            ]);

            // ✅ Guardar source_etapa_id para que VerificarCondicionJob lo use
            $etapa->update([
                'response_athenacampaign' => array_merge($responseData, [
                    'source_message_id' => $messageId,
                    'source_etapa_id' => $etapaEmailAnterior->id,
                ]),
            ]);
            $responseData = $etapa->fresh()->response_athenacampaign;
        }

        $prospectoIds = $etapa->prospectos()->pluck('prospectos.id')->toArray();
        if (empty($prospectoIds)) {
            $prospectoIds = $ejecucion->prospectos()->pluck('prospectos.id')->toArray();
        }

        Log::debug('EjecutarNodosProgramados: Preparando evaluación de condición', [
            'message_id' => $messageId,
            'prospectos_count' => count($prospectoIds),
        ]);

        // ✅ PRIORIDAD 1: Usar conexión guardada por EnviarEtapaJob
        // PRIORIDAD 2: Construir desde el stage
        $condicionGuardada = $responseData['conexion'] ?? null;

        if ($condicionGuardada) {
            $condicion = $condicionGuardada;
            // Asegurar que tenga los datos de la condición
            if (! isset($condicion['data'])) {
                $condicion['data'] = [
                    'check_param' => $stage['check_param'] ?? 'Views',
                    'check_operator' => $stage['check_operator'] ?? '>',
                    'check_value' => $stage['check_value'] ?? '0',
                ];
            }
        } else {
            // Construir el array $condicion con el formato esperado por VerificarCondicionJob
            $condicion = [
                'target_node_id' => $stage['id'],
                'source_node_id' => $conexionHaciaCondicion['source_node_id'] ?? null,
                'data' => [
                    'check_param' => $stage['check_param'] ?? 'Views',
                    'check_operator' => $stage['check_operator'] ?? '>',
                    'check_value' => $stage['check_value'] ?? '0',
                ],
            ];
        }

        // Marcar etapa como en proceso (se completará en VerificarCondicionJob)
        $etapa->update([
            'estado' => 'executing',
            'fecha_ejecucion' => now(),
        ]);

        // ✅ Despachar job para evaluar condición con prospectos filtrados
        VerificarCondicionJob::dispatch(
            $ejecucion->id,
            $etapa->id,
            $condicion,
            $messageId,
            $prospectoIds  // Pasar prospectos a evaluar
        );

        Log::debug('EjecutarNodosProgramados: Condición despachada', [
            'ejecucion_id' => $ejecucion->id,
            'etapa_ejecucion_id' => $etapa->id,
            'nodo_id' => $stage['id'],
            'message_id' => $messageId,
            'prospectos_count' => count($prospectoIds),
        ]);
    }

    /**
     * Ejecuta un nodo final
     */
    private function ejecutarNodoFin(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapa): void
    {
        $etapa->update([
            'estado' => 'completed',
            'ejecutado' => true,
            'fecha_ejecucion' => now(),
        ]);

        $this->finalizarEjecucion($ejecucion);
    }

    /**
     * Finaliza una ejecución respetando flujos perpetuos.
     *
     * Para flujos PERPETUOS nunca se marca 'completed' (eso deja la ejecución
     * muerta y CatchUpProspectosJob deja de procesarla, dejando a los nuevos
     * prospectos sin nutrir): queda 'waiting' esperando nuevos prospectos.
     * Solo los flujos normales se completan.
     *
     * Centraliza la lógica que antes estaba repetida e inconsistente en este
     * job (ejecutarNodoFin, recuperación, programarSiguienteNodo): 4 de los 5
     * caminos NO respetaban es_perpetuo y re-mataban la ejecución perpetua.
     */
    private function finalizarEjecucion(FlujoEjecucion $ejecucion): void
    {
        $esPerpetuo = $ejecucion->es_perpetuo || ($ejecucion->flujo?->es_perpetuo ?? false);

        if ($esPerpetuo) {
            $ejecucion->update([
                'estado' => 'waiting',
                'proximo_nodo' => null,
                'fecha_proximo_nodo' => null,
            ]);

            Log::info('EjecutarNodosProgramados: Flujo perpetuo, ejecución en waiting (no completed)', [
                'ejecucion_id' => $ejecucion->id,
            ]);

            return;
        }

        $ejecucion->update([
            'estado' => 'completed',
            'fecha_fin' => now(),
            'proximo_nodo' => null,
            'fecha_proximo_nodo' => null,
        ]);

        Log::info('EjecutarNodosProgramados: Ejecución completada', [
            'ejecucion_id' => $ejecucion->id,
        ]);
    }

    /**
     * Recupera una etapa que quedó stuck en 'executing'.
     *
     * Posibles causas de stuck:
     * - Callback de batch falló silenciosamente
     * - Instancia de Cloud Run matada durante procesamiento
     * - Error no capturado en el job
     * - Volumen grande procesado por EnviarEtapaChunkJob (NO tiene callback global)
     */
    private function recuperarEtapaStuck(FlujoEjecucionEtapa $etapa, FlujoEjecucion $ejecucion): void
    {
        Log::info('EjecutarNodosProgramados: Iniciando recuperación de etapa stuck', [
            'etapa_id' => $etapa->id,
            'node_id' => $etapa->node_id,
        ]);

        $batchInfo = $etapa->response_athenacampaign;

        // ✅ CASO ESPECIAL: Volumen grande procesado por chunks
        // EnviarEtapaChunkJob NO tiene callback global, así que verificamos
        // si todos los envíos ya se procesaron basándonos en la tabla envios
        if (isset($batchInfo['modo']) && $batchInfo['modo'] === 'large_volume_chunked') {
            $this->verificarYCompletarEtapaVolumenGrande($etapa, $ejecucion, $batchInfo);

            return;
        }

        // Verificar si hay un batch asociado que podamos consultar
        $batchId = $batchInfo['batch_id'] ?? null;

        if ($batchId) {
            // Intentar obtener estado del batch
            try {
                $batch = \Illuminate\Support\Facades\Bus::findBatch($batchId);

                if ($batch) {
                    if ($batch->finished()) {
                        // El batch terminó pero el callback no se ejecutó
                        Log::info('EjecutarNodosProgramados: Batch terminado, ejecutando recuperación', [
                            'batch_id' => $batchId,
                            'processed' => $batch->processedJobs(),
                            'failed' => $batch->failedJobs,
                        ]);

                        // Generar messageId simulado
                        $messageId = rand(10000, 99999);

                        $etapa->update([
                            'estado' => 'completed',
                            'ejecutado' => true,
                            'message_id' => $messageId,
                            'fecha_ejecucion' => now(),
                            'response_athenacampaign' => array_merge($batchInfo, [
                                'recovered' => true,
                                'recovered_at' => now()->toISOString(),
                                'messageID' => $messageId,
                                'Recipients' => $batch->processedJobs() - $batch->failedJobs,
                                'Errores' => $batch->failedJobs,
                            ]),
                        ]);

                        // Actualizar la ejecución para continuar
                        $this->actualizarEjecucionDespuesDeRecuperacion($ejecucion, $etapa);

                        return;
                    }

                    if ($batch->cancelled()) {
                        Log::error('EjecutarNodosProgramados: Batch fue cancelado', ['batch_id' => $batchId]);
                        $this->marcarEtapaComoFallida($etapa, 'Batch cancelado');

                        return;
                    }

                    // Batch aún procesando - esperar más
                    Log::debug('EjecutarNodosProgramados: Batch aún procesando', [
                        'batch_id' => $batchId,
                        'pending' => $batch->pendingJobs,
                    ]);

                    return;
                }
            } catch (\Exception $e) {
                Log::warning('EjecutarNodosProgramados: No se pudo consultar batch', [
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ✅ FALLBACK: Verificar si los envíos ya terminaron aunque no tengamos batch info
        // Esto puede pasar si el response_athenacampaign se perdió o corrompió
        $this->verificarYCompletarEtapaPorEnvios($etapa, $ejecucion);
    }

    /**
     * Verifica si una etapa de volumen grande ya completó todos sus envíos.
     *
     * Cuando se usa EnviarEtapaChunkJob, no hay callback global que marque la etapa
     * como completada. Esta función verifica el estado real de los envíos.
     */
    private function verificarYCompletarEtapaVolumenGrande(
        FlujoEjecucionEtapa $etapa,
        FlujoEjecucion $ejecucion,
        array $batchInfo
    ): void {
        $totalProspectos = $batchInfo['total_prospectos'] ?? 0;

        // Contar envíos procesados (exitosos + fallidos)
        $envioStats = DB::table('envios')
            ->where('flujo_ejecucion_etapa_id', $etapa->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as exitosos,
                SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes
            ")
            ->first();

        $totalEnviosCreados = (int) ($envioStats->total ?? 0);
        $exitosos = (int) ($envioStats->exitosos ?? 0);
        $fallidos = (int) ($envioStats->fallidos ?? 0);
        $pendientes = (int) ($envioStats->pendientes ?? 0);
        $procesados = $exitosos + $fallidos;

        Log::debug('EjecutarNodosProgramados: Verificando etapa volumen grande', [
            'etapa_id' => $etapa->id,
            'total_prospectos' => $totalProspectos,
            'total_envios_creados' => $totalEnviosCreados,
            'procesados' => $procesados,
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'pendientes' => $pendientes,
        ]);

        // ✅ FIX: Verificar completitud basándose en ENVÍOS CREADOS, no en prospectos
        // No todos los prospectos generan envíos (email/teléfono inválido, desuscritos, etc.)
        // Antes: comparaba procesados vs total_prospectos (buggy - nunca llegaba al 80%)
        // Ahora: si no hay pendientes y hay envíos, está completa
        $todosProcesados = $pendientes === 0 && $totalEnviosCreados > 0;

        // Calcular porcentaje para logging (basado en envíos creados, no prospectos)
        $porcentajeProcesado = $totalEnviosCreados > 0 ? ($procesados / $totalEnviosCreados) * 100 : 0;

        // NOTA: Previamente había un check `payload LIKE '%etapa_id%'` para contar
        // jobs en cola, pero con millones de filas en `jobs` PostgreSQL cancelaba
        // la query por statement_timeout. La señal `$pendientes === 0` ya garantiza
        // que todos los envíos de la etapa fueron procesados — un guard adicional
        // de "jobs en cola" no aporta seguridad real.

        if ($todosProcesados) {
            Log::info('EjecutarNodosProgramados: Etapa volumen grande completada', [
                'etapa_id' => $etapa->id,
                'total_envios_creados' => $totalEnviosCreados,
                'porcentaje_procesado' => round($porcentajeProcesado, 2),
            ]);

            $messageId = rand(10000, 99999);

            $etapa->update([
                'estado' => 'completed',
                'ejecutado' => true,
                'message_id' => $messageId,
                'fecha_ejecucion' => now(),
                'response_athenacampaign' => array_merge($batchInfo, [
                    'completed_by_cron' => true,
                    'completed_at' => now()->toISOString(),
                    'messageID' => $messageId,
                    'Recipients' => $exitosos,
                    'Errores' => $fallidos,
                    'total_envios_creados' => $totalEnviosCreados,
                    'porcentaje_procesado' => round($porcentajeProcesado, 2),
                ]),
            ]);

            // Programar siguiente nodo
            $this->actualizarEjecucionDespuesDeRecuperacion($ejecucion, $etapa);

            return;
        }

        // Aún procesando - loguear progreso
        Log::debug('EjecutarNodosProgramados: Etapa volumen grande aún procesando', [
            'etapa_id' => $etapa->id,
            'total_envios_creados' => $totalEnviosCreados,
            'porcentaje' => round($porcentajeProcesado, 2),
            'pendientes' => $pendientes,
        ]);
    }

    /**
     * Fallback: Verifica completitud basándose únicamente en tabla de envíos.
     * Usado cuando no tenemos información de batch.
     */
    private function verificarYCompletarEtapaPorEnvios(FlujoEjecucionEtapa $etapa, FlujoEjecucion $ejecucion): void
    {
        // Obtener estadísticas de envíos para esta etapa
        $envioStats = DB::table('envios')
            ->where('flujo_ejecucion_etapa_id', $etapa->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as exitosos,
                SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes
            ")
            ->first();

        $total = (int) ($envioStats->total ?? 0);
        $pendientes = (int) ($envioStats->pendientes ?? 0);
        $exitosos = (int) ($envioStats->exitosos ?? 0);
        $fallidos = (int) ($envioStats->fallidos ?? 0);

        Log::debug('EjecutarNodosProgramados: Verificando completitud por envíos', [
            'etapa_id' => $etapa->id,
            'total_envios' => $total,
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'pendientes' => $pendientes,
        ]);

        // Si hay envíos y no quedan pendientes, marcar como completada
        if ($total > 0 && $pendientes === 0) {
            Log::info('EjecutarNodosProgramados: Completando etapa por verificación de envíos', [
                'etapa_id' => $etapa->id,
            ]);

            $messageId = rand(10000, 99999);

            $etapa->update([
                'estado' => 'completed',
                'ejecutado' => true,
                'message_id' => $messageId,
                'fecha_ejecucion' => now(),
                'response_athenacampaign' => array_merge(
                    $etapa->response_athenacampaign ?? [],
                    [
                        'completed_by_envio_check' => true,
                        'completed_at' => now()->toISOString(),
                        'messageID' => $messageId,
                        'Recipients' => $exitosos,
                        'Errores' => $fallidos,
                    ]
                ),
            ]);

            $this->actualizarEjecucionDespuesDeRecuperacion($ejecucion, $etapa);

            return;
        }

        // Si no hay envíos o aún hay pendientes, marcar como failed
        if ($total === 0) {
            $this->marcarEtapaComoFallida($etapa, 'No se encontraron envíos para esta etapa');
        } else {
            Log::debug('EjecutarNodosProgramados: Etapa aún tiene envíos pendientes', [
                'etapa_id' => $etapa->id,
                'pendientes' => $pendientes,
            ]);
        }
    }

    /**
     * Marca una etapa como fallida
     */
    private function marcarEtapaComoFallida(FlujoEjecucionEtapa $etapa, string $razon): void
    {
        $etapa->update([
            'estado' => 'failed',
            'ejecutado' => true,
            'fecha_ejecucion' => now(),
            'response_athenacampaign' => array_merge(
                $etapa->response_athenacampaign ?? [],
                ['error' => $razon, 'failed_at' => now()->toISOString()]
            ),
        ]);

        Log::error('EjecutarNodosProgramados: Etapa marcada como fallida', [
            'etapa_id' => $etapa->id,
            'razon' => $razon,
        ]);
    }

    /**
     * Actualiza la ejecución después de recuperar una etapa y programa el siguiente nodo.
     *
     * Similar a BatchCompletedCallback pero para etapas recuperadas por el cron.
     */
    private function actualizarEjecucionDespuesDeRecuperacion(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapa): void
    {
        // Obtener datos del flujo para encontrar el siguiente nodo
        $flujoData = $ejecucion->flujo->flujo_data ?? [];
        $branches = $flujoData['branches'] ?? $flujoData['edges'] ?? [];
        $stages = $flujoData['stages'] ?? [];
        $conditions = $flujoData['conditions'] ?? [];

        // Normalizar edges a branches
        if (! empty($flujoData['edges']) && empty($flujoData['branches'])) {
            $branches = collect($flujoData['edges'])->map(function ($edge) {
                return [
                    'source_node_id' => $edge['source'] ?? null,
                    'target_node_id' => $edge['target'] ?? null,
                    'source_handle' => $edge['sourceHandle'] ?? null,
                ];
            })->toArray();
        }

        // Buscar siguiente nodo
        $siguienteConexion = collect($branches)->firstWhere('source_node_id', $etapa->node_id);

        if (! $siguienteConexion) {
            // No hay siguiente nodo - completar ejecución
            $this->finalizarEjecucion($ejecucion);

            return;
        }

        $siguienteNodoId = $siguienteConexion['target_node_id'];

        // Si es nodo final, completar
        if (str_starts_with($siguienteNodoId, 'end-')) {
            $this->finalizarEjecucion($ejecucion);

            return;
        }

        // Buscar datos del siguiente nodo
        $siguienteNodo = collect($stages)->firstWhere('id', $siguienteNodoId);
        if (! $siguienteNodo) {
            $siguienteNodo = collect($conditions)->firstWhere('id', $siguienteNodoId);
        }

        $tipoNodo = $siguienteNodo['type'] ?? (str_starts_with($siguienteNodoId, 'condition') ? 'condition' : 'stage');

        $prospectoIds = $etapa->prospectos()->pluck('prospectos.id')->toArray();
        if (empty($prospectoIds)) {
            $prospectoIds = $ejecucion->prospectos()->pluck('prospectos.id')->toArray();
        }

        // Buscar la etapa siguiente (puede ya existir desde la creación del flujo)
        $siguienteEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $siguienteNodoId)
            ->first();

        // ✅ FIX: Usar fecha_programada existente si la etapa ya fue creada al inicio del flujo
        // Solo calcular desde now() si la etapa no existe (caso edge de flujos dinámicos)
        if ($siguienteEtapa && $siguienteEtapa->fecha_programada) {
            $fechaProgramada = $siguienteEtapa->fecha_programada;
            Log::debug('EjecutarNodosProgramados: Usando fecha_programada existente', [
                'node_id' => $siguienteNodoId,
                'fecha_programada' => $fechaProgramada,
            ]);
        } else {
            // Calcular fecha programada solo si no existe
            if ($tipoNodo === 'condition') {
                $tiempoVerificacion = 24; // horas por defecto
                $fechaProgramada = now()->addHours($tiempoVerificacion);
            } else {
                $tiempoEspera = $siguienteNodo['tiempo_espera'] ?? 0;
                $fechaProgramada = now()->addDays($tiempoEspera);
            }
            Log::debug('EjecutarNodosProgramados: Calculando nueva fecha_programada', [
                'node_id' => $siguienteNodoId,
                'tipo_nodo' => $tipoNodo,
                'fecha_programada' => $fechaProgramada,
            ]);
        }

        // ✅ FIX: Si la etapa ya existe, MERGE prospectos en lugar de sobrescribir
        // Esto soporta múltiples inputs al mismo nodo (ej: múltiples condiciones YES → mismo retarget)
        if ($siguienteEtapa) {
            $siguienteEtapa->prospectos()->syncWithoutDetaching($prospectoIds);
            $mergedProspectos = $siguienteEtapa->prospectos()->pluck('prospectos.id')->toArray();

            Log::debug("EjecutarNodosProgramados: Merging prospects for node {$siguienteNodoId}", [
                'new_count' => count($prospectoIds),
                'merged_count' => count($mergedProspectos),
            ]);

            $prospectoIds = $mergedProspectos;
        }

        // Preparar datos para la etapa
        // En flujos perpetuos la etapa pudo haber sido ejecutada en un ciclo anterior
        // (ejecutado=true). Si solo reseteamos estado, queda inconsistente y el
        // scheduler la salta. Reseteamos ambos campos para que vuelva a ser elegible.
        $etapaData = [
            'prospectos_ids' => $prospectoIds,
            'prospectos_count' => count($prospectoIds),
            'estado' => 'pending',
            'ejecutado' => false,
        ];

        // Si es condición, agregar source info
        if ($tipoNodo === 'condition') {
            $etapaData['response_athenacampaign'] = [
                'pending_condition' => true,
                'source_message_id' => $etapa->message_id,
                'source_etapa_id' => $etapa->id,
                'conexion' => $siguienteConexion,
            ];
        }

        if ($siguienteEtapa) {
            // Solo actualizar prospectos_ids y estado, NO sobreescribir fecha_programada
            $siguienteEtapa->update($etapaData);
            Log::debug('EjecutarNodosProgramados: Etapa siguiente actualizada', [
                'etapa_id' => $siguienteEtapa->id,
                'node_id' => $siguienteNodoId,
                'prospectos_count' => count($prospectoIds),
                'fecha_programada_preservada' => $siguienteEtapa->fecha_programada,
            ]);
        } else {
            // Usar firstOrCreate para evitar race conditions
            $etapaData['fecha_programada'] = $fechaProgramada;
            $siguienteEtapa = FlujoEjecucionEtapa::firstOrCreate(
                [
                    'flujo_ejecucion_id' => $ejecucion->id,
                    'node_id' => $siguienteNodoId,
                ],
                array_merge($etapaData, [
                    'etapa_id' => null,
                ])
            );
            Log::debug('EjecutarNodosProgramados: Etapa siguiente creada/encontrada', [
                'etapa_id' => $siguienteEtapa->id,
                'node_id' => $siguienteNodoId,
                'prospectos_count' => count($prospectoIds),
                'fecha_programada' => $fechaProgramada,
            ]);
        }

        // Actualizar ejecución
        $ejecucion->update([
            'nodo_actual' => $etapa->node_id,
            'proximo_nodo' => $siguienteNodoId,
            'fecha_proximo_nodo' => $fechaProgramada,
        ]);

        Log::debug('EjecutarNodosProgramados: Ejecución actualizada post-recovery', [
            'ejecucion_id' => $ejecucion->id,
            'nodo_recuperado' => $etapa->node_id,
            'proximo_nodo' => $siguienteNodoId,
            'tipo_nodo' => $tipoNodo,
        ]);
    }

    /**
     * Programa el siguiente nodo a ejecutar
     */
    private function programarSiguienteNodo(FlujoEjecucion $ejecucion, string $nodoActualId, array $branches): void
    {
        // Buscar siguiente conexión
        $siguienteConexion = collect($branches)->firstWhere('source_node_id', $nodoActualId);

        if (! $siguienteConexion) {
            // No hay siguiente nodo, marcar como completado
            $this->finalizarEjecucion($ejecucion);

            return;
        }

        $siguienteNodoId = $siguienteConexion['target_node_id'];

        // ✅ VERIFICAR SI ES UN NODO FINAL (end-*)
        if (str_starts_with($siguienteNodoId, 'end-')) {
            $flujo = $ejecucion->flujo;
            $esPerpetuo = $flujo->es_perpetuo ?? false;

            // Marcar los prospectos de ESTA ejecución como completados
            $prospectosCompletados = \App\Models\ProspectoEnFlujo::where('flujo_id', $ejecucion->flujo_id)
                ->whereIn('prospecto_id', $ejecucion->prospectos()->pluck('prospectos.id'))
                ->where('completado', false)
                ->update([
                    'completado' => true,
                    'estado' => 'completado',
                ]);

            Log::debug('EjecutarNodosProgramados: Prospectos de cohorte completados', [
                'ejecucion_id' => $ejecucion->id,
                'prospectos_completados' => $prospectosCompletados,
                'es_perpetuo' => $esPerpetuo,
            ]);

            if ($esPerpetuo) {
                // Flujo perpetuo: la ejecución queda "waiting" esperando nuevos prospectos
                $ejecucion->update([
                    'estado' => 'waiting',
                    'proximo_nodo' => null,
                    'fecha_proximo_nodo' => null,
                ]);

                Log::debug('EjecutarNodosProgramados: Flujo perpetuo en espera', [
                    'ejecucion_id' => $ejecucion->id,
                    'flujo_id' => $flujo->id,
                ]);
            } else {
                // Flujo normal: marcar como completado
                $ejecucion->update([
                    'estado' => 'completed',
                    'fecha_fin' => now(),
                    'proximo_nodo' => null,
                    'fecha_proximo_nodo' => null,
                ]);

                Log::info('EjecutarNodosProgramados: Ejecución completada', [
                    'ejecucion_id' => $ejecucion->id,
                ]);
            }

            return;
        }

        // ✅ Buscar la etapa en la base de datos que YA tiene la fecha programada correcta
        $siguienteEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $siguienteNodoId)
            ->where('ejecutado', false)
            ->first();

        if (! $siguienteEtapa) {
            // Si no existe la etapa, crear una nueva (fallback)
            $flujoData = $ejecucion->flujo->flujo_data;
            $stages = $flujoData['stages'] ?? [];
            $conditions = $flujoData['conditions'] ?? [];

            // Buscar en stages primero, luego en conditions
            $siguienteStage = collect($stages)->firstWhere('id', $siguienteNodoId);

            if (! $siguienteStage) {
                $siguienteStage = collect($conditions)->firstWhere('id', $siguienteNodoId);
            }

            if (! $siguienteStage) {
                throw new \Exception("No se encontró el siguiente nodo {$siguienteNodoId}");
            }

            $tiempoEspera = $siguienteStage['tiempo_espera'] ?? 0;
            $fechaProximoNodo = now()->addDays($tiempoEspera);
        } else {
            // ✅ Usar la fecha_programada que YA está en la base de datos
            $fechaProximoNodo = $siguienteEtapa->fecha_programada;
        }

        // Actualizar ejecución con próximo nodo
        $ejecucion->update([
            'proximo_nodo' => $siguienteNodoId,
            'fecha_proximo_nodo' => $fechaProximoNodo,
        ]);

        Log::info('EjecutarNodosProgramados: Siguiente nodo programado', [
            'ejecucion_id' => $ejecucion->id,
            'proximo_nodo' => $siguienteNodoId,
            'fecha_proximo_nodo' => $fechaProximoNodo,
            'usa_fecha_bd' => $siguienteEtapa !== null,
        ]);
    }
}
