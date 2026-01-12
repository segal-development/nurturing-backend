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
     */
    public function handle(): void
    {
        $chunkSize = 1000; // Procesar 1000 registros a la vez
        $totalProspectos = count($this->prospectoIds);
        $chunks = array_chunk($this->prospectoIds, $chunkSize);
        $totalProcesados = 0;

        Log::info('🚀 Iniciando asignación de prospectos', [
            'flujo_id' => $this->flujo->id,
            'total_prospectos' => $totalProspectos,
            'chunks' => count($chunks),
        ]);

        $now = now();

        foreach ($chunks as $index => $chunk) {
            // Preparar datos para insert masivo
            $data = array_map(function ($prospectoId) use ($now) {
                return [
                    'flujo_id' => $this->flujo->id,
                    'prospecto_id' => $prospectoId,
                    'canal_asignado' => $this->canalAsignado,
                    'estado' => 'pendiente',
                    'etapa_actual_id' => null,
                    'fecha_inicio' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $chunk);

            // Insert masivo del chunk
            ProspectoEnFlujo::insert($data);

            $totalProcesados += count($chunk);

            Log::info('📊 Chunk procesado', [
                'flujo_id' => $this->flujo->id,
                'chunk' => $index + 1,
                'total_chunks' => count($chunks),
                'procesados' => $totalProcesados,
                'porcentaje' => round(($totalProcesados / $totalProspectos) * 100, 2),
            ]);
        }

        // Actualizar metadata del flujo con el conteo real
        $conteoEmail = $this->canalAsignado === 'email' ? $totalProspectos : 0;
        $conteoSms = $this->canalAsignado === 'sms' ? $totalProspectos : 0;

        $this->flujo->update([
            'estado_procesamiento' => 'completado',
            'metadata' => array_merge($this->flujo->metadata ?? [], [
                'resumen' => [
                    'total_prospectos' => $totalProspectos,
                    'prospectos_email' => $conteoEmail,
                    'prospectos_sms' => $conteoSms,
                ],
                'procesamiento' => [
                    'inicio' => $now->toISOString(),
                    'fin' => now()->toISOString(),
                    'duracion_segundos' => now()->diffInSeconds($now),
                ],
            ]),
        ]);

        Log::info('✅ Asignación de prospectos completada', [
            'flujo_id' => $this->flujo->id,
            'total_procesados' => $totalProcesados,
            'duracion' => now()->diffInSeconds($now).' segundos',
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
