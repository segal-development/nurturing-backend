<?php

namespace App\Contracts;

use App\Models\Envio;

interface SmsServiceInterface
{
    /**
     * Send an SMS to a prospect.
     *
     * @return array{success: bool, message_id: string|null, error: string|null}
     */
    public function send(Envio $envio): array;
}
