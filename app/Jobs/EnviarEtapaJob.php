<?php

namespace App\Jobs;

use App\Jobs\Callbacks\BatchCompletedCallback;
use App\Jobs\Callbacks\BatchFailedCallback;
use App\Jobs\Callbacks\BatchFinishedCallback;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoJob;
use App\Models\ProspectoEnFlujo;
use App\Services\StageOrderResolver;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

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

        Log::debug('EnviarEtapaJob: Iniciando', [
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

        if ($this->isAlreadyCompleted($etapaEjecucion, $ejecucion)) {
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
        // Chunk de 200: cada orchestrator tarda ~3-5s en lugar de 20s+,
        // permitiendo que los workers procesen otros jobs (hijos) entre orchestrators.
        $chunkSize = 200;
        $totalChunks = (int) ceil($totalProspectos / $chunkSize);

        Log::debug('EnviarEtapaJob: Modo volumen grande', [
            'total_prospectos' => $totalProspectos,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
        ]);

        // Marcar primer_envio_at si es la primera vez que esta etapa procesa envíos
        // Esto permite contar "etapas que han trabajado" para gerencia, independiente del estado
        if ($etapaEjecucion->primer_envio_at === null) {
            $etapaEjecucion->update(['primer_envio_at' => now()]);
        }

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
            )->onConnection('database')->onQueue('envios');
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

        Log::debug('EnviarEtapaJob: Sub-jobs despachados', [
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

    private function isAlreadyCompleted(FlujoEjecucionEtapa $etapaEjecucion, FlujoEjecucion $ejecucion): bool
    {
        if ($etapaEjecucion->estado === 'completed') {
            // For perpetual executions with specific prospectoIds, allow processing
            // This handles catch-up scenarios where new prospects need this stage
            if ($ejecucion->es_perpetuo && ! empty($this->prospectoIds)) {
                Log::debug('EnviarEtapaJob: Perpetua con prospectos específicos', [
                    'etapa_id' => $this->etapaEjecucionId,
                    'prospectos_count' => count($this->prospectoIds),
                ]);

                return false;
            }

            Log::warning('EnviarEtapaJob: Etapa ya completada - saltando', [
                'etapa_id' => $this->etapaEjecucionId,
                'es_perpetuo' => $ejecucion->es_perpetuo,
                'tiene_prospectos' => ! empty($this->prospectoIds),
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

        if (! $conexion) {
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

        // Para tipo "ambos", obtener contenido de SMS también
        $contenidoSms = null;
        if ($tipoMensaje === 'ambos') {
            $contenidoSms = $this->obtenerContenidoSms();
        }

        // Pre-resolve email provider at batch level to avoid N+1 queries
        // All prospectos in same batch typically use same provider (same lote)
        $batchProviderName = $this->preResolveBatchProvider($prospectosEnFlujo);

        foreach ($prospectosEnFlujo as $prospectoEnFlujo) {
            $createdJobs = $this->createJobsForProspecto(
                prospectoEnFlujo: $prospectoEnFlujo,
                contenidoEmail: $contenidoData,
                contenidoSms: $contenidoSms,
                tipoMensaje: $tipoMensaje,
                flujoId: $ejecucion->flujo_id,
                providerName: $batchProviderName
            );

            foreach ($createdJobs as $job) {
                $jobs[] = $job;
            }
        }

        Log::debug('EnviarEtapaJob: Jobs creados', [
            'total_jobs' => count($jobs),
            'tipo_mensaje' => $tipoMensaje,
            'batch_provider' => $batchProviderName,
        ]);

        return $jobs;
    }

    /**
     * Crea los jobs necesarios para un prospecto según el tipo de mensaje.
     *
     * @param  string|null  $providerName  Pre-resolved provider name for batch optimization
     * @return array<ShouldQueue>
     */
    private function createJobsForProspecto(
        ProspectoEnFlujo $prospectoEnFlujo,
        array $contenidoEmail,
        ?array $contenidoSms,
        string $tipoMensaje,
        int $flujoId,
        ?string $providerName = null
    ): array {
        $jobs = [];

        // SMS only
        if ($tipoMensaje === 'sms') {
            $jobs[] = new EnviarSmsEtapaProspectoJob(
                prospectoEnFlujoId: $prospectoEnFlujo->id,
                contenido: $contenidoEmail['contenido'], // En este caso contenidoEmail tiene el SMS
                flujoId: $flujoId,
                etapaEjecucionId: $this->etapaEjecucionId
            );

            return $jobs;
        }

        // Para 'ambos', un prospecto puede tener solo uno de los dos canales.
        // Despachamos el job de email solo si tiene email válido y el de SMS solo
        // si tiene teléfono, para no generar failed jobs de canales imposibles.
        $prospecto = $prospectoEnFlujo->prospecto;
        $tieneEmail = ! empty($prospecto?->email) && ! ($prospecto->email_invalido ?? false);
        $tieneTelefono = ! empty($prospecto?->telefono);

        // Email (para 'email' o 'ambos', solo si tiene email válido)
        if ($tieneEmail) {
            $jobs[] = new EnviarEmailEtapaProspectoJob(
                prospectoEnFlujoId: $prospectoEnFlujo->id,
                contenido: $contenidoEmail['contenido'],
                asunto: $contenidoEmail['asunto'] ?? $this->stage['template']['asunto'] ?? 'Mensaje',
                flujoId: $flujoId,
                etapaEjecucionId: $this->etapaEjecucionId,
                esHtml: $contenidoEmail['es_html'],
                providerName: $providerName
            );
        }

        // SMS adicional para tipo 'ambos' (solo si tiene teléfono)
        if ($tipoMensaje === 'ambos' && $contenidoSms !== null && $tieneTelefono) {
            $jobs[] = new EnviarSmsEtapaProspectoJob(
                prospectoEnFlujoId: $prospectoEnFlujo->id,
                contenido: $contenidoSms['contenido'],
                flujoId: $flujoId,
                etapaEjecucionId: $this->etapaEjecucionId
            );
        }

        return $jobs;
    }

    /**
     * Pre-resolve email provider at batch level to avoid N+1 queries.
     *
     * For batches where all prospectos belong to the same lote (common case),
     * we can determine the provider once and pass it to all child jobs.
     * This eliminates per-prospecto importacion.lote lookups.
     *
     * @param  \Illuminate\Support\Collection  $prospectosEnFlujo  Collection with prospecto.importacion.lote eager loaded
     * @return string|null Provider name ('athena'|'certificada') or null if mixed batch
     */
    private function preResolveBatchProvider(\Illuminate\Support\Collection $prospectosEnFlujo): ?string
    {
        if ($prospectosEnFlujo->isEmpty()) {
            return null;
        }

        $resolver = app(\App\Services\Email\EmailProviderResolver::class);
        $providerName = null;
        $isHomogeneous = true;

        foreach ($prospectosEnFlujo as $prospectoEnFlujo) {
            $prospecto = $prospectoEnFlujo->prospecto;
            if (! $prospecto) {
                continue;
            }

            $currentProvider = $resolver->getProviderName($prospecto);

            if ($providerName === null) {
                $providerName = $currentProvider;
            } elseif ($providerName !== $currentProvider) {
                // Mixed batch - some IC, some not - cannot pre-resolve
                $isHomogeneous = false;
                Log::debug('EnviarEtapaJob: Mixed provider batch', [
                    'batch_size' => $prospectosEnFlujo->count(),
                ]);
                break;
            }
        }

        return $isHomogeneous ? $providerName : null;
    }

    /**
     * Obtiene el contenido de la plantilla SMS para tipo 'ambos'.
     */
    private function obtenerContenidoSms(): ?array
    {
        $plantillaType = $this->stage['plantilla_type'] ?? 'inline';

        if ($plantillaType === 'reference') {
            $plantillaId = $this->stage['plantilla_id'] ?? null;

            if ($plantillaId) {
                $plantilla = \App\Models\Plantilla::find($plantillaId);

                if ($plantilla && $plantilla->esSMS()) {
                    Log::debug('EnviarEtapaJob: Usando plantilla SMS de referencia', [
                        'stage_id' => $this->stage['id'] ?? null,
                        'plantilla_id' => $plantillaId,
                        'plantilla_nombre' => $plantilla->nombre,
                    ]);

                    return [
                        'contenido' => $plantilla->contenido ?? '',
                        'asunto' => null,
                        'es_html' => false,
                    ];
                }
            }
        }

        // Fallback: contenido inline para SMS (si existe)
        $contenido = $this->stage['plantilla_mensaje_sms'] ?? $this->stage['data']['contenido_sms'] ?? null;

        if ($contenido) {
            return [
                'contenido' => $contenido,
                'asunto' => null,
                'es_html' => false,
            ];
        }

        Log::warning('EnviarEtapaJob: No se encontró plantilla SMS para tipo ambos', [
            'stage_id' => $this->stage['id'] ?? null,
        ]);

        return null;
    }

    private function dispatchBatch(array $jobs, FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion, array $contenidoData): void
    {
        // Marcar primer_envio_at si es la primera vez que esta etapa procesa envíos
        // Esto permite contar "etapas que han trabajado" para gerencia, independiente del estado
        if ($etapaEjecucion->primer_envio_at === null) {
            $etapaEjecucion->update(['primer_envio_at' => now()]);
        }

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

        // Route email-only batches to a dedicated 'emails' queue so they
        // don't get blocked behind massive SMS queues (FIFO). SMS-only and
        // mixed batches stay in 'envios' for backward compatibility.
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $queueName = $tipoMensaje === 'email' ? 'emails' : 'envios';

        // Usar clases invocables en lugar de closures para evitar
        // problemas de serialización con Laravel 12 + SerializableClosure
        $batch = Bus::batch($jobs)
            ->name($batchName)
            ->onConnection('database')
            ->onQueue($queueName)
            ->allowFailures()
            ->then(new BatchCompletedCallback($callbackData))
            ->catch(new BatchFailedCallback($callbackData))
            ->finally(new BatchFinishedCallback($callbackData))
            ->dispatch();

        Log::debug('EnviarEtapaJob: Batch despachado', [
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
        $plantillaType = $this->stage['plantilla_type'] ?? 'inline';

        // Si usa plantilla de referencia, obtener de la base de datos
        if ($plantillaType === 'reference') {
            // Para tipo 'ambos' o 'email', usar plantilla_id_email si existe
            $plantillaId = ($tipoMensaje === 'email' || $tipoMensaje === 'ambos')
                ? ($this->stage['plantilla_id_email'] ?? $this->stage['plantilla_id'] ?? null)
                : ($this->stage['plantilla_id'] ?? null);

            if ($plantillaId) {
                $plantilla = \App\Models\Plantilla::find($plantillaId);

                if ($plantilla) {
                    Log::debug('EnviarEtapaJob: Usando plantilla de referencia', [
                        'stage_id' => $this->stage['id'] ?? null,
                        'plantilla_id' => $plantillaId,
                        'plantilla_nombre' => $plantilla->nombre,
                        'tipo_mensaje' => $tipoMensaje,
                    ]);

                    if ($plantilla->esEmail()) {
                        return [
                            'contenido' => $plantilla->generarPreview() ?? '',
                            'asunto' => $plantilla->asunto,
                            'es_html' => true,
                        ];
                    } else {
                        return [
                            'contenido' => $plantilla->contenido ?? '',
                            'asunto' => null,
                            'es_html' => false,
                        ];
                    }
                }

                Log::warning('EnviarEtapaJob: Plantilla no encontrada', [
                    'plantilla_id' => $plantillaId,
                    'stage_id' => $this->stage['id'] ?? null,
                ]);
            }
        }

        // Fallback: contenido inline
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
        $currentNodeId = $this->stage['id'] ?? null;

        // Obtener IDs existentes
        $existingIds = ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereIn('prospecto_id', $this->prospectoIds)
            ->pluck('prospecto_id')
            ->toArray();

        $idsToCreate = array_diff($this->prospectoIds, $existingIds);

        // Crear los nuevos en batch
        if (! empty($idsToCreate)) {
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

        // Build base query for prospects in this flow
        // Eager load prospecto.importacion.lote for batch provider pre-resolution
        // This eliminates N+1 queries when determining email provider (IC vs non-IC)
        $query = ProspectoEnFlujo::with(['prospecto.importacion.lote'])
            ->where('flujo_id', $flujoId)
            ->whereIn('prospecto_id', $this->prospectoIds)
            ->where('cancelado', false)
            ->where('completado', false);

        // Filtro de elegibilidad por canal:
        // - 'email': requiere email válido
        // - 'sms': requiere teléfono válido
        // - 'ambos': elegible si es alcanzable por AL MENOS un canal (email O teléfono).
        //   Antes 'ambos' exigía email, dejando sin NADA a los contratos sin email
        //   aunque tuvieran teléfono. Cada job por canal se saltea solo si le falta su dato.
        $emailValido = function ($q) {
            $q->whereNotNull('email')
                ->where('email', '!=', '')
                ->where(function ($q2) {
                    $q2->where('email_invalido', false)->orWhereNull('email_invalido');
                });
        };

        $telefonoValido = function ($q) {
            $q->whereNotNull('telefono')->where('telefono', '!=', '');
        };

        if ($tipoMensaje === 'email') {
            $query->whereHas('prospecto', $emailValido);
        } elseif ($tipoMensaje === 'sms') {
            $query->whereHas('prospecto', $telefonoValido);
        } elseif ($tipoMensaje === 'ambos') {
            $query->where(function ($q) use ($emailValido, $telefonoValido) {
                $q->whereHas('prospecto', $emailValido)
                    ->orWhereHas('prospecto', $telefonoValido);
            });
        }

        // Apply stage-based filtering for perpetual executions
        if ($currentNodeId && $ejecucion->es_perpetuo) {
            $resolver = app(StageOrderResolver::class);
            $previousStageNodeId = $resolver->getPreviousStage($ejecucion->flujo, $currentNodeId);

            if ($previousStageNodeId === null) {
                // First stage: only prospects with NULL ultima_etapa_node_id (new prospects)
                $query->whereNull('ultima_etapa_node_id');
            } else {
                // Subsequent stages: only prospects who completed the previous stage
                $query->where('ultima_etapa_node_id', $previousStageNodeId);
            }

            $filteredCount = $query->count();
            $totalCount = count($this->prospectoIds);

            Log::debug('EnviarEtapaJob: Stage filtering applied', [
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'current_node_id' => $currentNodeId,
                'previous_node_id' => $previousStageNodeId,
                'is_first_stage' => $previousStageNodeId === null,
                'total_prospectos' => $totalCount,
                'eligible_prospectos' => $filteredCount,
                'filtered_out' => $totalCount - $filteredCount,
            ]);
        }

        return $query->cursor()->collect();
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
