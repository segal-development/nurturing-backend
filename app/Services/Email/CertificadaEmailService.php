<?php

namespace App\Services\Email;

use App\Contracts\EmailServiceInterface;
use App\Models\Prospecto;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Email service for sending certified/transactional emails via Certificada API.
 *
 * This service is used for IC (Informes Comerciales) prospects who require
 * certified email delivery. The EmailProviderResolver determines when to
 * use this service based on the prospect's lote prefix (IC_*).
 *
 * Configuration in config/services.php:
 * - certificada.api_key: API key for authentication
 * - certificada.base_url: API endpoint URL
 * - certificada.sender_email: From email address
 * - certificada.sender_name: From display name
 * - certificada.enabled: Toggle service on/off
 * - certificada.timeout: HTTP request timeout in seconds
 *
 * @see \App\Services\Email\EmailProviderResolver
 * @see https://sistema.certificada.cl/api
 */
class CertificadaEmailService implements EmailServiceInterface
{
    private string $apiKey;

    private string $baseUrl;

    private string $senderEmail;

    private string $senderName;

    private int $timeout;

    private bool $enabled;

    public function __construct()
    {
        $this->apiKey = config('services.certificada.api_key') ?? '';
        $this->baseUrl = config('services.certificada.base_url') ?? 'https://sistema.certificada.cl/api';
        $this->senderEmail = config('services.certificada.sender_email') ?? 'info@informescomercialesb2b.cl';
        $this->senderName = config('services.certificada.sender_name') ?? 'Informes Comerciales';
        $this->timeout = (int) config('services.certificada.timeout', 30);
        $this->enabled = (bool) config('services.certificada.enabled', true);
    }

    /**
     * Send an email via Certificada API.
     *
     * @param  Prospecto  $prospecto  The recipient
     * @param  string  $asunto  Email subject
     * @param  string  $contenido  Email body (HTML or plain text)
     * @param  bool  $esHtml  Whether content is HTML
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function send(Prospecto $prospecto, string $asunto, string $contenido, bool $esHtml): array
    {
        if (! $this->enabled) {
            Log::warning('CertificadaEmailService: Service is disabled', [
                'prospecto_id' => $prospecto->id,
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Certificada service is disabled',
            ];
        }

        if (empty($this->apiKey)) {
            Log::error('CertificadaEmailService: API key not configured');

            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Certificada API key not configured',
            ];
        }

        try {
            $payload = $this->buildPayload($prospecto, $asunto, $contenido, $esHtml);

            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/transaccional/enviar_html", $payload);

            $data = $response->json();

            // Certificada returns Estado: "1" for success
            if ($response->successful() && ($data['Estado'] ?? '0') === '1') {
                Log::info('CertificadaEmailService: Email sent successfully', [
                    'prospecto_id' => $prospecto->id,
                    'email' => $prospecto->email,
                    'message_id' => $data['IdMensaje'] ?? null,
                ]);

                return [
                    'success' => true,
                    'message_id' => $data['IdMensaje'] ?? null,
                    'error' => null,
                ];
            }

            // API returned error
            $errorMessage = $data['Mensaje'] ?? 'Unknown Certificada API error';
            Log::error('CertificadaEmailService: API returned error', [
                'prospecto_id' => $prospecto->id,
                'email' => $prospecto->email,
                'response' => $data,
                'http_status' => $response->status(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $errorMessage,
            ];
        } catch (ConnectionException $e) {
            Log::error('CertificadaEmailService: Connection timeout', [
                'prospecto_id' => $prospecto->id,
                'email' => $prospecto->email,
                'error' => $e->getMessage(),
            ]);

            // Mark service as unhealthy so resolver falls back to SMTP
            EmailProviderResolver::markCertificadaUnhealthy('Connection timeout: ' . $e->getMessage());

            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Connection timeout to Certificada API',
            ];
        } catch (\Exception $e) {
            Log::error('CertificadaEmailService: Unexpected error', [
                'prospecto_id' => $prospecto->id,
                'email' => $prospecto->email,
                'error' => $e->getMessage(),
            ]);

            // Only mark unhealthy for connection/server errors, not for individual email failures
            if ($this->isServiceError($e)) {
                EmailProviderResolver::markCertificadaUnhealthy('Service error: ' . $e->getMessage());
            }

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build the API payload for Certificada.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Prospecto $prospecto, string $asunto, string $contenido, bool $esHtml): array
    {
        // Certificada requires HTML to be base64 encoded
        $htmlContent = $esHtml ? $contenido : nl2br(e($contenido));
        $textoPlano = $esHtml ? strip_tags($contenido) : $contenido;

        return [
            'IdApi' => $this->apiKey,
            'From' => [
                'Email' => $this->senderEmail,
                'Nombre' => $this->senderName,
            ],
            'To' => [
                'Email' => $prospecto->email,
                'Nombre' => $prospecto->nombre ?? $prospecto->email,
            ],
            'Despacho' => [
                'Html' => base64_encode($htmlContent),
                'Texto' => $textoPlano,
                'Asunto' => $asunto,
            ],
        ];
    }

    /**
     * Check if the service is properly configured and enabled.
     */
    public function isAvailable(): bool
    {
        return $this->enabled && ! empty($this->apiKey);
    }

    /**
     * Determine if an exception indicates a service-level error (vs individual email error).
     * 
     * Service errors should trigger fallback to SMTP.
     * Individual errors (invalid email, etc.) should not.
     */
    private function isServiceError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        // Connection/network errors
        $serviceErrorPatterns = [
            'connection',
            'timeout',
            'curl',
            'ssl',
            'certificate',
            'dns',
            'resolve',
            '500',
            '502',
            '503',
            '504',
            'service unavailable',
            'internal server error',
            'bad gateway',
        ];

        foreach ($serviceErrorPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
