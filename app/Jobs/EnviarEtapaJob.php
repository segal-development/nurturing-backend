<?php

namespace App\Jobs;

use App\Jobs\Callbacks\BatchCompletedCallback;
use App\Jobs\Callbacks\BatchFailedCallback;
use App\Jobs\Callbacks\BatchFinishedCallback;
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

        // Usar el callback para procesar siguiente paso
        $callbackData = [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'stage' => $this->stage,
            'prospecto_ids' => $this->prospectoIds,
            'branches' => $this->branches,
            'total_jobs' => 0,
        ];
        
        // Crear un batch vacío mock para el callback
        $callback = new BatchCompletedCallback($callbackData);
        // Llamar directamente a procesarSiguientePaso via reflection o simplificar
        // Por ahora, actualizar la ejecución para que el cron maneje el siguiente paso
        $this->programarSiguientePasoSimple($ejecucion);
    }
    
    /**
     * Versión simplificada para cuando no hay prospectos.
     * El cron se encargará de ejecutar el siguiente nodo.
     */
    private function programarSiguientePasoSimple(FlujoEjecucion $ejecucion): void
    {
        $branches = $this->branches;
        $stageId = $this->stage['id'];
        
        $conexion = collect($branches)->firstWhere('source_node_id', $stageId);
        
        if (!$conexion) {
            // No hay siguiente nodo, finalizar
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);
            return;
        }
        
        $targetNodeId = $conexion['target_node_id'];
        
        if (str_starts_with($targetNodeId, 'end-')) {
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);
            return;
        }
        
        // Programar siguiente nodo para que el cron lo ejecute
        $ejecucion->update([
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => now(),
        ]);
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

        // Usar clases invocables en lugar de closures para evitar
        // problemas de serialización con Laravel 12 + SerializableClosure
        $batch = Bus::batch($jobs)
            ->name($batchName)
            ->onQueue('envios')
            ->allowFailures()
            ->then(new BatchCompletedCallback($callbackData))
            ->catch(new BatchFailedCallback($callbackData))
            ->finally(new BatchFinishedCallback($callbackData))
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

    // NOTA: Los callbacks onBatchCompleted, onBatchFailed, onBatchFinished
    // se movieron a clases invocables en App\Jobs\Callbacks\ para evitar
    // problemas de serialización con Laravel 12 + SerializableClosure

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

    // NOTA: Los métodos procesarSiguientePaso, finalizarFlujo, findTargetNode,
    // procesarNodoSiguiente, programarVerificacionCondicion, programarSiguienteEtapa
    // se movieron a BatchCompletedCallback para evitar problemas de serialización

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
