<?php

namespace App\Jobs;

use App\DTOs\CriteriosSeleccionProspectos;
use App\Models\Flujo;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job para asignar prospectos a un flujo de manera asíncrona.
 *
 * IMPORTANTE: Este job NO recibe los IDs de prospectos directamente.
 * En su lugar, recibe criterios de selección y construye la query internamente.
 * Esto permite procesar millones de prospectos sin cargar IDs en memoria.
 *
 * @see CriteriosSeleccionProspectos
 */
class AsignarProspectosAFlujoJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $timeout = 3600; // 1 hora para datasets muy grandes (1M+)

    public int $tries = 3;

    public int $uniqueFor = 3600;

    private const CHUNK_SIZE = 2000;

    private const MAX_CHUNK_RETRIES = 3;

    private const PROGRESS_UPDATE_INTERVAL = 5; // Actualizar progreso cada N chunks

    /**
     * Criterios de selección serializados.
     * Usamos array en vez del DTO para que Laravel pueda serializar el job.
     */
    private array $criteriosArray;

    public function __construct(
        public Flujo $flujo,
        CriteriosSeleccionProspectos|array $criterios,
        public string $canalAsignado
    ) {
        // Convertir DTO a array para serialización
        $this->criteriosArray = $criterios instanceof CriteriosSeleccionProspectos
            ? $criterios->toArray()
            : $criterios;
    }

    public function uniqueId(): string
    {
        return 'flujo-asignacion-'.$this->flujo->id;
    }

    /**
     * Reconstruye el DTO desde el array serializado.
     */
    private function getCriterios(): CriteriosSeleccionProspectos
    {
        return CriteriosSeleccionProspectos::fromArray($this->criteriosArray);
    }

    /**
     * Construye la query base para seleccionar prospectos según los criterios.
     */
    private function buildProspectosQuery(): Builder
    {
        $criterios = $this->getCriterios();

        // Si hay IDs específicos, usarlos directamente
        if (!$criterios->usarQueryPorCriterios()) {
            return Prospecto::query()->whereIn('id', $criterios->prospectoIds);
        }

        // Construir query por criterios (sin cargar IDs en memoria)
        $query = Prospecto::query()
            ->whereHas('importacion', function ($q) use ($criterios) {
                $q->where('origen', $criterios->origen);
            });

        // Filtrar por tipo solo si no es "Todos"
        if ($criterios->tipoProspectoId !== null) {
            $query->where('tipo_prospecto_id', $criterios->tipoProspectoId);
        }

        return $query;
    }

    /**
     * Ejecuta el job procesando prospectos en chunks.
     */
    public function handle(): void
    {
        $inicioTimestamp = now();

        // Inicializar estado
        $this->flujo->refresh();
        $this->flujo->update(['estado_procesamiento' => 'procesando']);

        // Obtener conteo total SIN cargar IDs en memoria
        $totalProspectos = $this->buildProspectosQuery()->count();

        if ($totalProspectos === 0) {
            Log::warning('⚠️ No se encontraron prospectos para asignar', [
                'flujo_id' => $this->flujo->id,
                'criterios' => $this->criteriosArray,
            ]);
            $this->finalizarProcesamiento(0, $inicioTimestamp);
            return;
        }

        $totalChunks = (int) ceil($totalProspectos / self::CHUNK_SIZE);

        Log::info('🚀 Iniciando asignación de prospectos (modo criterios)', [
            'flujo_id' => $this->flujo->id,
            'total_prospectos' => $totalProspectos,
            'chunks_estimados' => $totalChunks,
            'chunk_size' => self::CHUNK_SIZE,
            'usa_query_criterios' => $this->getCriterios()->usarQueryPorCriterios(),
        ]);

        // Inicializar progreso
        $this->actualizarProgreso(0, $totalProspectos, $totalChunks, 0, $inicioTimestamp);

        // Procesar en chunks usando chunkById (eficiente en memoria)
        $totalProcesados = 0;
        $chunkActual = 0;

        $this->buildProspectosQuery()
            ->select('id') // Solo necesitamos el ID
            ->chunkById(self::CHUNK_SIZE, function ($prospectos) use (
                &$totalProcesados,
                &$chunkActual,
                $totalProspectos,
                $totalChunks,
                $inicioTimestamp
            ) {
                $chunkActual++;

                $this->procesarChunkConReintentos(
                    $prospectos->pluck('id')->toArray(),
                    $chunkActual,
                    $inicioTimestamp
                );

                $totalProcesados += $prospectos->count();

                // Actualizar progreso cada N chunks
                if ($chunkActual % self::PROGRESS_UPDATE_INTERVAL === 0 || $chunkActual === $totalChunks) {
                    $this->actualizarProgreso(
                        $totalProcesados,
                        $totalProspectos,
                        $totalChunks,
                        $chunkActual,
                        $inicioTimestamp
                    );
                }

                // Liberar memoria periódicamente
                if ($chunkActual % 10 === 0) {
                    gc_collect_cycles();
                }

                Log::info('📊 Chunk procesado', [
                    'flujo_id' => $this->flujo->id,
                    'chunk' => $chunkActual,
                    'procesados' => $totalProcesados,
                    'porcentaje' => round(($totalProcesados / $totalProspectos) * 100, 2),
                ]);
            });

        // Finalizar
        $this->finalizarProcesamiento($totalProcesados, $inicioTimestamp);

        Log::info('✅ Asignación de prospectos completada', [
            'flujo_id' => $this->flujo->id,
            'total_procesados' => $totalProcesados,
            'duracion_segundos' => now()->diffInSeconds($inicioTimestamp),
        ]);
    }

    /**
     * Procesa un chunk con reintentos automáticos.
     */
    private function procesarChunkConReintentos(array $prospectoIds, int $chunkActual, \Carbon\Carbon $inicio): void
    {
        $intentos = 0;
        $exito = false;

        while (!$exito && $intentos < self::MAX_CHUNK_RETRIES) {
            $intentos++;

            try {
                // Reconectar solo en reintentos
                if ($intentos > 1) {
                    DB::reconnect();
                    sleep(1);
                }

                $data = array_map(fn($prospectoId) => [
                    'flujo_id' => $this->flujo->id,
                    'prospecto_id' => $prospectoId,
                    'canal_asignado' => $this->canalAsignado,
                    'estado' => 'pendiente',
                    'etapa_actual_id' => null,
                    'fecha_inicio' => $inicio,
                    'created_at' => $inicio,
                    'updated_at' => $inicio,
                ], $prospectoIds);

                ProspectoEnFlujo::insertOrIgnore($data);
                $exito = true;

            } catch (\Throwable $e) {
                Log::warning('⚠️ Error en chunk, reintentando...', [
                    'flujo_id' => $this->flujo->id,
                    'chunk' => $chunkActual,
                    'intento' => $intentos,
                    'error' => $e->getMessage(),
                ]);

                if ($intentos < self::MAX_CHUNK_RETRIES) {
                    sleep(pow(2, $intentos)); // Backoff exponencial
                }
            }
        }

        if (!$exito) {
            throw new \RuntimeException(
                "Chunk {$chunkActual} falló después de " . self::MAX_CHUNK_RETRIES . ' intentos'
            );
        }
    }

    /**
     * Actualiza el progreso en la metadata del flujo.
     */
    private function actualizarProgreso(
        int $procesados,
        int $total,
        int $totalChunks,
        int $chunkActual,
        \Carbon\Carbon $inicio
    ): void {
        $porcentaje = $total > 0 ? round(($procesados / $total) * 100, 2) : 0;
        $segundosTranscurridos = now()->diffInSeconds($inicio);

        $velocidadPorSegundo = $segundosTranscurridos > 0 ? $procesados / $segundosTranscurridos : 0;
        $restantes = $total - $procesados;
        $segundosRestantes = $velocidadPorSegundo > 0 ? (int) ceil($restantes / $velocidadPorSegundo) : null;

        $this->flujo->refresh();
        $this->flujo->update([
            'metadata' => array_merge($this->flujo->metadata ?? [], [
                'progreso' => [
                    'procesados' => $procesados,
                    'total' => $total,
                    'porcentaje' => $porcentaje,
                    'chunk_actual' => $chunkActual,
                    'total_chunks' => $totalChunks,
                    'inicio' => $inicio->toISOString(),
                    'ultima_actualizacion' => now()->toISOString(),
                    'segundos_transcurridos' => $segundosTranscurridos,
                    'segundos_restantes_estimados' => $segundosRestantes,
                    'velocidad_por_segundo' => round($velocidadPorSegundo, 2),
                ],
            ]),
        ]);
    }

    /**
     * Finaliza el procesamiento exitosamente.
     */
    private function finalizarProcesamiento(int $totalProspectos, \Carbon\Carbon $inicio): void
    {
        $conteoEmail = $this->canalAsignado === 'email' ? $totalProspectos : 0;
        $conteoSms = $this->canalAsignado === 'sms' ? $totalProspectos : 0;
        $duracionSegundos = now()->diffInSeconds($inicio);

        $this->flujo->refresh();
        $this->flujo->update([
            'estado_procesamiento' => 'completado',
            'metadata' => array_merge($this->flujo->metadata ?? [], [
                'resumen' => [
                    'total_prospectos' => $totalProspectos,
                    'prospectos_email' => $conteoEmail,
                    'prospectos_sms' => $conteoSms,
                ],
                'procesamiento' => [
                    'inicio' => $inicio->toISOString(),
                    'fin' => now()->toISOString(),
                    'duracion_segundos' => $duracionSegundos,
                    'modo' => 'criterios', // Indicar que usó el nuevo modo
                ],
                'progreso' => [
                    'procesados' => $totalProspectos,
                    'total' => $totalProspectos,
                    'porcentaje' => 100,
                    'completado' => true,
                    'inicio' => $inicio->toISOString(),
                    'fin' => now()->toISOString(),
                    'duracion_segundos' => $duracionSegundos,
                ],
            ]),
        ]);
    }

    /**
     * Maneja fallos del job.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('❌ Error al asignar prospectos al flujo', [
            'flujo_id' => $this->flujo->id,
            'intento' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        $this->flujo->refresh();

        if ($this->flujo->estado_procesamiento === 'completado') {
            return;
        }

        $this->flujo->update([
            'estado_procesamiento' => 'fallido',
            'metadata' => array_merge($this->flujo->metadata ?? [], [
                'error' => [
                    'mensaje' => $exception->getMessage(),
                    'fecha' => now()->toISOString(),
                    'intento_final' => $this->attempts(),
                ],
            ]),
        ]);
    }
}
