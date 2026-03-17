<?php

namespace App\Services\Email;

use App\Contracts\EmailServiceInterface;
use App\Models\Prospecto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SmtpEmailService implements EmailServiceInterface
{
    /**
     * Send an email via Laravel's SMTP mail facade.
     *
     * @param  Prospecto  $prospecto  The recipient
     * @param  string  $asunto  Email subject
     * @param  string  $contenido  Email body (HTML or plain text)
     * @param  bool  $esHtml  Whether content is HTML
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function send(Prospecto $prospecto, string $asunto, string $contenido, bool $esHtml): array
    {
        try {
            if ($esHtml) {
                Mail::html($contenido, function ($message) use ($prospecto, $asunto) {
                    $message->to($prospecto->email, $prospecto->nombre)->subject($asunto);
                });
            } else {
                Mail::raw($contenido, function ($message) use ($prospecto, $asunto) {
                    $message->to($prospecto->email, $prospecto->nombre)->subject($asunto);
                });
            }

            return [
                'success' => true,
                'message_id' => null, // SMTP doesn't return message ID
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('SmtpEmailService: Failed to send email', [
                'prospecto_id' => $prospecto->id,
                'email' => $prospecto->email,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
