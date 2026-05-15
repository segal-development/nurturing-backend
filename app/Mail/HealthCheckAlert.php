<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HealthCheckAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $issues,
        public array $snapshot
    ) {}

    public function envelope(): Envelope
    {
        $hasError = collect($this->issues)->contains('severity', 'error');
        $prefix = $hasError ? '🚨 ERROR' : '⚠️ WARNING';
        $count = count($this->issues);

        return new Envelope(
            subject: "$prefix - Nurturing health check ($count " . ($count === 1 ? 'issue' : 'issues') . ')'
        );
    }

    public function content(): Content
    {
        $lines = ['Health check de nurturing-backend detectó problemas:', ''];

        foreach ($this->issues as $i) {
            $emoji = $i['severity'] === 'error' ? '🔴' : '🟡';
            $lines[] = "$emoji [{$i['severity']}] {$i['msg']}";
        }

        $lines[] = '';
        $lines[] = '--- Snapshot ---';
        foreach ($this->snapshot as $k => $v) {
            $val = is_bool($v) ? ($v ? 'true' : 'false') : (is_numeric($v) ? number_format($v) : $v);
            $lines[] = "  $k: $val";
        }
        $lines[] = '';
        $lines[] = 'Timestamp: '.now()->toIso8601String();

        return new Content(text: 'emails.health-check-alert', with: ['body' => implode("\n", $lines)]);
    }
}
