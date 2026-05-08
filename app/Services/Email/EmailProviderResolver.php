<?php

namespace App\Services\Email;

use App\Contracts\EmailServiceInterface;
use App\Models\Prospecto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolves which email service to use with configurable primary provider and automatic fallback.
 *
 * Configuration via EMAIL_PRIMARY_PROVIDER env variable:
 * - 'athena' (default): Uses SMTP (Athena) as primary, Certificada as fallback
 * - 'certificada': Uses Certificada as primary, SMTP (Athena) as fallback
 *
 * Fallback Logic:
 * - If primary provider is unhealthy, automatically switches to fallback
 * - Health status is tracked via cache keys set by VerificarSaludApiJob
 * - When primary recovers, automatically switches back
 *
 * @see \App\Services\EnvioService::enviarEmailAProspecto()
 * @see \App\Jobs\VerificarSaludApiJob
 */
class EmailProviderResolver
{
    private const CERTIFICADA_HEALTH_CACHE_KEY = 'certificada_health_status';
    private const ATHENA_HEALTH_CACHE_KEY = 'athena_smtp_health_status';

    public function __construct(
        private SmtpEmailService $smtpService,
        private CertificadaEmailService $certificadaService,
    ) {}

    /**
     * Get the configured primary provider.
     * 
     * @return 'athena'|'certificada'
     */
    private function getPrimaryProvider(): string
    {
        $configured = config('services.email.primary_provider', 'athena');
        
        // Validate and default to athena if invalid
        if (!in_array($configured, ['athena', 'certificada'], true)) {
            Log::warning('EmailProviderResolver: Invalid EMAIL_PRIMARY_PROVIDER value, defaulting to athena', [
                'configured' => $configured,
            ]);
            return 'athena';
        }

        return $configured;
    }

    /**
     * Resolve the appropriate email service for a prospect.
     * 
     * Uses configured primary provider with automatic fallback:
     * - Primary: athena (SMTP) or certificada based on EMAIL_PRIMARY_PROVIDER
     * - Fallback: the other provider if primary is unhealthy
     */
    public function resolve(Prospecto $prospecto): EmailServiceInterface
    {
        $primary = $this->getPrimaryProvider();

        if ($primary === 'athena') {
            return $this->resolveWithAthenaAsPrimary($prospecto);
        }

        return $this->resolveWithCertificadaAsPrimary($prospecto);
    }

    /**
     * Resolve with Athena (SMTP) as primary, Certificada as fallback.
     */
    private function resolveWithAthenaAsPrimary(Prospecto $prospecto): EmailServiceInterface
    {
        // Try Athena first
        if ($this->isAthenaHealthy()) {
            return $this->smtpService;
        }

        // Fallback to Certificada
        Log::warning('EmailProviderResolver: Athena unhealthy, falling back to Certificada', [
            'prospecto_id' => $prospecto->id,
            'source' => $this->getProspectoSource($prospecto),
            'reason' => $this->getAthenaUnhealthyReason(),
        ]);

        if ($this->isCertificadaHealthy()) {
            return $this->certificadaService;
        }

        // Both unhealthy - try Athena anyway (might recover)
        Log::error('EmailProviderResolver: Both providers unhealthy, attempting Athena anyway', [
            'prospecto_id' => $prospecto->id,
            'athena_reason' => $this->getAthenaUnhealthyReason(),
            'certificada_reason' => $this->getCertificadaUnhealthyReason(),
        ]);

        return $this->smtpService;
    }

    /**
     * Resolve with Certificada as primary, Athena (SMTP) as fallback.
     */
    private function resolveWithCertificadaAsPrimary(Prospecto $prospecto): EmailServiceInterface
    {
        // Try Certificada first
        if ($this->isCertificadaHealthy()) {
            return $this->certificadaService;
        }

        // Fallback to Athena
        Log::warning('EmailProviderResolver: Certificada unhealthy, falling back to Athena', [
            'prospecto_id' => $prospecto->id,
            'source' => $this->getProspectoSource($prospecto),
            'reason' => $this->getCertificadaUnhealthyReason(),
        ]);

        if ($this->isAthenaHealthy()) {
            return $this->smtpService;
        }

        // Both unhealthy - try Certificada anyway (might recover)
        Log::error('EmailProviderResolver: Both providers unhealthy, attempting Certificada anyway', [
            'prospecto_id' => $prospecto->id,
            'certificada_reason' => $this->getCertificadaUnhealthyReason(),
            'athena_reason' => $this->getAthenaUnhealthyReason(),
        ]);

        return $this->certificadaService;
    }

