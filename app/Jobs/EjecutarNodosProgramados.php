<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoEtapa;
use App\Models\ProspectoEnFlujo;
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

        Log::info('EjecutarNodosProgramados: Obteniendo prospectos', [
            'usa_prospectos_etapa' => $etapa->prospectos_ids !== null,
            'total_prospectos' => count($prospectoIds),
        ]);

        // Obtener prospectos para este flujo
        $prospectosEnFlujo = ProspectoEnFlujo::whereIn('prospecto_id', $prospectoIds)
            ->where('flujo_id', $ejecucion->flujo_id)
            ->with('prospecto')
            ->get();

        // Obtener tipo del mensaje
        $tipoMensaje = $stage['data']['tipo_mensaje'] ?? $stage['tipo_mensaje'] ?? 'email';
        
        // ✅ Obtener contenido usando la lógica de plantillas (igual que EnviarEtapaJob)
        $contenidoData = $this->obtenerContenidoMensaje($stage, $tipoMensaje);

        Log::info('EjecutarNodosProgramados: Enviando mensaje', [
            'stage_id' => $stage['id'] ?? 'unknown',
            'tipo' => $tipoMensaje,
            'es_html' => $contenidoData['es_html'],
            'tiene_asunto' => !empty($contenidoData['asunto']),
        ]);

        // Enviar mensaje
        $response = $envioService->enviar(
            tipoMensaje: $tipoMensaje,
            prospectosEnFlujo: $prospectosEnFlujo,
            contenido: $contenidoData['contenido'],
            template: [
                'asunto' => $contenidoData['asunto'] ?? $stage['data']['template']['asunto'] ?? null,
            ],
            flujo: $ejecucion->flujo,
            etapaEjecucionId: $etapa->id,
            esHtml: $contenidoData['es_html']
        );

        // Actualizar etapa con resultado
        $etapa->update([
            'estado' => 'completed',
            'ejecutado' => true,
            'message_id' => $response['mensaje']['messageID'] ?? null,
            'response_athenacampaign' => $response,
        ]);

        Log::info('EjecutarNodosProgramados: Nodo de envío completado', [
            'etapa_id' => $etapa->id,
            'message_id' => $response['mensaje']['messageID'] ?? null,
        ]);

        // Programar siguiente nodo (usar branches normalizados)
        $this->programarSiguienteNodo($ejecucion, $stage['id'], $branches);
    }

    /**
     * Obtiene el contenido del mensaje a enviar.
     * Prioriza plantilla de referencia sobre contenido inline.
     * 
     * @param array $stage Datos del stage desde flujo_data
     * @param string $tipoMensaje 'email' o 'sms'
     * @return array{contenido: string, asunto: string|null, es_html: bool}
     */
    private function obtenerContenidoMensaje(array $stage, string $tipoMensaje): array
    {
        // Intentar buscar la FlujoEtapa para usar plantilla de referencia
        $stageId = $stage['id'] ?? null;
        
        if ($stageId) {
            $flujoEtapa = FlujoEtapa::find($stageId);
            
            if ($flujoEtapa && $flujoEtapa->usaPlantillaReferencia()) {
                Log::info('EjecutarNodosProgramados: Usando plantilla de referencia', [
                    'stage_id' => $stageId,
                    'plantilla_id' => $flujoEtapa->plantilla_id,
                    'plantilla_type' => $flujoEtapa->plantilla_type,
                ]);
                
                return $flujoEtapa->obtenerContenidoParaEnvio($tipoMensaje);
            }
        }
        
        // Fallback: usar contenido inline del stage
        Log::info('EjecutarNodosProgramados: Usando contenido inline', [
            'stage_id' => $stageId,
        ]);
        
        return [
            'contenido' => $stage['data']['contenido'] ?? $stage['plantilla_mensaje'] ?? '',
            'asunto' => $stage['data']['template']['asunto'] ?? null,
            'es_html' => false,
        ];
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
        // Obtener el message_id de la etapa de email anterior
        // (necesario para consultar estadísticas en AthenaCampaign)
        $etapaEmailAnterior = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->whereNotNull('message_id')
            ->where('ejecutado', true)
            ->orderBy('fecha_ejecucion', 'desc')
            ->first();

        if (!$etapaEmailAnterior || !$etapaEmailAnterior->message_id) {
            Log::error('EjecutarNodosProgramados: No se encontró etapa de email anterior con message_id', [
                'ejecucion_id' => $ejecucion->id,
                'nodo_id' => $stage['id'],
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

        // ✅ PRIORIDAD: usar prospectos de la etapa si están disponibles (filtrado previo)
        // Si no, usar los prospectos de la ejecución completa
        $prospectoIds = $etapa->prospectos_ids ?? $ejecucion->prospectos_ids;

        Log::info('EjecutarNodosProgramados: Encontrado message_id para condición', [
            'message_id' => $messageId,
            'etapa_email_id' => $etapaEmailAnterior->id,
            'etapa_email_node_id' => $etapaEmailAnterior->node_id,
            'prospectos_count' => count($prospectoIds),
        ]);

        // Construir el array $condicion con el formato esperado por VerificarCondicionJob
        $condicion = [
            'target_node_id' => $stage['id'],
            'source_node_id' => $etapaEmailAnterior->node_id,
            'data' => [
                'check_param' => $stage['check_param'] ?? 'Views',
                'check_operator' => $stage['check_operator'] ?? '>',
                'check_value' => $stage['check_value'] ?? '0',
            ],
        ];

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
