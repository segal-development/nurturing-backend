<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoEtapa;
use App\Models\ProspectoEnFlujo;
use App\Services\EnvioService;
use App\Services\GuardedTransition;
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
 * Job que procesa un chunk de prospectos para envío de emails.
 *
 * Este job obtiene los prospectos de la BD usando offset/limit,
 * sin cargar todo en memoria. Es despachado por EnviarEtapaJob
 * para volúmenes grandes (>5000 prospectos).
 */
class EnviarEtapaChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public int $flujoEjecucionId,
        public int $etapaEjecucionId,
        public array $stage,
        public int $flujoId,
        public int $offset,
        public int $limit,
        public int $chunkIndex,
        public int $totalChunks,
        public array $branches = []
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        Log::debug('EnviarEtapaChunkJob: Procesando chunk', [
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'flujo_id' => $this->flujoId,
        ]);

        $ejecucion = FlujoEjecucion::find($this->flujoEjecucionId);
        if (! $ejecucion) {
            Log::error('EnviarEtapaChunkJob: Ejecución no encontrada');

            return;
        }

        $etapaEjecucion = FlujoEjecucionEtapa::find($this->etapaEjecucionId);
        if (! $etapaEjecucion) {
            Log::error('EnviarEtapaChunkJob: Etapa no encontrada');

            return;
        }

        // Obtener prospectos para este chunk desde la BD
        // Eager loads prospecto.importacion.lote for batch provider pre-resolution
        $prospectosEnFlujo = $this->obtenerProspectosChunk();

        if ($prospectosEnFlujo->isEmpty()) {
            Log::warning('EnviarEtapaChunkJob: Chunk vacío', [
                'chunk_index' => $this->chunkIndex,
            ]);

            return;
        }

        // Obtener contenido del mensaje
        $contenidoData = $this->obtenerContenidoMensaje();
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';

        // Pre-resolve email provider at chunk level to avoid N+1 queries
        $chunkProviderName = $this->preResolveChunkProvider($prospectosEnFlujo);

        // Load blocking (prospecto_id, canal) pairs scoped to this chunk's window.
        // Prevents dispatching leaf jobs for already-sent/pending envíos (idempotency).
        $prospectosChunkIds = $prospectosEnFlujo->pluck('prospecto_id')->toArray();
        $enviados = app(EnvioService::class)->cargarEnviosBloqueantes(
            $this->etapaEjecucionId,
            $prospectosChunkIds
        );

        // Crear jobs para este chunk
        $jobs = [];
        foreach ($prospectosEnFlujo as $prospectoEnFlujo) {
            $job = $this->createJobForProspecto($prospectoEnFlujo, $contenidoData, $tipoMensaje, $chunkProviderName, $enviados);
            if ($job) {
                $jobs[] = $job;
            }
        }

        if (empty($jobs)) {
            Log::warning('EnviarEtapaChunkJob: No se crearon jobs para el chunk');

            return;
        }

        // Despachar batch para este chunk
        $batchName = sprintf(
            'Chunk %d/%d - Etapa %s',
            $this->chunkIndex + 1,
            $this->totalChunks,
            $this->stage['label'] ?? 'Sin nombre'
        );

        // Route email-only batches to dedicated 'emails' queue. See EnviarEtapaJob.
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $queueName = $tipoMensaje === 'email' ? 'emails' : 'envios';

        $batch = Bus::batch($jobs)
            ->name($batchName)
            ->onConnection('database')
            ->onQueue($queueName)
            ->allowFailures()
            ->dispatch();

        Log::debug('EnviarEtapaChunkJob: Batch despachado', [
            'chunk_index' => $this->chunkIndex,
            'batch_id' => $batch->id,
            'jobs_count' => count($jobs),
        ]);

        // Liberar memoria
        unset($jobs, $prospectosEnFlujo);
    }

    /**
     * Obtiene los prospectos para este chunk desde la BD.
     * Usa offset/limit para no cargar todo en memoria.
     * For perpetual executions, also applies stage-based filtering.
     */
    private function obtenerProspectosChunk(): \Illuminate\Support\Collection
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $currentNodeId = $this->stage['id'] ?? null;

        // Load the execution to check if it's perpetual
        $ejecucion = FlujoEjecucion::find($this->flujoEjecucionId);

        // Build base query
        $baseQuery = ProspectoEnFlujo::where('flujo_id', $this->flujoId)
            ->where('cancelado', false)
            ->where('completado', false);

        // Exclude prospectos with invalid/missing email when sending email
        if (in_array($tipoMensaje, ['email', 'ambos'], true)) {
            $baseQuery->whereHas('prospecto', function ($q) {
                $q->whereNotNull('email')
                    ->where('email', '!=', '')
                    ->where(function ($q2) {
                        $q2->where('email_invalido', false)->orWhereNull('email_invalido');
                    });
            });
        }

        // Exclude prospectos with missing phone for SMS-only stages
        if ($tipoMensaje === 'sms') {
            $baseQuery->whereHas('prospecto', function ($q) {
                $q->whereNotNull('telefono')->where('telefono', '!=', '');
            });
        }

        // Apply stage-based filtering for perpetual executions
        if ($currentNodeId && $ejecucion && $ejecucion->es_perpetuo) {
            $resolver = app(StageOrderResolver::class);
            $previousStageNodeId = $resolver->getPreviousStage($ejecucion->flujo, $currentNodeId);

            if ($previousStageNodeId === null) {
                // First stage: only prospects with NULL ultima_etapa_node_id (new prospects)
                $baseQuery->whereNull('ultima_etapa_node_id');
            } else {
                // Subsequent stages: only prospects who completed the previous stage
                $baseQuery->where('ultima_etapa_node_id', $previousStageNodeId);
            }

            Log::debug('EnviarEtapaChunkJob: Stage filtering', [
                'chunk_index' => $this->chunkIndex,
                'current_node_id' => $currentNodeId,
                'previous_node_id' => $previousStageNodeId,
                'is_first_stage' => $previousStageNodeId === null,
            ]);
        }

        // Gate temporal — Decision 4 (design): aplicar en el WHERE ANTES de skip/take
        // para que la paginación no se desincronice entre chunks.
        // Criterio: now() >= fecha_inicio + offset_acumulado(currentStage)
        // Equivalente SQL: fecha_inicio <= now() - INTERVAL 'N days'
        // Si el flujo no se carga (null), o el offset = -1 (nodo no encontrado), se omite el gate.
        if ($currentNodeId) {
            $flujo = $ejecucion?->flujo ?? Flujo::find($this->flujoId);
            if ($flujo) {
                /** @var GuardedTransition $guard */
                $guard = app(GuardedTransition::class);
                $offsetDias = $guard->offsetAcumulado($flujo, $currentNodeId);

                if ($offsetDias >= 0) {
                    // Prospectos cuya fecha_inicio ya superó el offset acumulado de esta etapa.
                    // NULL fecha_inicio: se deja pasar (sin anchor no hay gate temporal).
                    $fechaCorte = now()->subDays($offsetDias);
                    $baseQuery->where(function ($q) use ($fechaCorte) {
                        $q->whereNull('fecha_inicio')
                            ->orWhere('fecha_inicio', '<=', $fechaCorte);
                    });

                    Log::debug('EnviarEtapaChunkJob: Gate temporal aplicado', [
                        'chunk_index' => $this->chunkIndex,
                        'current_node_id' => $currentNodeId,
                        'offset_dias' => $offsetDias,
                        'fecha_corte' => $fechaCorte->toDateTimeString(),
                    ]);
                }
            }
        }

        // Get prospect IDs for this chunk with filtering applied
        $prospectoIds = (clone $baseQuery)
            ->orderBy('id')
            ->skip($this->offset)
            ->take($this->limit)
            ->pluck('prospecto_id')
            ->toArray();

        if (empty($prospectoIds)) {
            return collect();
        }

        // Crear registros en ProspectoEnFlujo si no existen (should not happen for filtered perpetual)
        $existingIds = ProspectoEnFlujo::where('flujo_id', $this->flujoId)
            ->whereIn('prospecto_id', $prospectoIds)
            ->pluck('prospecto_id')
            ->toArray();

        $idsToCreate = array_diff($prospectoIds, $existingIds);

        if (! empty($idsToCreate)) {
            $now = now();
            // crearBatch normaliza 'ambos'→'email' internamente y calcula fecha_ingreso según origen.
            // Doble null-safe: si ejecucion o flujo no existe, se omite la creación.
            if ($flujo = $ejecucion?->flujo) {
                ProspectoEnFlujo::crearBatch($flujo, array_values($idsToCreate), $tipoMensaje, $now, estado: 'en_proceso');
            }
        }

        // Obtener los ProspectoEnFlujo para este chunk
        // Eager load prospecto.importacion.lote for batch provider pre-resolution
        return ProspectoEnFlujo::with(['prospecto.importacion.lote'])
            ->where('flujo_id', $this->flujoId)
            ->whereIn('prospecto_id', $prospectoIds)
            ->where('cancelado', false)
            ->where('completado', false)
            ->get();
    }

    /**
     * Pre-resolve email provider at chunk level to avoid N+1 queries.
     *
     * @param  \Illuminate\Support\Collection  $prospectosEnFlujo  Collection with prospecto.importacion.lote eager loaded
     * @return string|null Provider name ('athena'|'certificada') or null if mixed chunk
     */
    private function preResolveChunkProvider(\Illuminate\Support\Collection $prospectosEnFlujo): ?string
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
                $isHomogeneous = false;
                Log::debug('EnviarEtapaChunkJob: Mixed provider chunk', [
                    'chunk_index' => $this->chunkIndex,
                    'chunk_size' => $prospectosEnFlujo->count(),
                ]);
                break;
            }
        }

        return $isHomogeneous ? $providerName : null;
    }

    /**
     * @param  string|null  $providerName  Pre-resolved provider name for batch optimization
     * @param  array<string, true>  $enviados  Set de pares "{prospecto_id}:{canal}" ya enviados (idempotencia)
     */
    private function createJobForProspecto(
        ProspectoEnFlujo $prospectoEnFlujo,
        array $contenidoData,
        string $tipoMensaje,
        ?string $providerName = null,
        array $enviados = [],
    ): ?ShouldQueue {
        $pid = $prospectoEnFlujo->prospecto_id;

        if ($tipoMensaje === 'sms') {
            // Skip if SMS already sent/pending for this etapa
            if (isset($enviados["{$pid}:sms"])) {
                return null;
            }

            return new EnviarSmsEtapaProspectoJob(
                prospectoEnFlujoId: $prospectoEnFlujo->id,
                contenido: $contenidoData['contenido'],
                flujoId: $this->flujoId,
                etapaEjecucionId: $this->etapaEjecucionId
            );
        }

        // For 'email' and 'ambos' (which falls through to email in ChunkJob),
        // skip if email already sent/pending for this etapa.
        if (isset($enviados["{$pid}:email"])) {
            return null;
        }

        return new EnviarEmailEtapaProspectoJob(
            prospectoEnFlujoId: $prospectoEnFlujo->id,
            contenido: $contenidoData['contenido'],
            asunto: $contenidoData['asunto'] ?? $this->stage['template']['asunto'] ?? 'Mensaje',
            flujoId: $this->flujoId,
            etapaEjecucionId: $this->etapaEjecucionId,
            esHtml: $contenidoData['es_html'],
            providerName: $providerName
        );
    }

    private function obtenerContenidoMensaje(): array
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';
        $stageId = $this->stage['id'] ?? null;

        if ($stageId) {
            $flujoEtapa = FlujoEtapa::find($stageId);

            if ($flujoEtapa && $flujoEtapa->usaPlantillaReferencia()) {
                return $flujoEtapa->obtenerContenidoParaEnvio($tipoMensaje);
            }
        }

        $contenido = $this->stage['plantilla_mensaje'] ?? $this->stage['data']['contenido'] ?? '';

        return [
            'contenido' => $contenido,
            'asunto' => $this->stage['template']['asunto'] ?? $this->stage['data']['template']['asunto'] ?? null,
            'es_html' => $this->detectarSiEsHtml($contenido),
        ];
    }

    private function detectarSiEsHtml(string $contenido): bool
    {
        return (bool) preg_match('/<(html|body|div|p|br|table|a\s+href)/i', $contenido);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('EnviarEtapaChunkJob: Falló', [
            'chunk_index' => $this->chunkIndex,
            'error' => $exception->getMessage(),
        ]);
    }
}
