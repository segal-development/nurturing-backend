<?php

namespace App\Listeners;

use App\Events\CircuitBreakerOpened;
use App\Services\AlertasService;
use Illuminate\Support\Facades\Log;

/**
 * Listener que notifica cuando el circuit breaker se abre.
 * 
 * Usa el AlertasService para enviar:
 * - SMS a los números configurados (alerta crítica)
 * - Email a los destinatarios configurados
 */
class NotifyCircuitBreakerOpened
{
    public function __construct(
        private AlertasService $alertasService
    ) {}

    public function handle(CircuitBreakerOpened $event): void
    {
        Log::critical('[CircuitBreaker] Circuit breaker abierto', [
            'channel' => $event->channel,
            'failures' => $event->failureCount,
            'threshold' => $event->threshold,
            'recovery_seconds' => $event->recoveryTimeSeconds,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Usar el nuevo servicio de alertas (SMS + Email)
        $this->alertasService->alertaCircuitBreakerAbierto(
            $event->channel,
            $event->failureCount
        );
    }
}
