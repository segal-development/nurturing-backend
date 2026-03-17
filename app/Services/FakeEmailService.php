<?php

namespace App\Services;

use App\Contracts\EmailServiceInterface;
use App\Models\Prospecto;
use Illuminate\Support\Str;

class FakeEmailService implements EmailServiceInterface
{
    /**
     * Simulate sending an email (for development/testing).
     *
     * @param  Prospecto  $prospecto  The recipient
     * @param  string  $asunto  Email subject
     * @param  string  $contenido  Email body (HTML or plain text)
     * @param  bool  $esHtml  Whether content is HTML
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function send(Prospecto $prospecto, string $asunto, string $contenido, bool $esHtml): array
    {
        // Simulate a small delay
        usleep(50000); // 50ms

        // Simulate 95% success rate
        $success = rand(1, 100) <= 95;

        if ($success) {
            return [
                'success' => true,
                'message_id' => 'fake-email-'.Str::uuid(),
                'error' => null,
            ];
        }

        return [
            'success' => false,
            'message_id' => null,
            'error' => 'Simulated email delivery failure',
        ];
    }
}
