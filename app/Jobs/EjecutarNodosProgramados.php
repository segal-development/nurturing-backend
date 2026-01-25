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
        Log::info('EjecutarNodosProgramados: Iniciando verificación de nodos programados');

        // Obtener ejecuciones con nodos programados listos para ejecutar
        $ejecuciones = FlujoEjecucion::conNodosProgramados()->get();

        if ($ejecuciones->isEmpty()) {
            Log::info('EjecutarNodosProgramados: No hay nodos programados listos para ejecutar');

            return;
        }

        Log::info('EjecutarNodosProgramados: Encontradas ejecuciones con nodos programados', [
            'cantidad' => $ejecuciones->count(),
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
            
            Log::info('EjecutarNodosProgramados: Esperando que etapa anterior complete', [
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

        Log::info('EjecutarNodosProgramados: Ejecutando nodo', [
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
            Log::info('EjecutarNodosProgramados: Nodo ya fue ejecutado o está en proceso, saltando', [
                'ejecucion_id' => $ejecucion->id,
                'nodo_id' => $nodoId,
                'estado_etapa' => $etapaExistente->estado,
                'ejecutado' => $etapaExistente->ejecutado,
            ]);
            
            // Buscar el siguiente nodo para actualizar la ejecución
            $this->programarSiguienteNodo($ejecucion, $stage['id'], $branches);
            return;
        }

        if (!$etapaExistente) {
            // Crear nueva etapa (operación atómica)
            $etapaExistente = FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $ejecucion->id,
                'node_id' => $nodoId,
                'fecha_programada' => now(),
                'estado' => 'pending',
                'ejecutado' => false,
            ]);
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

        if (in_array($tipoNodo, ['email', 'sms', 'stage'])) {
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

        // ✅ PRIORIDAD: usar prospectos de la etapa si están disponibles (filtrado por condición)
        // Si no, usar los prospectos de la ejecución completa
        $prospectoIds = $etapa->prospectos_ids ?? $ejecucion->prospectos_ids;

        Log::info('EjecutarNodosProgramados: Despachando EnviarEtapaJob', [
            'ejecucion_id' => $ejecucion->id,
            'etapa_id' => $etapa->id,
            'stage_id' => $stage['id'] ?? 'unknown',
            'usa_prospectos_etapa' => $etapa->prospectos_ids !== null,
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

        Log::info('EjecutarNodosProgramados: EnviarEtapaJob despachado', [
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
                Log::info('EjecutarNodosProgramados: Condición esperando que email anterior complete', [
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
            Log::info('EjecutarNodosProgramados: Usando source_message_id de etapa', [
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

            if (!$etapaEmailAnterior || !$etapaEmailAnterior->message_id) {
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
            
            Log::info('EjecutarNodosProgramados: Usando message_id de etapa email anterior', [
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

        // ✅ PRIORIDAD: usar prospectos de la etapa si están disponibles (filtrado previo)
        // Si no, usar los prospectos de la ejecución completa
        $prospectoIds = $etapa->prospectos_ids ?? $ejecucion->prospectos_ids;

        Log::info('EjecutarNodosProgramados: Preparando evaluación de condición', [
            'message_id' => $messageId,
            'prospectos_count' => count($prospectoIds),
        ]);

        // ✅ PRIORIDAD 1: Usar conexión guardada por EnviarEtapaJob
        // PRIORIDAD 2: Construir desde el stage
        $condicionGuardada = $responseData['conexion'] ?? null;
        
        if ($condicionGuardada) {
            $condicion = $condicionGuardada;
            // Asegurar que tenga los datos de la condición
            if (!isset($condicion['data'])) {
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

        Log::info('EjecutarNodosProgramados: Condición despachada para evaluación', [
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
                    Log::info('EjecutarNodosProgramados: Batch aún procesando', [
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
        
        $exitosos = (int) ($envioStats->exitosos ?? 0);
        $fallidos = (int) ($envioStats->fallidos ?? 0);
        $pendientes = (int) ($envioStats->pendientes ?? 0);
        $procesados = $exitosos + $fallidos;
        
        Log::info('EjecutarNodosProgramados: Verificando etapa volumen grande', [
            'etapa_id' => $etapa->id,
            'total_prospectos' => $totalProspectos,
            'procesados' => $procesados,
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'pendientes' => $pendientes,
        ]);
        
        // ✅ Verificar si está "prácticamente terminado"
        // - No quedan pendientes
        // - Se procesó al menos el 80% de los prospectos (algunos pueden no tener email válido)
        $porcentajeProcesado = $totalProspectos > 0 ? ($procesados / $totalProspectos) * 100 : 0;
        $todosProcesados = $pendientes === 0 && $porcentajeProcesado >= 80;
        
        // También verificar jobs en cola para esta etapa
        $jobsEnCola = DB::table('jobs')
            ->where('payload', 'like', '%' . $etapa->id . '%')
            ->count();
        
        if ($todosProcesados && $jobsEnCola < 100) {
            Log::info('EjecutarNodosProgramados: Etapa volumen grande completada', [
                'etapa_id' => $etapa->id,
                'porcentaje_procesado' => round($porcentajeProcesado, 2),
                'jobs_restantes_en_cola' => $jobsEnCola,
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
                    'porcentaje_procesado' => round($porcentajeProcesado, 2),
                ]),
            ]);
            
            // Programar siguiente nodo
            $this->actualizarEjecucionDespuesDeRecuperacion($ejecucion, $etapa);
            return;
        }
        
        // Aún procesando - loguear progreso
        Log::info('EjecutarNodosProgramados: Etapa volumen grande aún procesando', [
            'etapa_id' => $etapa->id,
            'porcentaje' => round($porcentajeProcesado, 2),
            'jobs_en_cola' => $jobsEnCola,
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
        
        Log::info('EjecutarNodosProgramados: Verificando completitud por envíos', [
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
            Log::info('EjecutarNodosProgramados: Etapa aún tiene envíos pendientes', [
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
        if (!empty($flujoData['edges']) && empty($flujoData['branches'])) {
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
        
        if (!$siguienteConexion) {
            // No hay siguiente nodo - completar ejecución
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
                'proximo_nodo' => null,
                'fecha_proximo_nodo' => null,
            ]);
            Log::info('EjecutarNodosProgramados: Ejecución completada después de recuperación');
            return;
        }

        $siguienteNodoId = $siguienteConexion['target_node_id'];
        
        // Si es nodo final, completar
        if (str_starts_with($siguienteNodoId, 'end-')) {
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
                'proximo_nodo' => null,
                'fecha_proximo_nodo' => null,
            ]);
            Log::info('EjecutarNodosProgramados: Nodo final alcanzado, ejecución completada');
            return;
        }

        // Buscar datos del siguiente nodo
        $siguienteNodo = collect($stages)->firstWhere('id', $siguienteNodoId);
        if (!$siguienteNodo) {
            $siguienteNodo = collect($conditions)->firstWhere('id', $siguienteNodoId);
        }
        
        $tipoNodo = $siguienteNodo['type'] ?? (str_starts_with($siguienteNodoId, 'condition') ? 'condition' : 'stage');
        
        // Calcular fecha programada
        if ($tipoNodo === 'condition') {
            $tiempoVerificacion = 24; // horas por defecto
            $fechaProgramada = now()->addHours($tiempoVerificacion);
        } else {
            $tiempoEspera = $siguienteNodo['tiempo_espera'] ?? 0;
            $fechaProgramada = now()->addDays($tiempoEspera);
        }
        
        // Obtener prospectos_ids de la etapa actual
        $prospectoIds = $etapa->prospectos_ids ?? $ejecucion->prospectos_ids ?? [];

        // Buscar o crear la etapa siguiente
        $siguienteEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $siguienteNodoId)
            ->first();

        $etapaData = [
            'prospectos_ids' => $prospectoIds,
            'fecha_programada' => $fechaProgramada,
            'estado' => 'pending',
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
            $siguienteEtapa->update($etapaData);
            Log::info('EjecutarNodosProgramados: Etapa siguiente actualizada', [
                'etapa_id' => $siguienteEtapa->id,
                'node_id' => $siguienteNodoId,
                'prospectos_count' => count($prospectoIds),
            ]);
        } else {
            $siguienteEtapa = FlujoEjecucionEtapa::create(array_merge($etapaData, [
                'flujo_ejecucion_id' => $ejecucion->id,
                'etapa_id' => null,
                'node_id' => $siguienteNodoId,
            ]));
            Log::info('EjecutarNodosProgramados: Etapa siguiente creada', [
                'etapa_id' => $siguienteEtapa->id,
                'node_id' => $siguienteNodoId,
                'prospectos_count' => count($prospectoIds),
            ]);
        }

        // Actualizar ejecución
        $ejecucion->update([
            'nodo_actual' => $etapa->node_id,
            'proximo_nodo' => $siguienteNodoId,
            'fecha_proximo_nodo' => $fechaProgramada,
        ]);

        Log::info('EjecutarNodosProgramados: Ejecución actualizada después de recuperación', [
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
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
                'proximo_nodo' => null,
                'fecha_proximo_nodo' => null,
            ]);

            Log::info('EjecutarNodosProgramados: No hay siguiente nodo, ejecución completada', [
                'ejecucion_id' => $ejecucion->id,
            ]);

            return;
        }

        $siguienteNodoId = $siguienteConexion['target_node_id'];

        // ✅ VERIFICAR SI ES UN NODO FINAL (end-*)
        if (str_starts_with($siguienteNodoId, 'end-')) {
            Log::info('EjecutarNodosProgramados: Nodo final alcanzado, completando ejecución', [
                'ejecucion_id' => $ejecucion->id,
                'end_node_id' => $siguienteNodoId,
            ]);

            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
                'proximo_nodo' => null,
                'fecha_proximo_nodo' => null,
            ]);

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
