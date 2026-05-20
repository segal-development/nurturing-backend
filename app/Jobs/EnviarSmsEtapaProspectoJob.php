<?php

namespace App\Jobs;

use App\Jobs\Middleware\RateLimitedMiddleware;
use App\Models\ProspectoEnFlujo;
use App\Services\EnvioService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job para enviar UN SMS a UN prospecto dentro de una etapa de flujo.
 *
 * Este job está diseñado para ser ejecutado en batch con Bus::batch()
 * para manejar grandes volúmenes de prospectos (20k-350k+) sin timeout.
 *
 * Características:
 * - Rate limiting via RateLimitedMiddleware (configurable en config/envios.php)
 * - Circuit breaker para manejar fallos del proveedor SMS
 * - Usa el trait Batchable para integrarse con Bus::batch()
 * - Reintentos automáticos con backoff exponencial
 * - Idempotencia: ShouldBeUnique previene jobs duplicados en cola
 */
class EnviarSmsEtapaProspectoJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Queueable;

    /**
     * The number of times the job may be attempted.
     * 0 = ilimitado: un release por rate-limit NO debe matar el job (cuenta como attempt).
     * El límite real de FALLOS lo pone $maxExceptions.
     */
    public int $tries = 0;

    /**
     * Máximo de excepciones REALES antes de fallar (errores de proveedor).
     * NO cuenta los releases por rate-limit.
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [30, 60, 120];

    /**
     * Job timeout in seconds.
     */
    public int $timeout;

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
        public ?int $flujoId = null,
        public ?int $etapaEjecucionId = null
    ) {
        // tries/maxExceptions/backoff son propiedades fijas (patrón rate-limit-safe,
        // igual que EnviarEmailEtapaProspectoJob). Solo el timeout viene de config.
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
            new RateLimitedMiddleware('sms'),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(EnvioService $envioService): void
    {
        // Fail fast: validar configuración crítica antes de intentar enviar
        $this->validateSmsConfiguration();

        if ($this->shouldSkipBatchJob()) {
            return;
        }

        // Distributed lock to prevent duplicate processing across workers
        $lockKey = "processing:sms:{$this->prospectoEnFlujoId}:{$this->etapaEjecucionId}";
        $lock = Cache::lock($lockKey, 120); // 2 minutes max

        if (! $lock->get()) {
            // Another worker is already processing this, skip silently
            Log::debug('EnviarSmsEtapaProspectoJob: Lock already held, skipping', [
                'prospecto_en_flujo_id' => $this->prospectoEnFlujoId,
                'etapa_ejecucion_id' => $this->etapaEjecucionId,
            ]);

            return;
        }

        try {
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
        } finally {
            $lock->release();
        }
    }

    /**
     * Valida que la configuración de SMS esté correcta.
     *
     * Falla inmediatamente si falta configuración crítica, evitando
     * miles de jobs fallidos innecesariamente.
     *
     * @throws \RuntimeException Si falta configuración crítica
     */
    private function validateSmsConfiguration(): void
    {
        $token = config('services.sms.api_token');

        if (empty($token)) {
            $errorMsg = 'SMS_API_TOKEN no configurado. Ejecutar: php artisan config:cache && sudo supervisorctl restart nurturing-worker:*';

            Log::critical('EnviarSmsEtapaProspectoJob: Configuración de SMS inválida', [
                'error' => $errorMsg,
                'prospecto_en_flujo_id' => $this->prospectoEnFlujoId,
                'etapa_ejecucion_id' => $this->etapaEjecucionId,
            ]);

            // Este error NO debe reintentar - es un problema de configuración
            // que requiere intervención manual
            throw new \RuntimeException($errorMsg);
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

    /**
     * Llave única para idempotencia.
     *
     * Combina prospecto + etapa para garantizar que solo UN job
     * por prospecto/etapa pueda estar en cola a la vez.
     */
    public function uniqueId(): string
    {
        return "sms:{$this->prospectoEnFlujoId}:{$this->etapaEjecucionId}";
    }
}
