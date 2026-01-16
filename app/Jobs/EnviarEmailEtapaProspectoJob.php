<?php

namespace App\Jobs;

use App\Jobs\Middleware\RateLimitedMiddleware;
use App\Models\ProspectoEnFlujo;
use App\Services\EnvioService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job para enviar UN email a UN prospecto dentro de una etapa de flujo.
 *
 * Este job está diseñado para ser ejecutado en batch con Bus::batch()
 * para manejar grandes volúmenes de prospectos (20k-350k+) sin timeout.
 *
 * Características:
 * - Rate limiting via RateLimitedMiddleware (configurable en config/envios.php)
 * - Circuit breaker para manejar fallos del proveedor SMTP
 * - Usa el trait Batchable para integrarse con Bus::batch()
 * - Incluye tracking de aperturas (pixel) y clicks (URL rewrite)
 * - Reintentos automáticos con backoff exponencial
 */
class EnviarEmailEtapaProspectoJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff;

    /**
     * Job timeout in seconds.
     */
    public int $timeout;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $prospectoEnFlujoId,
        public string $contenido,
        public string $asunto,
        public ?int $flujoId = null,
        public ?int $etapaEjecucionId = null,
        public bool $esHtml = false
    ) {
        // Load configuration from config/envios.php
        $this->tries = config('envios.queue.tries', 3);
        $this->backoff = config('envios.queue.backoff', [30, 60, 120]);
        $this->timeout = config('envios.queue.timeout', 60);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimitedMiddleware('email'),
        ];
    }

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

        $result = $envioService->enviarEmailAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: $this->contenido,
            asunto: $this->asunto,
            flujoId: $this->flujoId,
            etapaEjecucionId: $this->etapaEjecucionId,
            esHtml: $this->esHtml
        );

        if (! $result['success']) {
            throw new \Exception($result['error'] ?? 'Error desconocido al enviar email');
        }
    }

    /**
     * Check if this job should be skipped (batch cancelled)
     */
    private function shouldSkipBatchJob(): bool
    {
        if ($this->batch()?->cancelled()) {
            Log::info('EnviarEmailEtapaProspectoJob: Batch cancelado, omitiendo', [
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
            Log::error('EnviarEmailEtapaProspectoJob: ProspectoEnFlujo no encontrado', [
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
        Log::error('EnviarEmailEtapaProspectoJob: Falló definitivamente', [
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
            'enviar-email-etapa',
        ];

        if ($this->etapaEjecucionId) {
            $tags[] = 'etapa-ejecucion:'.$this->etapaEjecucionId;
        }

        return $tags;
    }
}
