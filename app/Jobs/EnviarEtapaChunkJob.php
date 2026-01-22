<?php

namespace App\Jobs;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoEtapa;
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
        Log::info('EnviarEtapaChunkJob: Procesando chunk', [
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'flujo_id' => $this->flujoId,
        ]);

        $ejecucion = FlujoEjecucion::find($this->flujoEjecucionId);
        if (!$ejecucion) {
            Log::error('EnviarEtapaChunkJob: Ejecución no encontrada');
            return;
        }

        $etapaEjecucion = FlujoEjecucionEtapa::find($this->etapaEjecucionId);
        if (!$etapaEjecucion) {
            Log::error('EnviarEtapaChunkJob: Etapa no encontrada');
            return;
        }

        // Obtener prospectos para este chunk desde la BD
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

        // Crear jobs para este chunk
        $jobs = [];
        foreach ($prospectosEnFlujo as $prospectoEnFlujo) {
            $job = $this->createJobForProspecto($prospectoEnFlujo, $contenidoData, $tipoMensaje);
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

        $batch = Bus::batch($jobs)
            ->name($batchName)
            ->onQueue('envios')
            ->allowFailures()
            ->dispatch();

        Log::info('EnviarEtapaChunkJob: Batch despachado', [
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
     */
    private function obtenerProspectosChunk(): \Illuminate\Support\Collection
    {
        $tipoMensaje = $this->stage['tipo_mensaje'] ?? 'email';

        // Primero obtener los IDs de prospectos asignados al flujo en este rango
        $prospectoIds = ProspectoEnFlujo::where('flujo_id', $this->flujoId)
            ->orderBy('id')
            ->skip($this->offset)
            ->take($this->limit)
            ->pluck('prospecto_id')
            ->toArray();

        if (empty($prospectoIds)) {
            return collect();
        }

        // Crear registros en ProspectoEnFlujo si no existen
        $existingIds = ProspectoEnFlujo::where('flujo_id', $this->flujoId)
            ->whereIn('prospecto_id', $prospectoIds)
            ->pluck('prospecto_id')
            ->toArray();

        $idsToCreate = array_diff($prospectoIds, $existingIds);

        if (!empty($idsToCreate)) {
            $now = now();
            $insertData = array_map(function ($prospectoId) use ($tipoMensaje, $now) {
                return [
                    'prospecto_id' => $prospectoId,
                    'flujo_id' => $this->flujoId,
                    'canal_asignado' => $tipoMensaje,
                    'estado' => 'en_proceso',
                    'fecha_inicio' => $now,
                    'completado' => false,
                    'cancelado' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $idsToCreate);

            ProspectoEnFlujo::insert($insertData);
        }

        // Obtener los ProspectoEnFlujo para este chunk
        return ProspectoEnFlujo::where('flujo_id', $this->flujoId)
            ->whereIn('prospecto_id', $prospectoIds)
            ->get();
    }

    private function createJobForProspecto(ProspectoEnFlujo $prospectoEnFlujo, array $contenidoData, string $tipoMensaje): ?ShouldQueue
    {
        if ($tipoMensaje === 'sms') {
            return new EnviarSmsEtapaProspectoJob(
                prospectoEnFlujoId: $prospectoEnFlujo->id,
                contenido: $contenidoData['contenido'],
                flujoId: $this->flujoId,
                etapaEjecucionId: $this->etapaEjecucionId
            );
        }

        return new EnviarEmailEtapaProspectoJob(
            prospectoEnFlujoId: $prospectoEnFlujo->id,
            contenido: $contenidoData['contenido'],
            asunto: $contenidoData['asunto'] ?? $this->stage['template']['asunto'] ?? 'Mensaje',
            flujoId: $this->flujoId,
            etapaEjecucionId: $this->etapaEjecucionId,
            esHtml: $contenidoData['es_html']
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
