<?php

namespace App\Services\Email;

use App\Contracts\EmailServiceInterface;
use App\Models\Prospecto;
use Illuminate\Support\Facades\Log;

/**
 * Resolves which email service to use based on prospect characteristics.
 *
 * Implements the Strategy Pattern to route emails dynamically:
 * - IC prospects (lote name starts with "IC_") use CertificadaEmailService
 * - All other prospects use SmtpEmailService
 * - Falls back to SMTP if Certificada is unavailable (disabled or not configured)
 *
 * The resolver is injected into EnvioService and called for each email send
 * to determine the appropriate provider.
 *
 * Detection logic:
 * 1. Load prospect's importacion.lote relationship
 * 2. Check if lote.nombre starts with "IC_" (case-sensitive)
 * 3. Verify Certificada service is available
 * 4. Return appropriate service or fall back to SMTP
 *
 * @see \App\Services\EnvioService::enviarEmailAProspecto()
 */
class EmailProviderResolver
{
    public function __construct(
        private SmtpEmailService $smtpService,
        private CertificadaEmailService $certificadaService,
    ) {}

    /**
     * Resolve the appropriate email service for a prospect.
     * IC prospects (lotes starting with IC_) use Certificada.
     * All others use SMTP.
     */
    public function resolve(Prospecto $prospecto): EmailServiceInterface
    {
        if ($this->isICProspect($prospecto)) {
            // Only use Certificada if it's available (configured and enabled)
            if ($this->certificadaService->isAvailable()) {
                return $this->certificadaService;
            }
            // Fallback to SMTP if Certificada is not available
            Log::warning('EmailProviderResolver: Certificada not available for IC prospect, falling back to SMTP', [
                'prospecto_id' => $prospecto->id,
            ]);
        }

        return $this->smtpService;
    }

    /**
     * Determine the provider name for a prospect (for logging/storage).
     */
    public function getProviderName(Prospecto $prospecto): string
    {
        if ($this->isICProspect($prospecto) && $this->certificadaService->isAvailable()) {
            return 'certificada';
        }

        return 'smtp';
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
}
