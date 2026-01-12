<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\ProspectoEnFlujo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AsignarProspectosAFlujoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600; // 10 minutos de timeout

    public int $tries = 3; // 3 intentos en caso de fallo

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Flujo $flujo,
        public array $prospectoIds,
        public string $canalAsignado
    ) {}

    /**
     * Execute the job.
     * Procesa la asignación de prospectos en chunks para optimizar memoria y performance.
     * Actualiza el progreso en cada chunk para que el frontend pueda mostrarlo.
     */
    public function handle(): void
    {
        $chunkSize = 1000;
        $totalProspectos = count($this->prospectoIds);
        $chunks = array_chunk($this->prospectoIds, $chunkSize);
        $totalChunks = count($chunks);
        $totalProcesados = 0;

        $inicioTimestamp = now();

        Log::info('🚀 Iniciando asignación de prospectos', [
            'flujo_id' => $this->flujo->id,
            'total_prospectos' => $totalProspectos,
            'chunks' => $totalChunks,
        ]);

        // Inicializar progreso en metadata
        $this->actualizarProgreso(0, $totalProspectos, $totalChunks, 0, $inicioTimestamp);

        foreach ($chunks as $index => $chunk) {
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

            ProspectoEnFlujo::insert($data);

            $totalProcesados += count($chunk);
            $chunkActual = $index + 1;

            // Actualizar progreso en cada chunk
            $this->actualizarProgreso(
                $totalProcesados,
                $totalProspectos,
                $totalChunks,
                $chunkActual,
                $inicioTimestamp
            );

            Log::info('📊 Chunk procesado', [
                'flujo_id' => $this->flujo->id,
                'chunk' => $chunkActual,
                'total_chunks' => $totalChunks,
                'procesados' => $totalProcesados,
                'porcentaje' => round(($totalProcesados / $totalProspectos) * 100, 2),
            ]);
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
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('❌ Error al asignar prospectos al flujo', [
            'flujo_id' => $this->flujo->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Marcar el flujo como fallido
        $this->flujo->update([
            'estado_procesamiento' => 'fallido',
            'metadata' => array_merge($this->flujo->metadata ?? [], [
                'error' => [
                    'mensaje' => $exception->getMessage(),
                    'fecha' => now()->toISOString(),
                ],
            ]),
        ]);
    }
}
