<?php

declare(strict_types=1);

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

/**
 * Job para procesar un lote individual de envíos.
 *
 * Se encarga de enviar mensajes a un subconjunto de prospectos,
 * actualizar el progreso del batching, y procesar el siguiente paso
 * solo cuando es el último lote.
 *
 * Principio SOLID: Single Responsibility - solo procesa un lote.
 */
class EnviarLoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutos (lotes pueden ser grandes)

    public $tries = 3;

    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public function __construct(
        public readonly int $flujoEjecucionId,
        public readonly int $etapaEjecucionId,
        public readonly array $prospectoIds,
        public readonly array $stage,
        public readonly array $branches,
        public readonly int $batchNumber,
        public readonly int $totalBatches,
        public readonly bool $isLastBatch
    ) {
        $this->afterCommit();
    }

    /**
     * Ejecuta el procesamiento del lote.
     */
    public function handle(EnvioService $envioService): void
    {
        $logContext = $this->buildLogContext();

        Log::info('EnviarLoteJob: Iniciando lote', $logContext);

        try {
            $this->processLote($envioService);
        } catch (\Exception $e) {
            $this->handleError($e, $logContext);
            throw $e;
        }
    }

    /**
     * Procesa el lote de envíos.
     */
    private function processLote(EnvioService $envioService): void
    {
        DB::beginTransaction();

        try {
            $ejecucion = $this->getEjecucion();
            $etapaEjecucion = $this->getEtapaEjecucion();

            // Early return: si la etapa fue cancelada o ya completó
            if ($this->shouldSkipBatch($etapaEjecucion)) {
                DB::commit();
                return;
            }

            // Obtener prospectos para este lote
            $prospectosEnFlujo = $this->obtenerProspectosEnFlujo($ejecucion);

            // Early return: sin prospectos válidos
            if ($prospectosEnFlujo->isEmpty()) {
                Log::warning('EnviarLoteJob: Lote sin prospectos válidos', [
                    'lote' => $this->batchNumber,
                ]);
                $this->updateBatchProgress($etapaEjecucion);
                DB::commit();
                return;
            }

            // Obtener contenido del mensaje
            $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
            $contenidoData = $this->obtenerContenidoMensaje($tipoMensaje);

            // Enviar el lote
            $response = $this->enviarLote($envioService, $prospectosEnFlujo, $tipoMensaje, $contenidoData, $ejecucion);

            // Actualizar progreso
            $this->updateBatchProgress($etapaEjecucion, $response);

            // Si es el último lote, procesar siguiente paso
            if ($this->isLastBatch) {
                $this->finalizarEtapaYProcesarSiguiente($ejecucion, $etapaEjecucion, $response);
            }

            // Registrar job completado
            $this->registrarJobCompletado($response);

            DB::commit();

            Log::info('EnviarLoteJob: Lote completado', [
                'lote' => $this->batchNumber,
                'de' => $this->totalBatches,
                'es_ultimo' => $this->isLastBatch,
                'prospectos_enviados' => $prospectosEnFlujo->count(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Verifica si el lote debe saltarse.
     */
    private function shouldSkipBatch(FlujoEjecucionEtapa $etapaEjecucion): bool
    {
        $skipStates = ['completed', 'failed', 'cancelled'];

        if (in_array($etapaEjecucion->estado, $skipStates)) {
            Log::info('EnviarLoteJob: Saltando lote - etapa en estado final', [
                'estado' => $etapaEjecucion->estado,
                'lote' => $this->batchNumber,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Envía los mensajes del lote.
     */
    private function enviarLote(
        EnvioService $envioService,
        \Illuminate\Support\Collection $prospectosEnFlujo,
        string $tipoMensaje,
        array $contenidoData,
        FlujoEjecucion $ejecucion
    ): array {
        Log::info('EnviarLoteJob: Enviando mensajes', [
            'lote' => $this->batchNumber,
            'tipo' => $tipoMensaje,
            'prospectos' => $prospectosEnFlujo->count(),
        ]);

        return $envioService->enviar(
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
    }

    /**
     * Actualiza el progreso del batching en la etapa.
     */
    private function updateBatchProgress(FlujoEjecucionEtapa $etapaEjecucion, ?array $envioResponse = null): void
    {
        $currentResponse = $etapaEjecucion->response_athenacampaign ?? [];
        $batchingInfo = $currentResponse['batching'] ?? [];

        $batchingInfo['batches_completed'] = ($batchingInfo['batches_completed'] ?? 0) + 1;
        $batchingInfo['last_batch_at'] = now()->toIso8601String();

        // Agregar info del último lote
        $batchingInfo['batches'][$this->batchNumber] = [
            'completed_at' => now()->toIso8601String(),
            'prospectos_count' => count($this->prospectoIds),
            'response' => $envioResponse ? [
                'exitosos' => $envioResponse['mensaje']['Recipients'] ?? 0,
                'errores' => $envioResponse['mensaje']['Errores'] ?? 0,
            ] : null,
        ];

        $currentResponse['batching'] = $batchingInfo;

        $etapaEjecucion->update([
            'response_athenacampaign' => $currentResponse,
        ]);
    }

    /**
     * Finaliza la etapa y procesa el siguiente paso (solo para último lote).
     */
    private function finalizarEtapaYProcesarSiguiente(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        array $response
    ): void {
        Log::info('EnviarLoteJob: Último lote - finalizando etapa', [
            'etapa_id' => $this->etapaEjecucionId,
        ]);

        $messageId = $response['mensaje']['messageID'] ?? rand(10000, 99999);

        // Actualizar etapa como completada
        $currentResponse = $etapaEjecucion->response_athenacampaign ?? [];
        $currentResponse['batching']['completed_at'] = now()->toIso8601String();
        $currentResponse['batching']['status'] = 'completed';

        $etapaEjecucion->update([
            'message_id' => $messageId,
            'response_athenacampaign' => $currentResponse,
            'estado' => 'completed',
            'fecha_ejecucion' => now(),
        ]);

        // Procesar siguiente paso del flujo
        $this->procesarSiguientePaso($ejecucion, $etapaEjecucion, $messageId);
    }

    /**
     * Obtiene los prospectos para este lote.
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
     * Obtiene el contenido del mensaje.
     */
    private function obtenerContenidoMensaje(string $tipoMensaje): array
    {
        $stageId = $this->stage['id'] ?? null;

        if ($stageId) {
            $flujoEtapa = FlujoEtapa::find($stageId);

            if ($flujoEtapa && $flujoEtapa->usaPlantillaReferencia()) {
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
     * Procesa el siguiente paso del flujo.
     * Código extraído de EnviarEtapaJob para reutilización.
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
            Log::info('EnviarLoteJob: No hay siguiente paso (fin del flujo)');
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);
            return;
        }

        $primeraConexion = $conexionesDesdeEsta->first();
        $targetNodeId = $primeraConexion['target_node_id'];

        // Early return: nodo final
        if (str_starts_with($targetNodeId, 'end-')) {
            Log::info('EnviarLoteJob: Nodo final alcanzado');
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
            Log::warning('EnviarLoteJob: Nodo destino no encontrado', [
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
        }
    }

    /**
     * Programa la verificación de una condición.
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
     * Programa la siguiente etapa.
     */
    private function programarSiguienteEtapa(
        FlujoEjecucion $ejecucion,
        array $targetNode,
        string $targetNodeId
    ): void {
        $tiempoEspera = $targetNode['tiempo_espera'] ?? 0;
        $fechaProgramada = now()->addDays($tiempoEspera);

        $siguienteEtapaEjecucion = FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_id' => null,
            'node_id' => $targetNodeId,
            'fecha_programada' => $fechaProgramada,
            'estado' => 'pending',
        ]);

        // Obtener todos los prospectoIds originales de la ejecución
        $allProspectoIds = $this->getAllProspectoIdsFromEjecucion($ejecucion);

        EnviarEtapaJob::dispatch(
            $this->flujoEjecucionId,
            $siguienteEtapaEjecucion->id,
            $targetNode,
            $allProspectoIds,
            $this->branches
        )->delay($fechaProgramada);

        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaProgramada,
        ]);
    }

    /**
     * Obtiene todos los IDs de prospectos de la ejecución.
     */
    private function getAllProspectoIdsFromEjecucion(FlujoEjecucion $ejecucion): array
    {
        return ProspectoEnFlujo::where('flujo_id', $ejecucion->flujo_id)
            ->pluck('prospecto_id')
            ->toArray();
    }

    /**
     * Registra el job como completado.
     */
    private function registrarJobCompletado(array $response): void
    {
        FlujoJob::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'job_type' => 'enviar_lote',
            'job_id' => $this->job?->uuid(),
            'job_data' => [
                'etapa_id' => $this->etapaEjecucionId,
                'lote' => $this->batchNumber,
                'total_lotes' => $this->totalBatches,
                'prospectos_count' => count($this->prospectoIds),
                'es_ultimo' => $this->isLastBatch,
            ],
            'estado' => 'completed',
            'fecha_queued' => now(),
            'fecha_procesado' => now(),
        ]);
    }

    /**
     * Maneja errores del job.
     */
    private function handleError(\Exception $e, array $logContext): void
    {
        Log::error('EnviarLoteJob: Error', array_merge($logContext, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]));

        $etapaEjecucion = FlujoEjecucionEtapa::find($this->etapaEjecucionId);

        if ($etapaEjecucion) {
            $currentResponse = $etapaEjecucion->response_athenacampaign ?? [];
            $currentResponse['batching']['batches'][$this->batchNumber] = [
                'failed_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ];

            $etapaEjecucion->update([
                'response_athenacampaign' => $currentResponse,
                'error_mensaje' => "Lote {$this->batchNumber} falló: {$e->getMessage()}",
            ]);
        }

        FlujoJob::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'job_type' => 'enviar_lote',
            'job_id' => $this->job?->uuid(),
            'job_data' => [
                'etapa_id' => $this->etapaEjecucionId,
                'lote' => $this->batchNumber,
                'total_lotes' => $this->totalBatches,
            ],
            'estado' => 'failed',
            'fecha_queued' => now(),
            'error_details' => $e->getMessage(),
            'intentos' => $this->attempts(),
        ]);
    }

    /**
     * Construye contexto para logging.
     */
    private function buildLogContext(): array
    {
        return [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'lote' => $this->batchNumber,
            'de' => $this->totalBatches,
            'prospectos' => count($this->prospectoIds),
            'es_ultimo' => $this->isLastBatch,
            'stage_label' => $this->stage['label'] ?? 'Unknown',
        ];
    }

    /**
     * Obtiene la ejecución del flujo.
     */
    private function getEjecucion(): FlujoEjecucion
    {
        return FlujoEjecucion::findOrFail($this->flujoEjecucionId);
    }

    /**
     * Obtiene la etapa de ejecución.
     */
    private function getEtapaEjecucion(): FlujoEjecucionEtapa
    {
        return FlujoEjecucionEtapa::findOrFail($this->etapaEjecucionId);
    }

    /**
     * Maneja fallo permanente del job.
     */
    public function failed(\Exception $exception): void
    {
        Log::error('EnviarLoteJob: Falló permanentemente', [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'lote' => $this->batchNumber,
            'error' => $exception->getMessage(),
        ]);

        $etapaEjecucion = FlujoEjecucionEtapa::find($this->etapaEjecucionId);

        if ($etapaEjecucion) {
            $etapaEjecucion->update([
                'estado' => 'failed',
                'error_mensaje' => "Lote {$this->batchNumber} falló permanentemente: {$exception->getMessage()}",
            ]);
        }
    }
}
