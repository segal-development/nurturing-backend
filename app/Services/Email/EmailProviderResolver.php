<?php

namespace App\Services\Email;

use App\Contracts\EmailServiceInterface;
use App\Models\Prospecto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolves which email service to use based on prospect characteristics.
 *
 * Implements the Strategy Pattern to route emails dynamically:
 * - Grupo Deudas prospects (metadata.source = 'grupo_deuda') use CertificadaEmailService
 * - IC prospects (lote name starts with "IC_") use CertificadaEmailService
 * - All other prospects use SmtpEmailService
 * - Falls back to SMTP if Certificada is unavailable or unhealthy
 *
 * Health Check Integration:
 * - Checks cache key 'certificada_health_status' set by VerificarSaludApiJob
 * - If Certificada is unhealthy, automatically falls back to SMTP
 * - When Certificada recovers, automatically switches back
 *
 * @see \App\Services\EnvioService::enviarEmailAProspecto()
 * @see \App\Jobs\VerificarSaludApiJob
 */
class EmailProviderResolver
{
    private const CERTIFICADA_HEALTH_CACHE_KEY = 'certificada_health_status';

    public function __construct(
        private SmtpEmailService $smtpService,
        private CertificadaEmailService $certificadaService,
    ) {}

    /**
     * Resolve the appropriate email service for a prospect.
     * 
     * Priority:
     * 1. Grupo Deudas prospects → Certificada (with SMTP fallback)
     * 2. IC prospects → Certificada (with SMTP fallback)
     * 3. All others → SMTP
     */
    public function resolve(Prospecto $prospecto): EmailServiceInterface
    {
        // Check if prospect should use Certificada
        if ($this->shouldUseCertificada($prospecto)) {
            // Check if Certificada is available AND healthy
            if ($this->isCertificadaHealthy()) {
                return $this->certificadaService;
            }

            // Fallback to SMTP if Certificada is not healthy
            Log::warning('EmailProviderResolver: Certificada unhealthy, falling back to SMTP', [
                'prospecto_id' => $prospecto->id,
                'source' => $this->getProspectoSource($prospecto),
                'reason' => $this->getCertificadaUnhealthyReason(),
            ]);
        }

        return $this->smtpService;
    }

    /**
     * Determine the provider name for a prospect (for logging/storage).
     */
    public function getProviderName(Prospecto $prospecto): string
    {
        if ($this->shouldUseCertificada($prospecto) && $this->isCertificadaHealthy()) {
            return 'certificada';
        }

        return 'smtp';
    }

    /**
     * Check if a prospect should use Certificada based on source or lote.
     */
    private function shouldUseCertificada(Prospecto $prospecto): bool
    {
        return $this->isGrupoDeudaProspect($prospecto) || $this->isICProspect($prospecto);
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
     * Check if a prospect is from Grupo Deudas API sync.
     * 
     * Grupo Deudas prospects have metadata.source = 'grupo_deuda'
     * This includes: Contratos Nuevos, Cuotas por Vencer, Cuotas Vencidas, Clientes Ingreso
     */
    private function isGrupoDeudaProspect(Prospecto $prospecto): bool
    {
        $metadata = $prospecto->metadata;

        if (! is_array($metadata)) {
            return false;
        }

        return ($metadata['source'] ?? null) === 'grupo_deuda';
    }

    /**
     * Check if a prospect belongs to an IC lote.
     */
    private function isICProspect(Prospecto $prospecto): bool
    {
        // Load the relationship if not already loaded to avoid N+1
        if (! $prospecto->relationLoaded('importacion')) {
            $prospecto->load('importacion.lote');
        } elseif ($prospecto->importacion && ! $prospecto->importacion->relationLoaded('lote')) {
            $prospecto->importacion->load('lote');
        }

        $loteName = $prospecto->importacion?->lote?->nombre ?? '';

        return str_starts_with($loteName, 'IC_');
    }

    /**
     * Get the source identifier for a prospect (for logging).
     */
    private function getProspectoSource(Prospecto $prospecto): string
    {
        if ($this->isGrupoDeudaProspect($prospecto)) {
            $endpoint = $prospecto->metadata['endpoint'] ?? 'unknown';
            return "grupo_deuda:{$endpoint}";
        }

        if ($this->isICProspect($prospecto)) {
            return 'ic_lote';
        }

        return 'other';
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
