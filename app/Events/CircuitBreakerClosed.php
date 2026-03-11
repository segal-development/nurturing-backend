<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento disparado cuando el circuit breaker se cierra (API vuelve a funcionar).
 *
 * Esto indica que la API está respondiendo correctamente y los envíos
 * pueden reanudarse automáticamente.
 */
class CircuitBreakerClosed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $channel,
        public string $closedReason = 'health_check_passed'
    ) {}
}
