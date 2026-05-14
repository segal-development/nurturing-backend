<?php

namespace App\Services\Email;

use App\Contracts\EmailServiceInterface;
use App\Models\Prospecto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email service for sending emails via Laravel's SMTP (Athena).
 * 
 * When configured with Athena SMTP credentials, this is the primary
 * email provider. Falls back to Certificada if unhealthy.
 */
class SmtpEmailService implements EmailServiceInterface
{
    /**
     * Send an email via Laravel's SMTP mail facade (Athena).
     *
     * @param  Prospecto  $prospecto  The recipient
     * @param  string  $asunto  Email subject
     * @param  string  $contenido  Email body (HTML or plain text)
     * @param  bool  $esHtml  Whether content is HTML
     * @param  string|null  $senderEmail  Custom sender email (falls back to config if null)
     * @param  string|null  $senderName  Custom sender name (falls back to config if null)
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function send(
        Prospecto $prospecto,
        string $asunto,
        string $contenido,
        bool $esHtml,
        ?string $senderEmail = null,
        ?string $senderName = null
    ): array {
        try {
            // Use custom sender if provided, else fall back to config
            $effectiveSenderEmail = $senderEmail ?? config('mail.from.address');
            $effectiveSenderName = $senderName ?? config('mail.from.name');

            if ($esHtml) {
                Mail::html($contenido, function ($message) use ($prospecto, $asunto, $effectiveSenderEmail, $effectiveSenderName) {
                    $message->from($effectiveSenderEmail, $effectiveSenderName)
                        ->to($prospecto->email, $prospecto->nombre)
                        ->subject($asunto);
                });
            } else {
                Mail::raw($contenido, function ($message) use ($prospecto, $asunto, $effectiveSenderEmail, $effectiveSenderName) {
                    $message->from($effectiveSenderEmail, $effectiveSenderName)
                        ->to($prospecto->email, $prospecto->nombre)
                        ->subject($asunto);
                });
            }

            return [
                'success' => true,
                'message_id' => null, // SMTP doesn't return message ID easily
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('SmtpEmailService: Failed to send email', [
                'prospecto_id' => $prospecto->id,
                'email' => $prospecto->email,
                'error' => $e->getMessage(),
            ]);

            // Mark Athena as unhealthy if it's a service-level error
            if ($this->isServiceError($e)) {
                EmailProviderResolver::markAthenaUnhealthy('SMTP error: ' . $e->getMessage());
            }

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Determine if an exception indicates a service-level error (vs individual email error).
     * 
     * Service errors should trigger fallback to Certificada.
     * Individual errors (invalid email, etc.) should not.
     */
    private function isServiceError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        // Connection/network errors that indicate SMTP service is down
        $serviceErrorPatterns = [
            'connection',
            'timeout',
            'curl',
            'ssl',
            'certificate',
            'dns',
            'resolve',
            'refused',
            'reset',
            'broken pipe',
            'host',
            'authentication failed',
            'smtp',
            '500',
            '502',
            '503',
            '504',
        ];

        foreach ($serviceErrorPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
