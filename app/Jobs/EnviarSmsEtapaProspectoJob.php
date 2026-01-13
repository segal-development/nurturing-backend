<?php

namespace App\Jobs;

use App\Models\ProspectoEnFlujo;
use App\Services\EnvioService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job para enviar UN SMS a UN prospecto dentro de una etapa de flujo.
 *
 * Este job está diseñado para ser ejecutado en batch con Bus::batch()
 * para manejar grandes volúmenes de prospectos (20k-350k+) sin timeout.
 *
 * Características:
 * - Usa el trait Batchable para integrarse con Bus::batch()
 * - Reintentos automáticos con backoff exponencial
 * - Manejo robusto de errores
 */
class EnviarSmsEtapaProspectoJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $prospectoEnFlujoId,
        public string $contenido,
        public ?int $flujoId = null,
        public ?int $etapaEjecucionId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EnvioService $envioService): void
    {
        if ($this->shouldSkipBatchJob()) {
            return;
        }

        $prospectoEnFlujo = $this->loadProspectoEnFlujo();

        if (! $prospectoEnFlujo) {
            return;
        }

        $result = $envioService->enviarSmsAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: $this->contenido,
            flujoId: $this->flujoId,
            etapaEjecucionId: $this->etapaEjecucionId
        );

        if (! $result['success']) {
            throw new \Exception($result['error'] ?? 'Error desconocido al enviar SMS');
        }
    }

    /**
     * Check if this job should be skipped (batch cancelled)
     */
    private function shouldSkipBatchJob(): bool
    {
        if ($this->batch()?->cancelled()) {
            Log::info('EnviarSmsEtapaProspectoJob: Batch cancelado, omitiendo', [
                'prospecto_en_flujo_id' => $this->prospectoEnFlujoId,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Load ProspectoEnFlujo with related models
     */
    private function loadProspectoEnFlujo(): ?ProspectoEnFlujo
    {
        $prospectoEnFlujo = ProspectoEnFlujo::with('prospecto')
            ->find($this->prospectoEnFlujoId);

        if (! $prospectoEnFlujo) {
            Log::error('EnviarSmsEtapaProspectoJob: ProspectoEnFlujo no encontrado', [
                'prospecto_en_flujo_id' => $this->prospectoEnFlujoId,
            ]);

            return null;
        }

        return $prospectoEnFlujo;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('EnviarSmsEtapaProspectoJob: Falló definitivamente', [
            'prospecto_en_flujo_id' => $this->prospectoEnFlujoId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        $tags = [
            'prospecto-en-flujo:'.$this->prospectoEnFlujoId,
            'enviar-sms-etapa',
        ];

        if ($this->etapaEjecucionId) {
            $tags[] = 'etapa-ejecucion:'.$this->etapaEjecucionId;
        }

        return $tags;
    }
}
