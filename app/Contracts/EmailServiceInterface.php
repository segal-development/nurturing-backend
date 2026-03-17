<?php

namespace App\Contracts;

use App\Models\Prospecto;

interface EmailServiceInterface
{
    /**
     * Send an email to a prospect.
     *
     * @param  Prospecto  $prospecto  The recipient
     * @param  string  $asunto  Email subject
     * @param  string  $contenido  Email body (HTML or plain text)
     * @param  bool  $esHtml  Whether content is HTML
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function send(Prospecto $prospecto, string $asunto, string $contenido, bool $esHtml): array;
}
