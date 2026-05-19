<?php

namespace App\Jobs\Middleware;

use App\Events\CircuitBreakerClosed;
use App\Events\CircuitBreakerOpened;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Middleware de Rate Limiting para Jobs de envío.
 *
 * Usa el RateLimiter nativo de Laravel que maneja correctamente:
 * - Múltiples workers concurrentes
 * - Expiración automática de contadores
 * - Atomicidad en operaciones de incremento
 *
 * Uso:
 * ```php
 * public function middleware(): array
 * {
 *     return [new RateLimitedMiddleware('email')];
 * }
 * ```
 */
class RateLimitedMiddleware
{
    /**
     * Tipo de canal: 'email' o 'sms'
     */
    private string $channel;

    /**
     * Configuración de rate limits
     */
    private array $config;

    public function __construct(string $channel = 'email')
    {
        $this->channel = $channel;
        $this->config = config("envios.rate_limits.{$channel}", [
            'per_second' => 10,
            'per_minute' => 500,
            'backoff_seconds' => 5,
            'max_retries' => 3,
        ]);
    }

    /**
     * Procesa el job con rate limiting + circuit breaker (CLOSED / OPEN / HALF-OPEN).
     */
    public function handle(object $job, Closure $next): void
    {
        $state = $this->getCircuitState();

        if ($state === 'open') {
            $this->handleCircuitOpen($job);

            return;
        }

        // HALF-OPEN: solo dejamos pasar un número limitado de "probes" para
        // testear si el proveedor se recuperó. Si la slot está tomada por otro
        // probe en vuelo, este job se reposta con un delay corto.
        $isProbe = ($state === 'half-open');
        if ($isProbe && ! $this->reserveProbeSlot()) {
            $job->release(5);

            return;
        }

        $perMinute = $this->config['per_minute'];
        $rateLimitKey = "envio-rate:{$this->channel}";

        // Use Laravel's RateLimiter which handles concurrency correctly
        $executed = RateLimiter::attempt(
            $rateLimitKey,
            $perMinute,
            function () use ($job, $next, $isProbe) {
                // Process the job
                try {
                    $next($job);
                    if ($isProbe) {
                        $this->releaseProbeSlot();
                        $this->recordProbeSuccess();
                    } else {
                        $this->recordSuccess();
                    }
                } catch (\Throwable $e) {
                    if ($isProbe) {
                        $this->releaseProbeSlot();
                    }
                    // Only count as circuit breaker failure if it's a REAL provider error
                    // Not validation errors like "prospecto sin email"
                    if ($this->isProviderError($e)) {
                        if ($isProbe) {
                            $this->recordProbeFailure();
                        } else {
                            $this->recordFailure();
                        }
                    }
                    throw $e;
                }
            },
            60 // decay seconds (1 minute window)
        );

        if (! $executed) {
            // Rate limited during half-open: liberamos la probe slot
            if ($isProbe) {
                $this->releaseProbeSlot();
            }
            $this->handleRateLimited($job);
        }
    }

    /**
     * Determina si un error es del proveedor (SMTP/API) o es de validación.
     * Solo los errores de proveedor deben activar el circuit breaker.
     */
    private function isProviderError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        // Errores de validación/configuración que NO deben contar para circuit breaker
        // Estos errores requieren intervención manual, no reintentos
        $validationErrors = [
            'no tiene email',
            'email válido',
            'email invalido',
            'prospecto no encontrado',
            'prospecto inactivo',
            'desuscrito',
            'unsubscribed',
            // Errores de configuración (requieren fix manual, no reintentos)
            'no configurado',
            'config:cache',
            'api_token',
            'sms_api_token',
        ];

        foreach ($validationErrors as $validationError) {
            if (str_contains($message, $validationError)) {
                return false;
            }
        }

        // Errores de proveedor que SÍ deben contar
        $providerErrors = [
            'connection',
            'timeout',
            'smtp',
            'mail server',
            'relay',
            'quota',
            'rate limit',
            'too many',
            // SMTP error codes
            '421',
            '450',
            '451',
            '452',
            '500',
            '550',
            '551',
            '552',
            '553',
            '554',
            // HTTP error codes (SMS API)
            'http 400',
            'http 401',
            'http 403',
            'http 429',
            'http 500',
            'http 502',
            'http 503',
            'http 504',
        ];

