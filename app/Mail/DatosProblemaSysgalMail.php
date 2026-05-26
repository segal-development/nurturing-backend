<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a SYSGAL: clientes que entraron al flujo pero tienen el dato de contacto mal
 * (email inválido / sin email) y hay que corregirlo en el origen (SYSGAL).
 *
 * El destinatario (to) y la copia (cc) los pone el comando vía Mail::to()->cc().
 */
class DatosProblemaSysgalMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public array $items,
        public int $cantidad,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Emails a corregir en SYSGAL — {$this->cantidad} cliente(s) con email mal escrito",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.datos-problema-sysgal',
            with: [
                'items' => $this->items,
                'cantidad' => $this->cantidad,
            ],
        );
    }
}
