<?php

namespace App\Jobs;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoEtapa;
use App\Models\FlujoJob;
use App\Models\ProspectoEnFlujo;
use App\Services\EnvioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnviarEtapaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutos

    public $tries = 3;

    public $backoff = [60, 300, 900]; // Reintentos: 1min, 5min, 15min

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $flujoEjecucionId,
        public int $etapaEjecucionId,
        public array $stage,
        public array $prospectoIds,
        public array $branches = []
    ) {
        // ✅ Indica que el job debe despacharse solo DESPUÉS del commit de la transacción
        // Esto previene que el job falle antes de que los registros se guarden en la BD
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(EnvioService $envioService): void
    {
        Log::info('EnviarEtapaJob: Iniciado', [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'stage_label' => $this->stage['label'] ?? 'Unknown',
        ]);

        DB::beginTransaction();

        try {
            // 1. Obtener modelos
            $ejecucion = FlujoEjecucion::findOrFail($this->flujoEjecucionId);
            $etapaEjecucion = FlujoEjecucionEtapa::findOrFail($this->etapaEjecucionId);

            // 2. Verificar que no esté ya ejecutada
            if ($etapaEjecucion->estado === 'completed') {
                Log::warning('EnviarEtapaJob: Etapa ya completada', [
                    'etapa_id' => $this->etapaEjecucionId,
                ]);

                return;
            }

            // 3. Actualizar estados
            $etapaEjecucion->update(['estado' => 'executing']);
            $ejecucion->update(['estado' => 'in_progress']);

            // 4. Obtener/crear ProspectoEnFlujo para cada prospecto
            $prospectosEnFlujo = $this->obtenerProspectosEnFlujo($ejecucion);

            // 5. Obtener contenido del mensaje (desde plantilla o inline)
            $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
            $contenidoData = $this->obtenerContenidoMensaje($tipoMensaje);

            Log::info('EnviarEtapaJob: Enviando mensaje', [
                'tipo' => $tipoMensaje,
                'prospectos' => count($this->prospectoIds),
                'es_html' => $contenidoData['es_html'],
                'tiene_asunto' => !empty($contenidoData['asunto']),
            ]);

            $response = $envioService->enviar(
                tipoMensaje: $tipoMensaje,
                prospectosEnFlujo: $prospectosEnFlujo,
                contenido: $contenidoData['contenido'],
                template: [
                    'asunto' => $contenidoData['asunto'] ?? $this->stage['template']['asunto'] ?? null,
                ],
                flujo: $ejecucion->flujo,
                etapaEjecucionId: $this->etapaEjecucionId,
                esHtml: $contenidoData['es_html']
            );

            // 5. Obtener messageID de la respuesta
            $messageId = $response['mensaje']['messageID'] ?? null;

            if (! $messageId) {
                throw new \Exception('No se recibió messageID de AthenaCampaign');
            }

            Log::info('EnviarEtapaJob: Mensaje enviado exitosamente', [
                'message_id' => $messageId,
                'destinatarios' => $response['mensaje']['Recipients'] ?? 0,
            ]);

            // 6. Actualizar etapa con resultado
            $etapaEjecucion->update([
                'message_id' => $messageId,
                'response_athenacampaign' => $response,
                'estado' => 'completed',
                'fecha_ejecucion' => now(),
            ]);

            // 7. Registrar job como completado
            FlujoJob::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'job_type' => 'enviar_etapa',
                'job_id' => $this->job->uuid() ?? null,
                'job_data' => [
                    'etapa_id' => $this->etapaEjecucionId,
                    'stage' => $this->stage,
                    'prospectos_count' => count($this->prospectoIds),
                ],
                'estado' => 'completed',
                'fecha_queued' => now(),
                'fecha_procesado' => now(),
            ]);

            // 8. Determinar siguiente paso
            $this->procesarSiguientePaso($ejecucion, $etapaEjecucion, $messageId);

            DB::commit();

            Log::info('EnviarEtapaJob: Completado exitosamente', [
                'etapa_id' => $this->etapaEjecucionId,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('EnviarEtapaJob: Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Actualizar estado de error
            if (isset($etapaEjecucion)) {
                $etapaEjecucion->update([
                    'estado' => 'failed',
                    'error_mensaje' => $e->getMessage(),
                ]);
            }

            // Registrar job como fallido
            FlujoJob::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'job_type' => 'enviar_etapa',
                'job_id' => $this->job->uuid() ?? null,
                'job_data' => [
                    'etapa_id' => $this->etapaEjecucionId,
                    'stage' => $this->stage,
                ],
                'estado' => 'failed',
                'fecha_queued' => now(),
                'error_details' => $e->getMessage(),
                'intentos' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtiene el contenido del mensaje a enviar.
     * Prioriza plantilla de referencia sobre contenido inline.
     * 
     * @param string $tipoMensaje 'email' o 'sms'
     * @return array{contenido: string, asunto: string|null, es_html: bool}
     */
    private function obtenerContenidoMensaje(string $tipoMensaje): array
    {
        // Intentar buscar la FlujoEtapa para usar plantilla de referencia
        $stageId = $this->stage['id'] ?? null;
        
        if ($stageId) {
            $flujoEtapa = FlujoEtapa::find($stageId);
            
            if ($flujoEtapa && $flujoEtapa->usaPlantillaReferencia()) {
                Log::info('EnviarEtapaJob: Usando plantilla de referencia', [
                    'stage_id' => $stageId,
                    'plantilla_id' => $flujoEtapa->plantilla_id,
                    'plantilla_type' => $flujoEtapa->plantilla_type,
                ]);
                
                return $flujoEtapa->obtenerContenidoParaEnvio($tipoMensaje);
            }
        }
        
        // Fallback: usar contenido inline del stage
        Log::info('EnviarEtapaJob: Usando contenido inline', [
            'stage_id' => $stageId,
        ]);
        
        return [
            'contenido' => $this->stage['plantilla_mensaje'] ?? '',
            'asunto' => $this->stage['template']['asunto'] ?? null,
            'es_html' => false,
        ];
    }

    /**
     * Obtiene o crea registros de ProspectoEnFlujo para los prospectos
     */
    private function obtenerProspectosEnFlujo(FlujoEjecucion $ejecucion): \Illuminate\Support\Collection
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';

        $prospectosEnFlujo = collect();

        foreach ($this->prospectoIds as $prospectoId) {
            // Buscar o crear ProspectoEnFlujo
            $prospectoEnFlujo = ProspectoEnFlujo::firstOrCreate(
                [
                    'prospecto_id' => $prospectoId,
                    'flujo_id' => $ejecucion->flujo_id,
                ],
                [
                    'canal_asignado' => $tipoMensaje,
                    'estado' => 'en_proceso',
                    'fecha_inicio' => now(),
                    'completado' => false,
                    'cancelado' => false,
                ]
            );

            $prospectosEnFlujo->push($prospectoEnFlujo);
        }

        return $prospectosEnFlujo;
    }

    /**
     * Determina el siguiente paso después de enviar la etapa
     */
    private function procesarSiguientePaso(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        int $messageId
    ): void {
        // Buscar todas las conexiones desde esta etapa
        $conexionesDesdeEsta = collect($this->branches)->filter(function ($branch) {
            return $branch['source_node_id'] === $this->stage['id'];
        });

        if ($conexionesDesdeEsta->isEmpty()) {
            Log::info('EnviarEtapaJob: No hay siguiente paso (fin del flujo)');
            // Marcar ejecución como completada si no hay más etapas
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);

            return;
        }

        // Obtener el target de la primera conexión
        $primeraConexion = $conexionesDesdeEsta->first();
        $targetNodeId = $primeraConexion['target_node_id'];

        // ✅ VERIFICAR SI ES UN NODO FINAL (end-*)
        if (str_starts_with($targetNodeId, 'end-')) {
            Log::info('EnviarEtapaJob: Nodo final alcanzado, completando ejecución', [
                'end_node_id' => $targetNodeId,
            ]);

            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);

            return;
        }

        // Buscar el nodo destino
        $flujoData = $ejecucion->flujo->flujo_data;
        $stages = $flujoData['stages'] ?? [];
        $targetNode = collect($stages)->firstWhere('id', $targetNodeId);

        if (! $targetNode) {
            Log::warning('EnviarEtapaJob: Nodo destino no encontrado', [
                'target_node_id' => $targetNodeId,
            ]);

            return;
        }

        // Determinar el tipo de nodo siguiente
        $tipoNodoSiguiente = $targetNode['type'] ?? 'stage';

        if ($tipoNodoSiguiente === 'condition') {
            // Es un nodo de condición: Programar verificación
            $tiempoVerificacion = $this->stage['tiempo_verificacion_condicion'] ?? 24; // horas
            $fechaVerificacion = now()->addHours($tiempoVerificacion);

            Log::info('EnviarEtapaJob: Programando verificación de condición', [
                'en_horas' => $tiempoVerificacion,
                'condition_node_id' => $targetNodeId,
            ]);

            VerificarCondicionJob::dispatch(
                $this->flujoEjecucionId,
                $etapaEjecucion->id,
                $primeraConexion,
                $messageId
            )->delay($fechaVerificacion);

            // ✅ ACTUALIZAR EJECUCIÓN: Mantener como in_progress con próxima verificación programada
            $ejecucion->update([
                'estado' => 'in_progress',
                'proximo_nodo' => $targetNodeId,
                'fecha_proximo_nodo' => $fechaVerificacion,
            ]);

            Log::info('EnviarEtapaJob: Condición programada', [
                'condition_node_id' => $targetNodeId,
                'fecha_verificacion' => $fechaVerificacion,
                'estado_ejecucion' => 'in_progress',
            ]);

        } elseif ($tipoNodoSiguiente === 'stage') {
            // Es una etapa normal: Calcular fecha de ejecución y encolar
            $tiempoEspera = $targetNode['tiempo_espera'] ?? 0; // días desde ahora
            $fechaProgramada = now()->addDays($tiempoEspera);

            Log::info('EnviarEtapaJob: Programando siguiente etapa', [
                'siguiente_node_id' => $targetNodeId,
                'tiempo_espera_dias' => $tiempoEspera,
                'fecha_programada' => $fechaProgramada,
            ]);

            // Crear registro de la siguiente etapa
            $siguienteEtapaEjecucion = FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'etapa_id' => null,
                'node_id' => $targetNodeId,
                'fecha_programada' => $fechaProgramada,
                'estado' => 'pending',
            ]);

            // Encolar job para enviar esta etapa con delay
            EnviarEtapaJob::dispatch(
                $this->flujoEjecucionId,
                $siguienteEtapaEjecucion->id,
                $targetNode,
                $this->prospectoIds,
                $this->branches
            )->delay($fechaProgramada);

            // ✅ ACTUALIZAR EJECUCIÓN: Mantener como in_progress con próximo nodo programado
            $ejecucion->update([
                'estado' => 'in_progress',
                'proximo_nodo' => $targetNodeId,
                'fecha_proximo_nodo' => $fechaProgramada,
            ]);

            Log::info('EnviarEtapaJob: Siguiente etapa encolada', [
                'etapa_ejecucion_id' => $siguienteEtapaEjecucion->id,
                'delay_dias' => $tiempoEspera,
                'estado_ejecucion' => 'in_progress',
                'proximo_nodo' => $targetNodeId,
            ]);

        } elseif ($tipoNodoSiguiente === 'end') {
            // Es un nodo final: Marcar ejecución como completada
            Log::info('EnviarEtapaJob: Nodo final alcanzado, completando ejecución');

            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);
        } else {
            Log::warning('EnviarEtapaJob: Tipo de nodo desconocido', [
                'tipo' => $tipoNodoSiguiente,
                'node_id' => $targetNodeId,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Exception $exception): void
    {
        Log::error('EnviarEtapaJob: Falló permanentemente', [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'error' => $exception->getMessage(),
        ]);

        // Marcar etapa como fallida
        $etapaEjecucion = FlujoEjecucionEtapa::find($this->etapaEjecucionId);
        if ($etapaEjecucion) {
            $etapaEjecucion->update([
                'estado' => 'failed',
                'error_mensaje' => "Job falló después de {$this->tries} intentos: {$exception->getMessage()}",
            ]);
        }
    }
}