        foreach ($providerErrors as $providerError) {
            if (str_contains($message, $providerError)) {
                return true;
            }
        }

        // Por defecto, no contar como error de proveedor
        // Esto es conservador - preferimos no abrir el circuit breaker
        return false;
    }

    /**
     * Maneja cuando el job está rate limited.
     */
    private function handleRateLimited(object $job): void
    {
        $attempts = method_exists($job, 'attempts') ? $job->attempts() : 0;

        // Safety valve: if job has been released too many times, let it through
        // This prevents infinite loops when rate limit is misconfigured
        if ($attempts > 50) {
            Log::warning('RateLimitedMiddleware: Job exceeded 50 attempts, letting through', [
                'channel' => $this->channel,
                'job_class' => get_class($job),
                'attempts' => $attempts,
            ]);

            return; // Let the job proceed without rate limiting
        }

        // Get available time until next slot
        $rateLimitKey = "envio-rate:{$this->channel}";
        $availableIn = RateLimiter::availableIn($rateLimitKey);

        // Use a small random delay to prevent thundering herd
        $delay = max(1, $availableIn) + rand(0, 2);

        if (config('envios.monitoring.log_rate_limits', true) && $attempts < 5) {
            // Only log first few attempts to avoid log spam
            Log::debug('RateLimitedMiddleware: Job rate limited', [
                'channel' => $this->channel,
                'job_class' => get_class($job),
                'delay_seconds' => $delay,
                'available_in' => $availableIn,
                'attempts' => $attempts,
            ]);
        }

        // Release the job back to the queue with delay
        $job->release($delay);
    }

    /**
     * Resuelve el estado actual del circuit breaker.
     * Estados: 'closed' | 'open' | 'half-open'
     *
     * Transición automática OPEN → HALF-OPEN cuando `recovery_time` venció.
     * El resto de transiciones (CLOSED → OPEN, HALF-OPEN → CLOSED, HALF-OPEN → OPEN)
     * son disparadas por eventos en recordSuccess/recordFailure y los métodos de probe.
     */
    private function getCircuitState(): string
    {
        $circuitKey = "envio-circuit:{$this->channel}";
        $state = Cache::get($circuitKey);

        if (! $state) {
            return 'closed';
        }

        if ($state === 'half-open') {
            return 'half-open';
        }

        // state === 'open' — verificar si ya pasó el recovery_time
        $openedAtRaw = Cache::get("circuit_breaker:{$this->channel}:opened_at");
        if ($openedAtRaw) {
            try {
                $openedAt = \Carbon\Carbon::parse($openedAtRaw);
                $recoveryTime = (int) config('envios.circuit_breaker.recovery_time', 60);
                if ($openedAt->copy()->addSeconds($recoveryTime)->isPast()) {
                    $this->transitionToHalfOpen();

                    return 'half-open';
                }
            } catch (\Throwable $e) {
                // Si el timestamp es inválido, asumimos open y dejamos que el TTL natural lo expire
            }
        }

        return 'open';
    }

    /**
     * Transición OPEN → HALF-OPEN. Permite un número limitado de probes
     * para testear si el proveedor se recuperó.
     */
    private function transitionToHalfOpen(): void
    {
        $circuitKey = "envio-circuit:{$this->channel}";
        $halfOpenWindow = (int) config('envios.circuit_breaker.half_open_window', 300);

        Cache::put($circuitKey, 'half-open', $halfOpenWindow);
        // Reset probe counters (defensive: por si quedaron de un ciclo previo)
        Cache::forget("circuit_breaker:{$this->channel}:probes-in-flight");
        Cache::put("circuit_breaker:{$this->channel}:probe-successes", 0, $halfOpenWindow);

        if (config('envios.monitoring.log_circuit_breaker', true)) {
            Log::info('RateLimitedMiddleware: Circuit breaker HALF-OPEN (testing recovery)', [
                'channel' => $this->channel,
                'half_open_window' => $halfOpenWindow,
                'max_probes' => (int) config('envios.circuit_breaker.half_open_max_probes', 1),
                'success_threshold' => (int) config('envios.circuit_breaker.half_open_success_threshold', 3),
            ]);
        }
    }

    /**
     * Intenta reservar una slot de probe en estado HALF-OPEN.
     * Retorna true si lo logra, false si el cupo ya está tomado.
     *
     * NOTA: usamos increment + check para atomicidad multi-worker. Si dos workers
     * compiten, ambos incrementan; el que excede el max libera y se reposta.
     */
    private function reserveProbeSlot(): bool
    {
        $probesKey = "circuit_breaker:{$this->channel}:probes-in-flight";
        $maxProbes = (int) config('envios.circuit_breaker.half_open_max_probes', 1);
        $halfOpenWindow = (int) config('envios.circuit_breaker.half_open_window', 300);

        // Asegurar que la key existe con TTL para evitar leaks
        Cache::add($probesKey, 0, $halfOpenWindow);
        $newCount = Cache::increment($probesKey);

        if ($newCount > $maxProbes) {
            // Demasiados probes en vuelo — revertir y rechazar
            Cache::decrement($probesKey);

            return false;
        }

        return true;
    }

    /**
     * Libera una slot de probe.
     */
    private function releaseProbeSlot(): void
    {
        $probesKey = "circuit_breaker:{$this->channel}:probes-in-flight";
        $current = (int) Cache::get($probesKey, 0);
        if ($current > 0) {
            Cache::decrement($probesKey);
        }
    }

    /**
     * Registra un probe exitoso. Si se acumulan suficientes consecutivos,
     * el circuito vuelve a CLOSED.
     */
    private function recordProbeSuccess(): void
    {
        $successKey = "circuit_breaker:{$this->channel}:probe-successes";
        $halfOpenWindow = (int) config('envios.circuit_breaker.half_open_window', 300);
        $threshold = (int) config('envios.circuit_breaker.half_open_success_threshold', 3);

        Cache::add($successKey, 0, $halfOpenWindow);
        $successes = Cache::increment($successKey);

        if (config('envios.monitoring.log_circuit_breaker', true)) {
            Log::info('RateLimitedMiddleware: Probe SUCCESS in HALF-OPEN', [
                'channel' => $this->channel,
                'successes' => $successes,
                'threshold' => $threshold,
            ]);
        }

        if ($successes >= $threshold) {
            // Suficientes éxitos consecutivos — cerrar el breaker
            $this->closeCircuit();
            // Cleanup probe state
            Cache::forget($successKey);
            Cache::forget("circuit_breaker:{$this->channel}:probes-in-flight");
            // Reset el contador de fallas también
            Cache::forget("envio-failures:{$this->channel}");
        }
    }

    /**
     * Registra un probe fallido. Cualquier fallo en HALF-OPEN reabre el breaker.
     */
    private function recordProbeFailure(): void
    {
        if (config('envios.monitoring.log_circuit_breaker', true)) {
            Log::warning('RateLimitedMiddleware: Probe FAILED in HALF-OPEN, reopening', [
                'channel' => $this->channel,
            ]);
        }

        // Limpiar probe state antes de reabrir
        Cache::forget("circuit_breaker:{$this->channel}:probe-successes");
        Cache::forget("circuit_breaker:{$this->channel}:probes-in-flight");

        // Reabrir el breaker (otro ciclo de recovery)
        $this->openCircuit();
    }

    /**
     * Maneja cuando el circuit breaker está abierto.
     */
    private function handleCircuitOpen(object $job): void
    {
        $recoveryTime = config('envios.circuit_breaker.recovery_time', 60);

        if (config('envios.monitoring.log_circuit_breaker', true)) {
            Log::warning('RateLimitedMiddleware: Circuit breaker open, releasing job', [
                'channel' => $this->channel,
                'job_class' => get_class($job),
                'recovery_time' => $recoveryTime,
            ]);
        }

        // Release with delay equal to recovery time
        $job->release($recoveryTime);
    }

    /**
     * Registra un envío exitoso.
     */
    private function recordSuccess(): void
    {
        // Reset failure counter on success
        $failureKey = "envio-failures:{$this->channel}";
        $current = (int) Cache::get($failureKey, 0);

        if ($current > 0) {
            Cache::decrement($failureKey);
        }

        if (Cache::get($failureKey, 0) <= 0) {
            Cache::forget($failureKey);
            $this->closeCircuit();
        }
    }

    /**
     * Registra un envío fallido.
     */
    private function recordFailure(): void
    {
        $failureKey = "envio-failures:{$this->channel}";
        $failureWindow = config('envios.circuit_breaker.failure_window', 60);
        $threshold = config('envios.circuit_breaker.failure_threshold', 10);

        $current = (int) Cache::get($failureKey, 0);

        if ($current === 0) {
            Cache::put($failureKey, 1, $failureWindow);
        } else {
            Cache::increment($failureKey);
        }

        // Check if we need to open the circuit
        if (Cache::get($failureKey, 0) >= $threshold) {
            $this->openCircuit();
        }
    }

    /**
     * Abre el circuit breaker.
     *
     * Importante: el TTL del cache key debe ser MAYOR que `recovery_time` para que
     * `getCircuitState()` pueda detectar el momento de transición a HALF-OPEN.
     * Si el TTL fuese == recovery_time, la key expiraría exactamente cuando
     * deberíamos transicionar, y el estado pasaría directo a CLOSED sin probes.
     */
    private function openCircuit(): void
    {
        $circuitKey = "envio-circuit:{$this->channel}";
        $recoveryTime = (int) config('envios.circuit_breaker.recovery_time', 60);
        $halfOpenWindow = (int) config('envios.circuit_breaker.half_open_window', 300);
        $threshold = (int) config('envios.circuit_breaker.failure_threshold', 10);
        $failureKey = "envio-failures:{$this->channel}";
        $failures = (int) Cache::get($failureKey, 0);

        // TTL total = open + half-open + buffer. Si nadie dispara la transición,
        // eventualmente expira y vuelve a CLOSED naturalmente (fallback seguro).
        $totalTtl = $recoveryTime + $halfOpenWindow + 60;

        // Store opened_at for monitoring AND for the OPEN→HALF-OPEN transition check
        Cache::put("circuit_breaker:{$this->channel}:opened_at", now()->toIso8601String(), $totalTtl);
        Cache::put("circuit_breaker:{$this->channel}:failures", $failures, $totalTtl);
        Cache::put($circuitKey, 'open', $totalTtl);

        // Reset probe counters de cualquier ciclo previo
        Cache::forget("circuit_breaker:{$this->channel}:probes-in-flight");
        Cache::forget("circuit_breaker:{$this->channel}:probe-successes");

        if (config('envios.monitoring.log_circuit_breaker', true)) {
            Log::error('RateLimitedMiddleware: Circuit breaker OPENED', [
                'channel' => $this->channel,
                'failures' => $failures,
                'threshold' => $threshold,
                'recovery_time' => $recoveryTime,
                'half_open_window' => $halfOpenWindow,
            ]);
        }

        // Dispatch event for notifications
        CircuitBreakerOpened::dispatch(
            $this->channel,
            $failures,
            $threshold,
            $recoveryTime
        );
    }

    /**
     * Cierra el circuit breaker desde OPEN o HALF-OPEN.
     * Dispatcha CircuitBreakerClosed para que listeners (e.g., ResumeEtapasOnCircuitClose)
     * reanuden las etapas pausadas previamente por PauseEtapasOnCircuitBreaker.
     */
    private function closeCircuit(): void
    {
        $circuitKey = "envio-circuit:{$this->channel}";
        $current = Cache::get($circuitKey);

        if ($current === 'open' || $current === 'half-open') {
            Cache::forget($circuitKey);
            // Limpiar markers de open
            Cache::forget("circuit_breaker:{$this->channel}:opened_at");
            Cache::forget("circuit_breaker:{$this->channel}:failures");

            if (config('envios.monitoring.log_circuit_breaker', true)) {
                Log::info('RateLimitedMiddleware: Circuit breaker CLOSED', [
                    'channel' => $this->channel,
                    'from_state' => $current,
                ]);
            }

            // Dispatchar evento para que listeners reanuden etapas pausadas
            $closedReason = $current === 'half-open' ? 'half_open_probes_succeeded' : 'success_threshold_reached';
            CircuitBreakerClosed::dispatch($this->channel, $closedReason);
        }
    }
}
