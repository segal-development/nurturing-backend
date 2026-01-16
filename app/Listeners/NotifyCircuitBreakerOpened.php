<?php

namespace App\Listeners;

use App\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Listener que notifica cuando el circuit breaker se abre
 * 
 * Envía alertas a:
 * - Logs de la aplicación
 * - Slack webhook (si está configurado)
 * - Email (si está configurado)
 */
class NotifyCircuitBreakerOpened
{
    public function handle(CircuitBreakerOpened $event): void
    {
        $message = sprintf(
            '[ALERTA CRÍTICA] Circuit Breaker ABIERTO para %s. Fallos: %d/%d. Los envíos están bloqueados por %d segundos.',
            strtoupper($event->channel),
            $event->failureCount,
            $event->threshold,
            $event->recoveryTimeSeconds
        );

        // 1. Siempre logueamos
        Log::critical($message, [
            'channel' => $event->channel,
            'failures' => $event->failureCount,
            'threshold' => $event->threshold,
            'recovery_seconds' => $event->recoveryTimeSeconds,
            'timestamp' => now()->toIso8601String(),
        ]);

        // 2. Enviar a Slack si está configurado
        $this->notifySlack($event, $message);

        // 3. Enviar email si está configurado
        $this->notifyEmail($event, $message);
    }

    private function notifySlack(CircuitBreakerOpened $event, string $message): void
    {
        $webhookUrl = config('envios.alerts.slack_webhook');
        
        if (empty($webhookUrl)) {
            return;
        }

        try {
            Http::post($webhookUrl, [
                'text' => $message,
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => '🚨 Circuit Breaker Abierto',
                            'emoji' => true,
                        ],
                    ],
                    [
                        'type' => 'section',
                        'fields' => [
                            [
                                'type' => 'mrkdwn',
                                'text' => "*Canal:*\n" . strtoupper($event->channel),
                            ],
                            [
                                'type' => 'mrkdwn',
                                'text' => "*Fallos:*\n{$event->failureCount}/{$event->threshold}",
                            ],
                            [
                                'type' => 'mrkdwn',
                                'text' => "*Recuperación en:*\n{$event->recoveryTimeSeconds} segundos",
                            ],
                            [
                                'type' => 'mrkdwn',
                                'text' => "*Timestamp:*\n" . now()->toDateTimeString(),
                            ],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => '⚠️ *Los envíos de ' . $event->channel . ' están temporalmente bloqueados*',
                        ],
                    ],
                ],
            ]);

            Log::info('Alerta de circuit breaker enviada a Slack');
        } catch (\Exception $e) {
            Log::error('Error enviando alerta a Slack', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyEmail(CircuitBreakerOpened $event, string $message): void
    {
        $alertEmail = config('envios.alerts.email');
        
        if (empty($alertEmail)) {
            return;
        }

        // Usando el mail driver configurado
        try {
            \Illuminate\Support\Facades\Mail::raw($message, function ($mail) use ($alertEmail, $event) {
                $mail->to($alertEmail)
                    ->subject("[ALERTA] Circuit Breaker Abierto - {$event->channel}");
            });

            Log::info('Alerta de circuit breaker enviada por email', [
                'email' => $alertEmail,
            ]);
        } catch (\Exception $e) {
            Log::error('Error enviando alerta por email', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
