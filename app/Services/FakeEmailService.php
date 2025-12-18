<?php

namespace App\Services;

use App\Contracts\EmailServiceInterface;
use App\Models\Envio;
use Illuminate\Support\Str;

class FakeEmailService implements EmailServiceInterface
{
    /**
     * Simulate sending an email (for development/testing).
     *
     * @return array{success: bool, message_id: string|null, error: string|null}
     */
    public function send(Envio $envio): array
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
