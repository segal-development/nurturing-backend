<?php

namespace App\Jobs;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoEtapa;
use App\Models\FlujoJob;
use App\Models\ProspectoEnFlujo;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job orquestador para enviar mensajes de una etapa de flujo.
 *
 * Para volúmenes grandes (>5000), despacha EnviarEtapaChunkJob que procesan
 * en chunks pequeños sin cargar todo en memoria.
 */
class EnviarEtapaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [60, 300, 900];

    private const CHUNK_SIZE = 100;

    public function __construct(
        public int $flujoEjecucionId,
        public int $etapaEjecucionId,
        public array $stage,
        public array $prospectoIds,
        public array $branches = []
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $totalProspectos = count($this->prospectoIds);
        
        Log::info('EnviarEtapaJob: Iniciando', [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'stage_label' => $this->stage['label'] ?? 'Unknown',
            'prospectos_count' => $totalProspectos,
        ]);

        $ejecucion = $this->loadEjecucion();
        if (! $ejecucion) {
            return;
        }

        $etapaEjecucion = $this->loadEtapaEjecucion();
        if (! $etapaEjecucion) {
            return;
        }

        if ($this->isAlreadyCompleted($etapaEjecucion)) {
            return;
        }

        $this->updateInitialStates($ejecucion, $etapaEjecucion);
        
        // Para volúmenes grandes (>5000), usar estrategia de sub-jobs
        if ($totalProspectos > 5000) {
            $this->handleLargeVolume($ejecucion, $etapaEjecucion);
            return;
        }
        
        // Volumen normal: procesar todo de una
        $prospectosEnFlujo = $this->obtenerProspectosEnFlujo($ejecucion);
        $contenidoData = $this->obtenerContenidoMensaje();
        $jobs = $this->createBatchJobs($prospectosEnFlujo, $contenidoData, $ejecucion);

        if (empty($jobs)) {
            $this->handleNoProspectos($etapaEjecucion, $ejecucion);
            return;
        }

        $this->dispatchBatch($jobs, $ejecucion, $etapaEjecucion, $contenidoData);
    }
    
    /**
     * Maneja volúmenes grandes (>5000 prospectos) SIN cargar todo en memoria.
     * 
     * Despacha sub-jobs (EnviarEtapaChunkJob) que procesan chunks de 1000 prospectos.
     * Cada sub-job obtiene sus prospectos de la BD usando offset/limit.
     */
    private function handleLargeVolume(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion): void
    {
        $totalProspectos = count($this->prospectoIds);
        $chunkSize = 1000;
        $totalChunks = (int) ceil($totalProspectos / $chunkSize);
        
        Log::info('EnviarEtapaJob: Modo volumen grande - despachando sub-jobs', [
            'total_prospectos' => $totalProspectos,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
        ]);

        // Guardar metadata del procesamiento
        $etapaEjecucion->update([
            'response_athenacampaign' => [
                'modo' => 'large_volume_chunked',
                'total_prospectos' => $totalProspectos,
                'total_chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
                'chunks_dispatched' => 0,
                'started_at' => now()->toIso8601String(),
            ],
        ]);

        // Despachar sub-jobs para cada chunk SIN cargar los IDs en memoria
        for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
            $offset = $chunkIndex * $chunkSize;
            
            EnviarEtapaChunkJob::dispatch(
                flujoEjecucionId: $this->flujoEjecucionId,
                etapaEjecucionId: $this->etapaEjecucionId,
                stage: $this->stage,
                flujoId: $ejecucion->flujo_id,
                offset: $offset,
                limit: $chunkSize,
                chunkIndex: $chunkIndex,
                totalChunks: $totalChunks,
                branches: $this->branches
            )->onQueue('envios');
        }
        
        // Actualizar contador
        $etapaEjecucion->update([
            'response_athenacampaign' => [
                'modo' => 'large_volume_chunked',
                'total_prospectos' => $totalProspectos,
                'total_chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
                'chunks_dispatched' => $totalChunks,
                'started_at' => now()->toIso8601String(),
            ],
        ]);

        Log::info('EnviarEtapaJob: Sub-jobs despachados', [
            'total_chunks' => $totalChunks,
        ]);
    }

    private function loadEjecucion(): ?FlujoEjecucion
    {
        $ejecucion = FlujoEjecucion::find($this->flujoEjecucionId);

        if (! $ejecucion) {
            Log::error('EnviarEtapaJob: Ejecución no encontrada', [
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
            ]);
        }

        return $ejecucion;
    }

    private function loadEtapaEjecucion(): ?FlujoEjecucionEtapa
    {
        $etapa = FlujoEjecucionEtapa::find($this->etapaEjecucionId);

        if (! $etapa) {
            Log::error('EnviarEtapaJob: Etapa de ejecución no encontrada', [
                'etapa_ejecucion_id' => $this->etapaEjecucionId,
            ]);
        }

        return $etapa;
    }

    private function isAlreadyCompleted(FlujoEjecucionEtapa $etapaEjecucion): bool
    {
        if ($etapaEjecucion->estado === 'completed') {
            Log::warning('EnviarEtapaJob: Etapa ya completada', [
                'etapa_id' => $this->etapaEjecucionId,
            ]);
            return true;
        }
        return false;
    }

    private function updateInitialStates(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion): void
    {
        $etapaEjecucion->update(['estado' => 'executing']);
        $ejecucion->update(['estado' => 'in_progress']);
    }

    private function handleNoProspectos(FlujoEjecucionEtapa $etapaEjecucion, FlujoEjecucion $ejecucion): void
    {
        Log::warning('EnviarEtapaJob: No hay prospectos para enviar', [
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
        ]);

        $etapaEjecucion->update([
            'estado' => 'completed',
            'fecha_ejecucion' => now(),
            'response_athenacampaign' => ['mensaje' => 'No hay prospectos'],
        ]);

        $this->procesarSiguientePaso($ejecucion, $etapaEjecucion, 0);
    }

    private function createBatchJobs(\Illuminate\Support\Collection $prospectosEnFlujo, array $contenidoData, FlujoEjecucion $ejecucion): array
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $jobs = [];

        foreach ($prospectosEnFlujo as $prospectoEnFlujo) {
            $job = $this->createJobForProspecto(
                prospectoEnFlujo: $prospectoEnFlujo,
                contenidoData: $contenidoData,
                tipoMensaje: $tipoMensaje,
                flujoId: $ejecucion->flujo_id
            );

            if ($job) {
                $jobs[] = $job;
            }
        }

        Log::info('EnviarEtapaJob: Jobs creados', [
            'total_jobs' => count($jobs),
            'tipo_mensaje' => $tipoMensaje,
        ]);

        return $jobs;
    }

    private function createJobForProspecto(ProspectoEnFlujo $prospectoEnFlujo, array $contenidoData, string $tipoMensaje, int $flujoId): ?ShouldQueue
    {
        if ($tipoMensaje === 'sms') {
            return new EnviarSmsEtapaProspectoJob(
                prospectoEnFlujoId: $prospectoEnFlujo->id,
                contenido: $contenidoData['contenido'],
                flujoId: $flujoId,
                etapaEjecucionId: $this->etapaEjecucionId
            );
        }

        return new EnviarEmailEtapaProspectoJob(
            prospectoEnFlujoId: $prospectoEnFlujo->id,
            contenido: $contenidoData['contenido'],
            asunto: $contenidoData['asunto'] ?? $this->stage['template']['asunto'] ?? 'Mensaje',
            flujoId: $flujoId,
            etapaEjecucionId: $this->etapaEjecucionId,
            esHtml: $contenidoData['es_html']
        );
    }

    private function dispatchBatch(array $jobs, FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion, array $contenidoData): void
    {
        $batchName = sprintf(
            'Etapa %s - Flujo %d (%d prospectos)',
            $this->stage['label'] ?? 'Sin nombre',
            $ejecucion->flujo_id,
            count($jobs)
        );

        $callbackData = [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'stage' => $this->stage,
            'prospecto_ids' => $this->prospectoIds,
            'branches' => $this->branches,
            'total_jobs' => count($jobs),
        ];

        $batch = Bus::batch($jobs)
            ->name($batchName)
            ->onQueue('envios')
            ->allowFailures()
            ->then(function (Batch $batch) use ($callbackData) {
                $this->onBatchCompleted($batch, $callbackData);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($callbackData) {
                $this->onBatchFailed($batch, $e, $callbackData);
            })
            ->finally(function (Batch $batch) use ($callbackData) {
                $this->onBatchFinished($batch, $callbackData);
            })
            ->dispatch();

        Log::info('EnviarEtapaJob: Batch despachado', [
            'batch_id' => $batch->id,
            'total_jobs' => count($jobs),
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
        ]);

        FlujoJob::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'job_type' => 'enviar_etapa_batch',
            'job_id' => $batch->id,
            'job_data' => [
                'etapa_id' => $this->etapaEjecucionId,
                'stage' => $this->stage,
                'prospectos_count' => count($jobs),
                'batch_id' => $batch->id,
            ],
            'estado' => 'processing',
            'fecha_queued' => now(),
        ]);

        $etapaEjecucion->update([
            'response_athenacampaign' => [
                'batch_id' => $batch->id,
                'total_jobs' => count($jobs),
                'estado' => 'batch_processing',
            ],
        ]);
    }

    private function onBatchCompleted(Batch $batch, array $data): void
    {
        Log::info('EnviarEtapaJob: Batch completado', [
            'batch_id' => $batch->id,
            'processed' => $batch->processedJobs(),
            'failed' => $batch->failedJobs,
            'etapa_ejecucion_id' => $data['etapa_ejecucion_id'],
        ]);

        $etapaEjecucion = FlujoEjecucionEtapa::find($data['etapa_ejecucion_id']);
        $ejecucion = FlujoEjecucion::find($data['flujo_ejecucion_id']);

        if (! $etapaEjecucion || ! $ejecucion) {
            Log::error('EnviarEtapaJob: No se encontraron modelos en callback', $data);
            return;
        }

        $messageId = rand(10000, 99999);

        $etapaEjecucion->update([
            'message_id' => $messageId,
            'estado' => 'completed',
            'ejecutado' => true,
            'fecha_ejecucion' => now(),
            'response_athenacampaign' => [
                'batch_id' => $batch->id,
                'messageID' => $messageId,
                'Recipients' => $batch->processedJobs() - $batch->failedJobs,
                'Errores' => $batch->failedJobs,
                'total_jobs' => $data['total_jobs'],
            ],
        ]);

        FlujoJob::where('job_id', $batch->id)
            ->where('job_type', 'enviar_etapa_batch')
            ->update([
                'estado' => 'completed',
                'fecha_procesado' => now(),
            ]);

        $this->stage = $data['stage'];
        $this->branches = $data['branches'];
        $this->prospectoIds = $data['prospecto_ids'];
        $this->flujoEjecucionId = $data['flujo_ejecucion_id'];
        $this->etapaEjecucionId = $data['etapa_ejecucion_id'];

        $this->procesarSiguientePaso($ejecucion, $etapaEjecucion, $messageId);
    }

    private function onBatchFailed(Batch $batch, Throwable $e, array $data): void
    {
        Log::error('EnviarEtapaJob: Batch con errores', [
            'batch_id' => $batch->id,
            'error' => $e->getMessage(),
            'failed_jobs' => $batch->failedJobs,
            'etapa_ejecucion_id' => $data['etapa_ejecucion_id'],
        ]);
    }

    private function onBatchFinished(Batch $batch, array $data): void
    {
        Log::info('EnviarEtapaJob: Batch finalizado', [
            'batch_id' => $batch->id,
            'total_processed' => $batch->processedJobs(),
            'total_failed' => $batch->failedJobs,
            'pending' => $batch->pendingJobs,
        ]);
    }

    private function obtenerContenidoMensaje(): array
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
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

        $contenido = $this->stage['plantilla_mensaje'] ?? $this->stage['data']['contenido'] ?? '';
        $esHtml = $this->detectarSiEsHtml($contenido);

        return [
            'contenido' => $contenido,
            'asunto' => $this->stage['template']['asunto'] ?? $this->stage['data']['template']['asunto'] ?? null,
            'es_html' => $esHtml,
        ];
    }

    private function detectarSiEsHtml(string $contenido): bool
    {
        $htmlPatterns = ['/<html/i', '/<body/i', '/<div/i', '/<p>/i', '/<br/i', '/<table/i', '/<a\s+href/i'];

        foreach ($htmlPatterns as $pattern) {
            if (preg_match($pattern, $contenido)) {
                return true;
            }
        }

        return false;
    }

    private function obtenerProspectosEnFlujo(FlujoEjecucion $ejecucion): \Illuminate\Support\Collection
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $flujoId = $ejecucion->flujo_id;
        
        // Obtener IDs existentes
        $existingIds = ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereIn('prospecto_id', $this->prospectoIds)
            ->pluck('prospecto_id')
            ->toArray();

        $idsToCreate = array_diff($this->prospectoIds, $existingIds);

        // Crear los nuevos en batch
        if (!empty($idsToCreate)) {
            $now = now();
            $chunks = array_chunk($idsToCreate, 1000);
            
            foreach ($chunks as $chunk) {
                $insertData = array_map(function ($prospectoId) use ($flujoId, $tipoMensaje, $now) {
                    return [
                        'prospecto_id' => $prospectoId,
                        'flujo_id' => $flujoId,
                        'canal_asignado' => $tipoMensaje,
                        'estado' => 'en_proceso',
                        'fecha_inicio' => $now,
                        'completado' => false,
                        'cancelado' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $chunk);

                ProspectoEnFlujo::insert($insertData);
            }
        }

        return ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereIn('prospecto_id', $this->prospectoIds)
            ->cursor()
            ->collect();
    }

    private function procesarSiguientePaso(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion, int $messageId): void
    {
        $conexionesDesdeEsta = collect($this->branches)->filter(function ($branch) {
            return $branch['source_node_id'] === $this->stage['id'];
        });

        if ($conexionesDesdeEsta->isEmpty()) {
            $this->finalizarFlujo($ejecucion);
            return;
        }

        $primeraConexion = $conexionesDesdeEsta->first();
        $targetNodeId = $primeraConexion['target_node_id'];

        if ($this->isEndNode($targetNodeId)) {
            $this->finalizarFlujo($ejecucion);
            return;
        }

        $targetNode = $this->findTargetNode($ejecucion, $targetNodeId);
        if (! $targetNode) {
            return;
        }

        $this->procesarNodoSiguiente($ejecucion, $etapaEjecucion, $targetNode, $targetNodeId, $primeraConexion, $messageId);
    }

    private function isEndNode(string $nodeId): bool
    {
        return str_starts_with($nodeId, 'end-');
    }

    private function finalizarFlujo(FlujoEjecucion $ejecucion): void
    {
        Log::info('EnviarEtapaJob: Finalizando flujo');
        $ejecucion->update([
            'estado' => 'completed',
            'fecha_fin' => now(),
        ]);
    }

    private function findTargetNode(FlujoEjecucion $ejecucion, string $targetNodeId): ?array
    {
        $flujoData = $ejecucion->flujo->flujo_data;
        $stages = $flujoData['stages'] ?? [];
        $conditions = $flujoData['conditions'] ?? [];
        
        $targetNode = collect($stages)->firstWhere('id', $targetNodeId);
        
        if (!$targetNode) {
            $targetNode = collect($conditions)->firstWhere('id', $targetNodeId);
        }

        return $targetNode;
    }

    private function procesarNodoSiguiente(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion, array $targetNode, string $targetNodeId, array $primeraConexion, int $messageId): void
    {
        $tipoNodoSiguiente = $targetNode['type'] ?? 'stage';

        match ($tipoNodoSiguiente) {
            'condition' => $this->programarVerificacionCondicion($ejecucion, $etapaEjecucion, $targetNodeId, $primeraConexion, $messageId),
            'stage' => $this->programarSiguienteEtapa($ejecucion, $targetNode, $targetNodeId),
            'end' => $this->finalizarFlujo($ejecucion),
            default => Log::warning('EnviarEtapaJob: Tipo de nodo desconocido', ['tipo' => $tipoNodoSiguiente]),
        };
    }

    private function programarVerificacionCondicion(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion, string $targetNodeId, array $conexion, int $messageId): void
    {
        $tiempoVerificacion = $this->stage['tiempo_verificacion_condicion'] ?? 24;
        $fechaVerificacion = now()->addHours($tiempoVerificacion);

        $condicionEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->flujoEjecucionId)
            ->where('node_id', $targetNodeId)
            ->first();

        if (!$condicionEtapa) {
            FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'etapa_id' => null,
                'node_id' => $targetNodeId,
                'prospectos_ids' => $this->prospectoIds,
                'fecha_programada' => $fechaVerificacion,
                'estado' => 'pending',
                'response_athenacampaign' => [
                    'pending_condition' => true,
                    'source_message_id' => $messageId,
                    'conexion' => $conexion,
                ],
            ]);
        } else {
            $condicionEtapa->update([
                'prospectos_ids' => $this->prospectoIds,
                'fecha_programada' => $fechaVerificacion,
                'response_athenacampaign' => [
                    'pending_condition' => true,
                    'source_message_id' => $messageId,
                    'conexion' => $conexion,
                ],
            ]);
        }

        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaVerificacion,
        ]);
    }

    private function programarSiguienteEtapa(FlujoEjecucion $ejecucion, array $targetNode, string $targetNodeId): void
    {
        $tiempoEspera = $targetNode['tiempo_espera'] ?? 0;
        $fechaProgramada = now()->addDays($tiempoEspera);

        $siguienteEtapaEjecucion = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->flujoEjecucionId)
            ->where('node_id', $targetNodeId)
            ->first();

        if (!$siguienteEtapaEjecucion) {
            FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'etapa_id' => null,
                'node_id' => $targetNodeId,
                'prospectos_ids' => $this->prospectoIds,
                'fecha_programada' => $fechaProgramada,
                'estado' => 'pending',
            ]);
        } else {
            $siguienteEtapaEjecucion->update([
                'prospectos_ids' => $this->prospectoIds,
                'fecha_programada' => $fechaProgramada,
            ]);
        }

        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaProgramada,
        ]);
    }

    public function failed(\Throwable $exception): void
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
                'error_mensaje' => "Job falló: {$exception->getMessage()}",
            ]);
        }
    }
}
