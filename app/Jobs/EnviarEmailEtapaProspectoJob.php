<?php

namespace App\Jobs;

use App\Jobs\Middleware\RateLimitedMiddleware;
use App\Models\ProspectoEnFlujo;
use App\Services\EnvioService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
 * - Idempotencia: ShouldBeUnique previene jobs duplicados en cola
 */
class EnviarEmailEtapaProspectoJob implements ShouldQueue, ShouldBeUnique
{
    use Batchable, Queueable;

    /**
     * The number of times the job may be attempted.
     * High value because rate-limited releases count as attempts.
     */
    public int $tries = 0; // 0 = unlimited attempts (controlled by $maxExceptions)

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     * This is the REAL limit - only counts actual failures, not rate-limit releases.
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [30, 60, 120];

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 60;

    /**
     * Tiempo (segundos) que el lock de unicidad permanece activo.
     * Previene que el mismo job se encole dos veces en este período.
     */
    public int $uniqueFor = 300; // 5 minutos

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
        // Load timeout from config (tries and maxExceptions are set as properties)
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

    /**
     * Llave única para idempotencia.
     * 
     * Combina prospecto + etapa para garantizar que solo UN job
     * por prospecto/etapa pueda estar en cola a la vez.
     * 
     * Esto previene duplicados cuando:
     * - Cloud Run mata una instancia y re-encola el job
     * - Hay race conditions al crear jobs
     * - Se reintenta manualmente una etapa
     */
    public function uniqueId(): string
    {
        return "email:{$this->prospectoEnFlujoId}:{$this->etapaEjecucionId}";
    }
}
