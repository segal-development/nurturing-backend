<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResumenDiarioMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $titulo,
        public string $mensaje,
        public array $contexto = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "📊 {$this->titulo}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.resumen-diario',
            with: [
                'titulo' => $this->titulo,
                'mensaje' => $this->mensaje,
                'contexto' => $this->contexto,
                'timestamp' => now()->format('d/m/Y H:i:s'),
            ],
        );
    }
}
