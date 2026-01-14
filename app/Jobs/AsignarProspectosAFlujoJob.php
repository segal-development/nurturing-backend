<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\ProspectoEnFlujo;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsignarProspectosAFlujoJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $timeout = 1800; // 30 minutos para datasets grandes (300k+)

    public int $tries = 3; // 3 intentos en caso de fallo

    /**
     * Tiempo en segundos que el job se considera único.
     * Previene duplicados si alguien intenta crear el mismo flujo múltiples veces.
     */
    public int $uniqueFor = 3600; // 1 hora

    /**
     * Número de registros por chunk.
     * 2000 es un buen balance entre velocidad y uso de memoria.
     */
    private const CHUNK_SIZE = 2000;

    /**
     * Máximo de reintentos por chunk individual.
     */
    private const MAX_CHUNK_RETRIES = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Flujo $flujo,
        public array $prospectoIds,
        public string $canalAsignado
    ) {}

    /**
     * ID único para prevenir jobs duplicados del mismo flujo.
     */
    public function uniqueId(): string
    {
        return 'flujo-asignacion-'.$this->flujo->id;
    }

    /**
     * Execute the job.
     * Procesa la asignación de prospectos en chunks para optimizar memoria y performance.
     * Reconecta a la BD entre chunks para evitar timeouts en Cloud SQL con datasets grandes.
     * Actualiza el progreso en cada chunk para que el frontend pueda mostrarlo.
     */
    public function handle(): void
    {
        $totalProspectos = count($this->prospectoIds);
        $chunks = array_chunk($this->prospectoIds, self::CHUNK_SIZE);
        $totalChunks = count($chunks);
        $totalProcesados = 0;

        $inicioTimestamp = now();

        // IMPORTANTE: Resetear estado a "procesando" al iniciar/reiniciar el job
        // Esto maneja el caso donde Laravel reintenta el job después de un fallo
        // y el estado quedó como "fallido" del intento anterior
        $this->flujo->refresh();
        $this->flujo->update(['estado_procesamiento' => 'procesando']);

        Log::info('🚀 Iniciando asignación de prospectos', [
            'flujo_id' => $this->flujo->id,
            'total_prospectos' => $totalProspectos,
            'chunks' => $totalChunks,
            'chunk_size' => self::CHUNK_SIZE,
        ]);

        // Inicializar progreso en metadata
        $this->actualizarProgreso(0, $totalProspectos, $totalChunks, 0, $inicioTimestamp);

        foreach ($chunks as $index => $chunk) {
            $chunkActual = $index + 1;
            $chunkProcesado = false;
            $intentos = 0;

            while (! $chunkProcesado && $intentos < self::MAX_CHUNK_RETRIES) {
                $intentos++;

                try {
                    // Solo reconectar en reintentos (no en el primer intento)
                    // Esto evita agotar el pool de conexiones en instancias pequeñas
                    if ($intentos > 1) {
                        DB::reconnect();
                        sleep(1); // Pequeña pausa antes de reintentar
                    }

                    $data = array_map(function ($prospectoId) use ($inicioTimestamp) {
                        return [
                            'flujo_id' => $this->flujo->id,
                            'prospecto_id' => $prospectoId,
                            'canal_asignado' => $this->canalAsignado,
                            'estado' => 'pendiente',
                            'etapa_actual_id' => null,
                            'fecha_inicio' => $inicioTimestamp,
                            'created_at' => $inicioTimestamp,
                            'updated_at' => $inicioTimestamp,
                        ];
                    }, $chunk);

                    // insertOrIgnore para manejar reintentos del job sin duplicados
                    ProspectoEnFlujo::insertOrIgnore($data);

                    $totalProcesados += count($chunk);
                    $chunkProcesado = true;

                    // Actualizar progreso cada 5 chunks para no saturar la BD
                    // En datasets grandes (150+ chunks) esto reduce queries de 150 a 30
                    if ($chunkActual % 5 === 0 || $chunkActual === $totalChunks) {
                        $this->actualizarProgreso(
                            $totalProcesados,
                            $totalProspectos,
                            $totalChunks,
                            $chunkActual,
                            $inicioTimestamp
                        );
                    }

                    Log::info('📊 Chunk procesado', [
                        'flujo_id' => $this->flujo->id,
                        'chunk' => $chunkActual,
                        'total_chunks' => $totalChunks,
                        'procesados' => $totalProcesados,
                        'porcentaje' => round(($totalProcesados / $totalProspectos) * 100, 2),
                    ]);

                } catch (\Throwable $e) {
                    Log::warning('⚠️ Error en chunk, reintentando...', [
                        'flujo_id' => $this->flujo->id,
                        'chunk' => $chunkActual,
                        'intento' => $intentos,
                        'max_intentos' => self::MAX_CHUNK_RETRIES,
                        'error' => $e->getMessage(),
                    ]);

                    // Esperar antes de reintentar (backoff exponencial)
                    if ($intentos < self::MAX_CHUNK_RETRIES) {
                        sleep(pow(2, $intentos)); // 2s, 4s, 8s
                    }
                }
            }

            // Si después de todos los intentos no se procesó, lanzar excepción
            if (! $chunkProcesado) {
                throw new \RuntimeException(
                    "Chunk {$chunkActual} falló después de ".self::MAX_CHUNK_RETRIES.' intentos'
                );
            }

            // Liberar memoria cada 10 chunks en datasets muy grandes
            if ($chunkActual % 10 === 0) {
                gc_collect_cycles();
            }
        }

        // Finalizar con estado completado
        $this->finalizarProcesamiento($totalProspectos, $inicioTimestamp);

        Log::info('✅ Asignación de prospectos completada', [
            'flujo_id' => $this->flujo->id,
            'total_procesados' => $totalProcesados,
            'duracion' => now()->diffInSeconds($inicioTimestamp).' segundos',
        ]);
    }

    /**
     * Actualiza el progreso del procesamiento en la metadata del flujo.
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

        // Estimar tiempo restante basado en velocidad actual
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
     * Finaliza el procesamiento y actualiza la metadata final.
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
     * Handle a job failure.
     * Solo se llama después de agotar todos los reintentos (tries).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('❌ Error al asignar prospectos al flujo', [
            'flujo_id' => $this->flujo->id,
            'intento' => $this->attempts(),
            'max_intentos' => $this->tries,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Refrescar para obtener el estado actual de la BD
        $this->flujo->refresh();

        // No sobrescribir si ya está completado (otro proceso pudo haberlo completado)
        if ($this->flujo->estado_procesamiento === 'completado') {
            Log::info('⚠️ Flujo ya completado, no marcando como fallido', [
                'flujo_id' => $this->flujo->id,
            ]);

            return;
        }

        // Marcar el flujo como fallido
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