    /**
     * Determine the provider name that will be used (for logging/storage).
     */
    public function getProviderName(Prospecto $prospecto): string
    {
        $primary = $this->getPrimaryProvider();

        if ($primary === 'athena') {
            if ($this->isAthenaHealthy()) {
                return 'athena';
            }
            if ($this->isCertificadaHealthy()) {
                return 'certificada';
            }
            return 'athena'; // Both unhealthy, will try athena
        }

        // Primary is certificada
        if ($this->isCertificadaHealthy()) {
            return 'certificada';
        }
        if ($this->isAthenaHealthy()) {
            return 'athena';
        }
        return 'certificada'; // Both unhealthy, will try certificada
    }

    /**
     * Check if Athena (SMTP) service is healthy.
     */
    private function isAthenaHealthy(): bool
    {
        $healthStatus = Cache::get(self::ATHENA_HEALTH_CACHE_KEY, ['healthy' => true]);

        return $healthStatus['healthy'] ?? true;
    }

    /**
     * Check if Certificada service is available AND healthy.
     */
    private function isCertificadaHealthy(): bool
    {
        // First check if service is configured and enabled
        if (! $this->certificadaService->isAvailable()) {
            return false;
        }

        // Then check health status from cache (set by VerificarSaludApiJob)
        $healthStatus = Cache::get(self::CERTIFICADA_HEALTH_CACHE_KEY, ['healthy' => true]);

        return $healthStatus['healthy'] ?? true;
    }

    /**
     * Get reason why Athena is unhealthy (for logging).
     */
    private function getAthenaUnhealthyReason(): string
    {
        $healthStatus = Cache::get(self::ATHENA_HEALTH_CACHE_KEY, ['healthy' => true]);

        return $healthStatus['reason'] ?? 'Unknown';
    }

    /**
     * Get reason why Certificada is unhealthy (for logging).
     */
    private function getCertificadaUnhealthyReason(): string
    {
        if (! $this->certificadaService->isAvailable()) {
            return 'Service not configured or disabled';
        }

        $healthStatus = Cache::get(self::CERTIFICADA_HEALTH_CACHE_KEY, ['healthy' => true]);

        return $healthStatus['reason'] ?? 'Unknown';
    }

    /**
     * Get the source identifier for a prospect (for logging).
     */
    private function getProspectoSource(Prospecto $prospecto): string
    {
        $metadata = $prospecto->metadata;

        if (is_array($metadata) && ($metadata['source'] ?? null) === 'grupo_deuda') {
            $endpoint = $metadata['endpoint'] ?? 'unknown';
            return "grupo_deuda:{$endpoint}";
        }

        // Check IC lote
        if (! $prospecto->relationLoaded('importacion')) {
            $prospecto->load('importacion.lote');
        }

        $loteName = $prospecto->importacion?->lote?->nombre ?? '';
        if (str_starts_with($loteName, 'IC_')) {
            return 'ic_lote';
        }

        return 'other';
    }

    /**
     * Mark Athena (SMTP) as unhealthy (called when send fails).
     * 
     * @param string $reason Reason for marking unhealthy
     * @param int $ttlSeconds How long to keep unhealthy status (default: 5 minutes)
     */
    public static function markAthenaUnhealthy(string $reason, int $ttlSeconds = 300): void
    {
        Cache::put(self::ATHENA_HEALTH_CACHE_KEY, [
            'healthy' => false,
            'reason' => $reason,
            'marked_at' => now()->toIso8601String(),
        ], $ttlSeconds);

        Log::warning('EmailProviderResolver: Athena marked as unhealthy', [
            'reason' => $reason,
            'ttl_seconds' => $ttlSeconds,
        ]);
    }

    /**
     * Mark Athena (SMTP) as healthy.
     */
    public static function markAthenaHealthy(): void
    {
        Cache::put(self::ATHENA_HEALTH_CACHE_KEY, [
            'healthy' => true,
            'checked_at' => now()->toIso8601String(),
        ], 600); // 10 minutes TTL

        Log::info('EmailProviderResolver: Athena marked as healthy');
    }

    /**
     * Mark Certificada as unhealthy (called when send fails).
     * 
     * @param string $reason Reason for marking unhealthy
     * @param int $ttlSeconds How long to keep unhealthy status (default: 5 minutes)
     */
    public static function markCertificadaUnhealthy(string $reason, int $ttlSeconds = 300): void
    {
        Cache::put(self::CERTIFICADA_HEALTH_CACHE_KEY, [
            'healthy' => false,
            'reason' => $reason,
            'marked_at' => now()->toIso8601String(),
        ], $ttlSeconds);

        Log::warning('EmailProviderResolver: Certificada marked as unhealthy', [
            'reason' => $reason,
            'ttl_seconds' => $ttlSeconds,
        ]);
    }

    /**
     * Mark Certificada as healthy (called by health check job).
     */
    public static function markCertificadaHealthy(): void
    {
        Cache::put(self::CERTIFICADA_HEALTH_CACHE_KEY, [
            'healthy' => true,
            'checked_at' => now()->toIso8601String(),
        ], 600); // 10 minutes TTL

        Log::info('EmailProviderResolver: Certificada marked as healthy');
    }
}
