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
 * Este job despacha jobs individuales en batch para manejar
 * grandes volúmenes de prospectos (20k-350k+) sin timeout.
 *
 * ARQUITECTURA:
 * 1. Este job prepara los datos y despacha un batch de EnviarEmailEtapaProspectoJob/EnviarSmsEtapaProspectoJob
 * 2. Cada job individual procesa UN prospecto
 * 3. El callback then() del batch ejecuta procesarSiguientePaso()
 * 4. El callback catch() maneja errores del batch
 */
class EnviarEtapaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout corto porque este job solo prepara y despacha el batch.
     * El trabajo pesado lo hacen los jobs individuales.
     */
    public $timeout = 120;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    /**
     * Number of prospects per chunk when creating jobs
     */
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
        
        // Para volúmenes grandes (>5000), usar estrategia de chunks con batches múltiples
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
     * Maneja volúmenes grandes (>5000 prospectos) usando batches múltiples.
     * 
     * En lugar de crear 350k jobs en memoria, crea múltiples batches de 2000 jobs cada uno.
     * Cada batch se procesa independientemente y el progreso se trackea en la etapa.
     */
    private function handleLargeVolume(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion): void
    {
        $totalProspectos = count($this->prospectoIds);
        $chunkSize = 2000; // Jobs por batch
        $totalChunks = ceil($totalProspectos / $chunkSize);
        
        Log::info('EnviarEtapaJob: Modo volumen grande activado', [
            'total_prospectos' => $totalProspectos,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
        ]);

        // Asegurar que los prospectos existan en ProspectoEnFlujo (bulk insert)
        $this->ensureProspectosEnFlujoExist($ejecucion);
        
        $contenidoData = $this->obtenerContenidoMensaje();
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $flujoId = $ejecucion->flujo_id;
        
        // Guardar metadata del procesamiento
        $etapaEjecucion->update([
            'response_athenacampaign' => [
                'modo' => 'large_volume',
                'total_prospectos' => $totalProspectos,
                'total_chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
                'batches_created' => 0,
                'started_at' => now()->toIso8601String(),
            ],
        ]);

        $batchIds = [];
        $batchesCreated = 0;
        
        // Procesar en chunks usando cursor para no cargar todo en memoria
        $prospectoIdsChunks = array_chunk($this->prospectoIds, $chunkSize);
        
        foreach ($prospectoIdsChunks as $chunkIndex => $chunkIds) {
            // Obtener ProspectoEnFlujo para este chunk
            $prospectosEnFlujoChunk = ProspectoEnFlujo::where('flujo_id', $flujoId)
                ->whereIn('prospecto_id', $chunkIds)
                ->get();
            
            if ($prospectosEnFlujoChunk->isEmpty()) {
                Log::warning('EnviarEtapaJob: Chunk vacío', ['chunk_index' => $chunkIndex]);
                continue;
            }
            
            // Crear jobs para este chunk
            $jobs = [];
            foreach ($prospectosEnFlujoChunk as $prospectoEnFlujo) {
                $job = $this->createJobForProspecto(
                    prospectoEnFlujo: $prospectoEnFlujo,
                    contenidoData: $contenidoData,
                    tipoMensaje: $tipoMensaje,
                    flujoId: $flujoId
                );
                if ($job) {
                    $jobs[] = $job;
                }
            }
            
            if (empty($jobs)) {
                continue;
            }
            
            // Crear batch para este chunk (sin callbacks complejos para evitar memory issues)
            $batchName = sprintf(
                'Chunk %d/%d - Etapa %s - Flujo %d',
                $chunkIndex + 1,
                $totalChunks,
                $this->stage['label'] ?? 'Sin nombre',
                $flujoId
            );
            
            $batch = Bus::batch($jobs)
                ->name($batchName)
                ->onQueue('envios')
                ->allowFailures()
                ->dispatch();
            
            $batchIds[] = $batch->id;
            $batchesCreated++;
            
            Log::info('EnviarEtapaJob: Batch creado', [
                'chunk_index' => $chunkIndex,
                'batch_id' => $batch->id,
                'jobs_in_batch' => count($jobs),
            ]);
            
            // Liberar memoria
            unset($jobs, $prospectosEnFlujoChunk);
        }
        
        // Actualizar etapa con info de todos los batches
        $etapaEjecucion->update([
            'response_athenacampaign' => [
                'modo' => 'large_volume',
                'total_prospectos' => $totalProspectos,
                'total_chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
                'batches_created' => $batchesCreated,
                'batch_ids' => $batchIds,
                'started_at' => now()->toIso8601String(),
            ],
        ]);
        
        // Registrar job principal
        FlujoJob::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'job_type' => 'enviar_etapa_large_volume',
            'job_id' => implode(',', $batchIds),
            'job_data' => [
                'etapa_id' => $this->etapaEjecucionId,
                'total_prospectos' => $totalProspectos,
                'batches_created' => $batchesCreated,
                'batch_ids' => $batchIds,
            ],
            'estado' => 'processing',
            'fecha_queued' => now(),
        ]);
        
        Log::info('EnviarEtapaJob: Volumen grande procesado', [
            'total_batches' => $batchesCreated,
            'batch_ids' => $batchIds,
        ]);
        
        // NOTA: Para large volume, el siguiente paso se procesa cuando 
        // el cron detecta que todos los batches terminaron (implementar en EjecutarNodosProgramados)
    }
    
    /**
     * Asegura que todos los prospectos existan en ProspectoEnFlujo usando bulk insert
     */
    private function ensureProspectosEnFlujoExist(FlujoEjecucion $ejecucion): void
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $flujoId = $ejecucion->flujo_id;
        
        // Obtener IDs que ya existen
        $existingIds = ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereIn('prospecto_id', $this->prospectoIds)
            ->pluck('prospecto_id')
            ->toArray();
        
        $idsToCreate = array_diff($this->prospectoIds, $existingIds);
        
        if (empty($idsToCreate)) {
            return;
        }
        
        Log::info('EnviarEtapaJob: Creando prospectos en flujo', [
            'a_crear' => count($idsToCreate),
        ]);
        
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

    /**
     * Load FlujoEjecucion model
     */
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

    /**
     * Load FlujoEjecucionEtapa model
     */
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

    /**
     * Check if stage is already completed
     */
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

    /**
     * Update initial states for execution tracking
     */
    private function updateInitialStates(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion
    ): void {
        $etapaEjecucion->update(['estado' => 'executing']);
        $ejecucion->update(['estado' => 'in_progress']);
    }

    /**
     * Handle case when no prospects are available
     */
    private function handleNoProspectos(
        FlujoEjecucionEtapa $etapaEjecucion,
        FlujoEjecucion $ejecucion
    ): void {
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

    /**
     * Create batch of individual jobs for each prospecto
     *
     * @return array<ShouldQueue>
     */
    private function createBatchJobs(
        \Illuminate\Support\Collection $prospectosEnFlujo,
        array $contenidoData,
        FlujoEjecucion $ejecucion
    ): array {
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

    /**
     * Create appropriate job based on message type
     */
    private function createJobForProspecto(
        ProspectoEnFlujo $prospectoEnFlujo,
        array $contenidoData,
        string $tipoMensaje,
        int $flujoId
    ): ?ShouldQueue {
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

    /**
     * Dispatch the batch with callbacks for completion and errors.
     *
     * Rate limiting is handled at the job level via RateLimitedMiddleware
     * in EnviarEmailEtapaProspectoJob and EnviarSmsEtapaProspectoJob.
     */
    private function dispatchBatch(
        array $jobs,
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        array $contenidoData
    ): void {
        $batchName = sprintf(
            'Etapa %s - Flujo %d (%d prospectos)',
            $this->stage['label'] ?? 'Sin nombre',
            $ejecucion->flujo_id,
            count($jobs)
        );

        // Store data needed for callbacks
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
            'rate_limiting' => 'Handled by RateLimitedMiddleware in individual jobs',
        ]);

        // Record job dispatch
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

        // Update etapa with batch info
        $etapaEjecucion->update([
            'response_athenacampaign' => [
                'batch_id' => $batch->id,
                'total_jobs' => count($jobs),
                'estado' => 'batch_processing',
            ],
        ]);
    }

    /**
     * Callback when batch completes successfully
     */
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

        // Generate simulated messageId for compatibility
        $messageId = rand(10000, 99999);

        $etapaEjecucion->update([
            'message_id' => $messageId,
            'estado' => 'completed',
            'fecha_ejecucion' => now(),
            'response_athenacampaign' => [
                'batch_id' => $batch->id,
                'messageID' => $messageId,
                'Recipients' => $batch->processedJobs() - $batch->failedJobs,
                'Errores' => $batch->failedJobs,
                'total_jobs' => $data['total_jobs'],
            ],
        ]);

        // Update FlujoJob record
        FlujoJob::where('job_id', $batch->id)
            ->where('job_type', 'enviar_etapa_batch')
            ->update([
                'estado' => 'completed',
                'fecha_procesado' => now(),
            ]);

        // Restore context for procesarSiguientePaso
        $this->stage = $data['stage'];
        $this->branches = $data['branches'];
        $this->prospectoIds = $data['prospecto_ids'];
        $this->flujoEjecucionId = $data['flujo_ejecucion_id'];
        $this->etapaEjecucionId = $data['etapa_ejecucion_id'];

        $this->procesarSiguientePaso($ejecucion, $etapaEjecucion, $messageId);
    }

    /**
     * Callback when batch has failures
     */
    private function onBatchFailed(Batch $batch, Throwable $e, array $data): void
    {
        Log::error('EnviarEtapaJob: Batch con errores', [
            'batch_id' => $batch->id,
            'error' => $e->getMessage(),
            'failed_jobs' => $batch->failedJobs,
            'etapa_ejecucion_id' => $data['etapa_ejecucion_id'],
        ]);
    }

    /**
     * Callback when batch finishes (success or failure)
     */
    private function onBatchFinished(Batch $batch, array $data): void
    {
        Log::info('EnviarEtapaJob: Batch finalizado', [
            'batch_id' => $batch->id,
            'total_processed' => $batch->processedJobs(),
            'total_failed' => $batch->failedJobs,
            'pending' => $batch->pendingJobs,
        ]);
    }

    /**
     * Obtiene el contenido del mensaje a enviar.
     *
     * @return array{contenido: string, asunto: string|null, es_html: bool}
     */
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
                    'es_html' => true,
                ]);

                return $flujoEtapa->obtenerContenidoParaEnvio($tipoMensaje);
            }

            // Solo loguear warning si no encontró la etapa (para diagnóstico)
            if (!$flujoEtapa) {
                Log::warning('EnviarEtapaJob: FlujoEtapa no encontrada, usando fallback', [
                    'stage_id' => $stageId,
                ]);
            }
        }

        // Fallback: contenido inline
        $contenido = $this->stage['plantilla_mensaje'] ?? $this->stage['data']['contenido'] ?? '';
        $esHtml = $this->detectarSiEsHtml($contenido);

        Log::info('EnviarEtapaJob: Usando contenido inline', [
            'stage_id' => $stageId,
            'es_html' => $esHtml,
            'contenido_length' => strlen($contenido),
        ]);

        return [
            'contenido' => $contenido,
            'asunto' => $this->stage['template']['asunto'] ?? $this->stage['data']['template']['asunto'] ?? null,
            'es_html' => $esHtml,
        ];
    }

    /**
     * Detecta si un contenido es HTML.
     * 
     * @param string $contenido
     * @return bool
     */
    private function detectarSiEsHtml(string $contenido): bool
    {
        // Si contiene tags HTML comunes, es HTML
        $htmlPatterns = [
            '/<html/i',
            '/<body/i',
            '/<div/i',
            '/<p>/i',
            '/<br/i',
            '/<table/i',
            '/<a\s+href/i',
            '/<img/i',
            '/<h[1-6]/i',
            '/<span/i',
            '/<style/i',
        ];

        foreach ($htmlPatterns as $pattern) {
            if (preg_match($pattern, $contenido)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtiene o crea registros de ProspectoEnFlujo
     * 
     * OPTIMIZADO: Usa consultas en batch en lugar de foreach individual
     * para manejar 350k+ prospectos sin timeout
     */
    private function obtenerProspectosEnFlujo(FlujoEjecucion $ejecucion): \Illuminate\Support\Collection
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $flujoId = $ejecucion->flujo_id;
        
        Log::info('EnviarEtapaJob: Obteniendo prospectos en flujo', [
            'total_prospectos' => count($this->prospectoIds),
            'flujo_id' => $flujoId,
        ]);

        // 1. Obtener IDs de prospectos que YA están en el flujo (una sola query)
        $existingIds = ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereIn('prospecto_id', $this->prospectoIds)
            ->pluck('prospecto_id')
            ->toArray();

        // 2. Identificar IDs que necesitan ser creados
        $idsToCreate = array_diff($this->prospectoIds, $existingIds);

        Log::info('EnviarEtapaJob: Prospectos existentes vs nuevos', [
            'existentes' => count($existingIds),
            'a_crear' => count($idsToCreate),
        ]);

        // 3. Crear los nuevos en batch usando insert (mucho más rápido que firstOrCreate individual)
        if (!empty($idsToCreate)) {
            $now = now();
            $chunks = array_chunk($idsToCreate, 1000); // Insertar de a 1000
            
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
            
            Log::info('EnviarEtapaJob: Prospectos creados en batch', [
                'creados' => count($idsToCreate),
            ]);
        }

        // 4. Obtener todos los ProspectoEnFlujo en una sola query
        // Usamos cursor() para no cargar todo en memoria
        return ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereIn('prospecto_id', $this->prospectoIds)
            ->cursor()
            ->collect();
    }

    /**
     * Determina el siguiente paso después de enviar la etapa
     */
    private function procesarSiguientePaso(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        int $messageId
    ): void {
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

        $this->procesarNodoSiguiente(
            ejecucion: $ejecucion,
            etapaEjecucion: $etapaEjecucion,
            targetNode: $targetNode,
            targetNodeId: $targetNodeId,
            primeraConexion: $primeraConexion,
            messageId: $messageId
        );
    }

    /**
     * Check if node is an end node
     */
    private function isEndNode(string $nodeId): bool
    {
        return str_starts_with($nodeId, 'end-');
    }

    /**
     * Finalize the flow execution
     */
    private function finalizarFlujo(FlujoEjecucion $ejecucion): void
    {
        Log::info('EnviarEtapaJob: Finalizando flujo');

        $ejecucion->update([
            'estado' => 'completed',
            'fecha_fin' => now(),
        ]);
    }

    /**
     * Find target node in flow data (stages OR conditions)
     */
    private function findTargetNode(FlujoEjecucion $ejecucion, string $targetNodeId): ?array
    {
        $flujoData = $ejecucion->flujo->flujo_data;
        $stages = $flujoData['stages'] ?? [];
        $conditions = $flujoData['conditions'] ?? [];
        
        // Buscar primero en stages
        $targetNode = collect($stages)->firstWhere('id', $targetNodeId);
        
        // Si no está en stages, buscar en conditions
        if (!$targetNode) {
            $targetNode = collect($conditions)->firstWhere('id', $targetNodeId);
        }

        if (!$targetNode) {
            Log::warning('EnviarEtapaJob: Nodo destino no encontrado en stages ni conditions', [
                'target_node_id' => $targetNodeId,
                'stages_count' => count($stages),
                'conditions_count' => count($conditions),
            ]);
        } else {
            Log::info('EnviarEtapaJob: Nodo destino encontrado', [
                'target_node_id' => $targetNodeId,
                'node_type' => $targetNode['type'] ?? 'unknown',
            ]);
        }

        return $targetNode;
    }

    /**
     * Process next node based on its type
     */
    private function procesarNodoSiguiente(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        array $targetNode,
        string $targetNodeId,
        array $primeraConexion,
        int $messageId
    ): void {
        $tipoNodoSiguiente = $targetNode['type'] ?? 'stage';

        match ($tipoNodoSiguiente) {
            'condition' => $this->programarVerificacionCondicion(
                $ejecucion, $etapaEjecucion, $targetNodeId, $primeraConexion, $messageId
            ),
            'stage' => $this->programarSiguienteEtapa(
                $ejecucion, $targetNode, $targetNodeId
            ),
            'end' => $this->finalizarFlujo($ejecucion),
            default => Log::warning('EnviarEtapaJob: Tipo de nodo desconocido', [
                'tipo' => $tipoNodoSiguiente,
                'node_id' => $targetNodeId,
            ]),
        };
    }

    /**
     * Schedule condition verification - NO job dispatch, only DB update
     * 
     * ✅ ARQUITECTURA SIMPLIFICADA: El cron EjecutarNodosProgramados
     * ejecutará la condición cuando fecha_proximo_nodo <= now()
     */
    private function programarVerificacionCondicion(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionEtapa $etapaEjecucion,
        string $targetNodeId,
        array $conexion,
        int $messageId
    ): void {
        $tiempoVerificacion = $this->stage['tiempo_verificacion_condicion'] ?? 24;
        $fechaVerificacion = now()->addHours($tiempoVerificacion);

        Log::info('EnviarEtapaJob: Programando verificación de condición (sin job dispatch)', [
            'en_horas' => $tiempoVerificacion,
            'fecha_verificacion' => $fechaVerificacion,
            'condition_node_id' => $targetNodeId,
            'prospectos_count' => count($this->prospectoIds),
            'message_id' => $messageId,
        ]);

        // Crear o actualizar etapa para la condición
        $condicionEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->flujoEjecucionId)
            ->where('node_id', $targetNodeId)
            ->first();

        if (!$condicionEtapa) {
            $condicionEtapa = FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'etapa_id' => null,
                'node_id' => $targetNodeId,
                'prospectos_ids' => $this->prospectoIds,
                'fecha_programada' => $fechaVerificacion,
                'estado' => 'pending',
                // Guardar message_id del email anterior para que el cron pueda consultar estadísticas
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

        // ✅ NO despachamos job - el cron lo ejecutará cuando llegue la fecha

        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaVerificacion,
        ]);
    }

    /**
     * Schedule next stage - NO job dispatch, only DB update
     * 
     * ✅ ARQUITECTURA SIMPLIFICADA: El cron EjecutarNodosProgramados
     * es el ÚNICO que ejecuta nodos cuando fecha_proximo_nodo <= now()
     */
    private function programarSiguienteEtapa(
        FlujoEjecucion $ejecucion,
        array $targetNode,
        string $targetNodeId
    ): void {
        $tiempoEspera = $targetNode['tiempo_espera'] ?? 0;
        $fechaProgramada = now()->addDays($tiempoEspera);

        Log::info('EnviarEtapaJob: Programando siguiente etapa (sin job dispatch)', [
            'siguiente_node_id' => $targetNodeId,
            'tiempo_espera_dias' => $tiempoEspera,
            'fecha_programada' => $fechaProgramada,
            'prospectos_count' => count($this->prospectoIds),
            'sera_ejecutado_inmediatamente' => $tiempoEspera === 0,
        ]);

        // Verificar si ya existe la etapa (puede haber sido creada previamente)
        $siguienteEtapaEjecucion = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->flujoEjecucionId)
            ->where('node_id', $targetNodeId)
            ->first();

        if (!$siguienteEtapaEjecucion) {
            // ✅ Crear etapa con prospectos_ids para propagación del filtrado
            $siguienteEtapaEjecucion = FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'etapa_id' => null,
                'node_id' => $targetNodeId,
                'prospectos_ids' => $this->prospectoIds,
                'fecha_programada' => $fechaProgramada,
                'estado' => 'pending',
            ]);
        } else {
            // Actualizar prospectos si la etapa ya existe
            $siguienteEtapaEjecucion->update([
                'prospectos_ids' => $this->prospectoIds,
                'fecha_programada' => $fechaProgramada,
            ]);
        }

        // ✅ NO despachamos job - el cron lo ejecutará cuando llegue la fecha
        // Esto evita jobs stuck con available_at en el futuro

        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaProgramada,
        ]);
    }

    /**
     * Handle a job failure.
     */
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
                'error_mensaje' => "Job falló después de {$this->tries} intentos: {$exception->getMessage()}",
            ]);
        }
    }
}
