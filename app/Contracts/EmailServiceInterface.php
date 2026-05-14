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
    ): array;
}
