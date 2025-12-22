<?php

namespace App\Jobs;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoEtapa;
use App\Models\FlujoJob;
use App\Models\ProspectoEnFlujo;
use App\Services\Batching\EnvioBatchService;
use App\Services\EnvioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job para enviar mensajes de una etapa del flujo.
 *
 * Ahora soporta batching automático: si hay más de 20,000 prospectos,
 * los divide en lotes y los encola con delays para evitar saturar el servidor.
 */
class EnviarEtapaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutos

    public $tries = 3;

    public $backoff = [60, 300, 900]; // Reintentos: 1min, 5min, 15min

    public function __construct(
        public int $flujoEjecucionId,
        public int $etapaEjecucionId,
        public array $stage,
        public array $prospectoIds,
        public array $branches = []
    ) {
        $this->afterCommit();
    }

    /**
     * Ejecuta el job.
     *
     * Decide automáticamente si usar batching basándose en la cantidad de prospectos.
     */
    public function handle(EnvioService $envioService, EnvioBatchService $batchService): void
    {
        Log::info('EnviarEtapaJob: Iniciado', [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'stage_label' => $this->stage['label'] ?? 'Unknown',
            'total_prospectos' => count($this->prospectoIds),
        ]);

        // Verificar si necesita batching
        if ($this->shouldUseBatching($batchService)) {
            $this->handleWithBatching($batchService);
            return;
        }

        // Envío directo (sin batching)
        $this->handleDirectSend($envioService);
    }

    /**
     * Determina si debe usar batching.
     */
    private function shouldUseBatching(EnvioBatchService $batchService): bool
    {
        $totalProspectos = count($this->prospectoIds);
        $shouldBatch = $batchService->shouldUseBatching($totalProspectos);

        Log::info('EnviarEtapaJob: Evaluando batching', [
            'total_prospectos' => $totalProspectos,
            'threshold' => $batchService->getConfig()['threshold'],
            'requiere_batching' => $shouldBatch,
        ]);

        return $shouldBatch;
    }

    /**
     * Maneja el envío usando batching.
     */
    private function handleWithBatching(EnvioBatchService $batchService): void
    {
        Log::info('EnviarEtapaJob: Usando batching', [
            'prospectos' => count($this->prospectoIds),
            'config' => $batchService->getConfig(),
        ]);

        DB::beginTransaction();

        try {
            $ejecucion = FlujoEjecucion::findOrFail($this->flujoEjecucionId);
            $etapaEjecucion = FlujoEjecucionEtapa::findOrFail($this->etapaEjecucionId);

            // Early return: ya completada
            if ($etapaEjecucion->estado === 'completed') {
                Log::warning('EnviarEtapaJob: Etapa ya completada');
                DB::commit();
                return;
            }

            // Actualizar estados
            $etapaEjecucion->update(['estado' => 'batching']);
            $ejecucion->update(['estado' => 'in_progress']);

            // Dispatch de los lotes
            $result = $batchService->dispatchBatches(
                $this->flujoEjecucionId,
                $this->etapaEjecucionId,
                $this->prospectoIds,
                $this->stage,
                $this->branches
            );

            // Registrar job como completado (la orquestación)
            FlujoJob::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'job_type' => 'enviar_etapa_batching',
                'job_id' => $this->job?->uuid(),
                'job_data' => [
                    'etapa_id' => $this->etapaEjecucionId,
                    'stage' => $this->stage,
                    'prospectos_count' => count($this->prospectoIds),
                    'batching' => $result->toArray(),
                ],
                'estado' => 'completed',
                'fecha_queued' => now(),
                'fecha_procesado' => now(),
            ]);

            DB::commit();

            Log::info('EnviarEtapaJob: Batching iniciado exitosamente', [
                'total_lotes' => $result->totalBatches,
                'tiempo_estimado_minutos' => $result->getEstimatedCompletionMinutes(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e);
            throw $e;
        }
    }

    /**
     * Maneja el envío directo (sin batching).
     */
    private function handleDirectSend(EnvioService $envioService): void
    {
        Log::info('EnviarEtapaJob: Envío directo (sin batching)', [
            'prospectos' => count($this->prospectoIds),
        ]);

        DB::beginTransaction();

        try {
            $ejecucion = FlujoEjecucion::findOrFail($this->flujoEjecucionId);
            $etapaEjecucion = FlujoEjecucionEtapa::findOrFail($this->etapaEjecucionId);

            // Early return: ya completada
            if ($etapaEjecucion->estado === 'completed') {
                Log::warning('EnviarEtapaJob: Etapa ya completada');
                DB::commit();
                return;
            }

            // Actualizar estados
            $etapaEjecucion->update(['estado' => 'executing']);
            $ejecucion->update(['estado' => 'in_progress']);

            // Obtener prospectos
            $prospectosEnFlujo = $this->obtenerProspectosEnFlujo($ejecucion);

            // Obtener contenido del mensaje
            $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
            $contenidoData = $this->obtenerContenidoMensaje($tipoMensaje);

            Log::info('EnviarEtapaJob: Enviando mensaje', [
                'tipo' => $tipoMensaje,
                'prospectos' => count($this->prospectoIds),
                'es_html' => $contenidoData['es_html'],
            ]);

            // Enviar
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

            $messageId = $response['mensaje']['messageID'] ?? null;

            if (! $messageId) {
                throw new \Exception('No se recibió messageID');
            }

            Log::info('EnviarEtapaJob: Mensaje enviado', [
                'message_id' => $messageId,
                'destinatarios' => $response['mensaje']['Recipients'] ?? 0,
            ]);

            // Actualizar etapa
            $etapaEjecucion->update([
                'message_id' => $messageId,
                'response_athenacampaign' => $response,
                'estado' => 'completed',
                'fecha_ejecucion' => now(),
            ]);

            // Registrar job
            FlujoJob::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'job_type' => 'enviar_etapa',
                'job_id' => $this->job?->uuid(),
                'job_data' => [
                    'etapa_id' => $this->etapaEjecucionId,
                    'stage' => $this->stage,
                    'prospectos_count' => count($this->prospectoIds),
                ],
                'estado' => 'completed',
                'fecha_queued' => now(),
                'fecha_procesado' => now(),
            ]);

            // Procesar siguiente paso
            $this->procesarSiguientePaso($ejecucion, $etapaEjecucion, $messageId);

            DB::commit();

            Log::info('EnviarEtapaJob: Completado exitosamente');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e);
            throw $e;
        }
    }

    /**
     * Maneja errores del job.
     */
    private function handleError(\Exception $e): void
    {
        Log::error('EnviarEtapaJob: Error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $etapaEjecucion = FlujoEjecucionEtapa::find($this->etapaEjecucionId);

        if ($etapaEjecucion) {
            $etapaEjecucion->update([
                'estado' => 'failed',
                'error_mensaje' => $e->getMessage(),
            ]);
        }

        FlujoJob::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'job_type' => 'enviar_etapa',
            'job_id' => $this->job?->uuid(),
            'job_data' => [
                'etapa_id' => $this->etapaEjecucionId,
                'stage' => $this->stage,
            ],
            'estado' => 'failed',
            'fecha_queued' => now(),
            'error_details' => $e->getMessage(),
            'intentos' => $this->attempts(),
        ]);
    }

    /**
     * Obtiene el contenido del mensaje.
     */
    private function obtenerContenidoMensaje(string $tipoMensaje): array
    {
        $stageId = $this->stage['id'] ?? null;

        if ($stageId) {
            $flujoEtapa = FlujoEtapa::find($stageId);

            if ($flujoEtapa && $flujoEtapa->usaPlantillaReferencia()) {
                Log::info('EnviarEtapaJob: Usando plantilla de referencia', [
                    'stage_id' => $stageId,
                    'plantilla_id' => $flujoEtapa->plantilla_id,
                ]);

                return $flujoEtapa->obtenerContenidoParaEnvio($tipoMensaje);
            }
        }

        return [
            'contenido' => $this->stage['plantilla_mensaje'] ?? '',
            'asunto' => $this->stage['template']['asunto'] ?? null,
            'es_html' => false,
        ];
    }

    /**
     * Obtiene o crea ProspectoEnFlujo para los prospectos.
     */
    private function obtenerProspectosEnFlujo(FlujoEjecucion $ejecucion): \Illuminate\Support\Collection
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $prospectosEnFlujo = collect();

        foreach ($this->prospectoIds as $prospectoId) {
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
     * Procesa el siguiente paso del flujo.
     */
    private function procesarSiguientePaso(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        int $messageId
    ): void {
        $conexionesDesdeEsta = collect($this->branches)->filter(function ($branch) {
            return $branch['source_node_id'] === $this->stage['id'];
        });

        // Early return: no hay siguiente paso
        if ($conexionesDesdeEsta->isEmpty()) {
            Log::info('EnviarEtapaJob: No hay siguiente paso (fin del flujo)');
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);
            return;
        }

        $primeraConexion = $conexionesDesdeEsta->first();
        $targetNodeId = $primeraConexion['target_node_id'];

        // Early return: nodo final (end-*)
        if (str_starts_with($targetNodeId, 'end-')) {
            Log::info('EnviarEtapaJob: Nodo final alcanzado');
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);
            return;
        }

        // Buscar nodo destino
        $flujoData = $ejecucion->flujo->flujo_data;
        $stages = $flujoData['stages'] ?? [];
        $targetNode = collect($stages)->firstWhere('id', $targetNodeId);

        if (! $targetNode) {
            Log::warning('EnviarEtapaJob: Nodo destino no encontrado', [
                'target_node_id' => $targetNodeId,
            ]);
            return;
        }

        $tipoNodoSiguiente = $targetNode['type'] ?? 'stage';

        if ($tipoNodoSiguiente === 'condition') {
            $this->programarVerificacionCondicion($ejecucion, $etapaEjecucion, $primeraConexion, $messageId, $targetNodeId);
        } elseif ($tipoNodoSiguiente === 'stage') {
            $this->programarSiguienteEtapa($ejecucion, $targetNode, $targetNodeId);
        } elseif ($tipoNodoSiguiente === 'end') {
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);
        } else {
            Log::warning('EnviarEtapaJob: Tipo de nodo desconocido', [
                'tipo' => $tipoNodoSiguiente,
            ]);
        }
    }

    /**
     * Programa verificación de condición.
     */
    private function programarVerificacionCondicion(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        array $conexion,
        int $messageId,
        string $targetNodeId
    ): void {
        $tiempoVerificacion = $this->stage['tiempo_verificacion_condicion'] ?? 24;
        $fechaVerificacion = now()->addHours($tiempoVerificacion);

        Log::info('EnviarEtapaJob: Programando verificación de condición', [
            'en_horas' => $tiempoVerificacion,
            'condition_node_id' => $targetNodeId,
        ]);

        VerificarCondicionJob::dispatch(
            $this->flujoEjecucionId,
            $etapaEjecucion->id,
            $conexion,
            $messageId
        )->delay($fechaVerificacion);

        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaVerificacion,
        ]);
    }

    /**
     * Programa siguiente etapa.
     */
    private function programarSiguienteEtapa(
        FlujoEjecucion $ejecucion,
        array $targetNode,
        string $targetNodeId
    ): void {
        $tiempoEspera = $targetNode['tiempo_espera'] ?? 0;
        $fechaProgramada = now()->addDays($tiempoEspera);

        Log::info('EnviarEtapaJob: Programando siguiente etapa', [
            'siguiente_node_id' => $targetNodeId,
            'tiempo_espera_dias' => $tiempoEspera,
        ]);

        $siguienteEtapaEjecucion = FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_id' => null,
            'node_id' => $targetNodeId,
            'fecha_programada' => $fechaProgramada,
            'estado' => 'pending',
        ]);

        EnviarEtapaJob::dispatch(
            $this->flujoEjecucionId,
            $siguienteEtapaEjecucion->id,
            $targetNode,
            $this->prospectoIds,
            $this->branches
        )->delay($fechaProgramada);

        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaProgramada,
        ]);
    }

    /**
     * Maneja fallo permanente.
     */
    public function failed(\Exception $exception): void
    {
        Log::error('EnviarEtapaJob: Falló permanentemente', [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'error' => $exception->getMessage(),
        ]);

        $etapaEjecucion = FlujoEjecucionEtapa::find($this->etapaEjecucionId);

        if ($etapaEjecucion) {
            $etapaEjecucion->update([
                'estado' => 'failed',
                'error_mensaje' => "Job falló después de {$this->tries} intentos: {$exception->getMessage()}",
            ]);
        }
    }
}
