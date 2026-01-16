<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento disparado cuando el circuit breaker se abre
 * 
 * Esto indica que hubo demasiados fallos consecutivos
 * y los envíos están temporalmente bloqueados
 */
class CircuitBreakerOpened
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $channel,
        public int $failureCount,
        public int $threshold,
        public int $recoveryTimeSeconds
    ) {}
}
