<?php

namespace App\Services;

use App\Contracts\SmsServiceInterface;
use App\Models\Envio;
use Illuminate\Support\Str;

class FakeSmsService implements SmsServiceInterface
{
    /**
     * Simulate sending an SMS (for development/testing).
     *
     * @return array{success: bool, message_id: string|null, error: string|null}
     */
    public function send(Envio $envio): array
    {
        // Simulate a small delay
        usleep(100000); // 100ms (SMS typically slower)

        // Simulate 90% success rate
        $success = rand(1, 100) <= 90;

        if ($success) {
            return [
                'success' => true,
                'message_id' => 'fake-sms-'.Str::uuid(),
                'error' => null,
            ];
        }

        return [
            'success' => false,
            'message_id' => null,
            'error' => 'Simulated SMS delivery failure',
        ];
    }
}
