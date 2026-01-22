<?php

namespace App\Jobs\Middleware;

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
     * Procesa el job con rate limiting.
     */
    public function handle(object $job, Closure $next): void
    {
        // Check circuit breaker first
        if ($this->isCircuitOpen()) {
            $this->handleCircuitOpen($job);
            return;
        }

        $perMinute = $this->config['per_minute'];
        $rateLimitKey = "envio-rate:{$this->channel}";

        // Use Laravel's RateLimiter which handles concurrency correctly
        $executed = RateLimiter::attempt(
            $rateLimitKey,
            $perMinute,
            function () use ($job, $next) {
                // Process the job
                try {
                    $next($job);
                    $this->recordSuccess();
                } catch (\Throwable $e) {
                    $this->recordFailure();
                    throw $e;
                }
            },
            60 // decay seconds (1 minute window)
        );

        if (!$executed) {
            $this->handleRateLimited($job);
        }
    }

    /**
     * Maneja cuando el job está rate limited.
     */
    private function handleRateLimited(object $job): void
    {
        // Get available time until next slot
        $rateLimitKey = "envio-rate:{$this->channel}";
        $availableIn = RateLimiter::availableIn($rateLimitKey);
        
        // Use a small random delay to prevent thundering herd
        $delay = max(1, $availableIn) + rand(0, 2);

        if (config('envios.monitoring.log_rate_limits', true)) {
            Log::debug("RateLimitedMiddleware: Job rate limited", [
                'channel' => $this->channel,
                'job_class' => get_class($job),
                'delay_seconds' => $delay,
                'available_in' => $availableIn,
            ]);
        }

        // Release the job back to the queue with delay
        $job->release($delay);
    }

    /**
     * Verifica si el circuit breaker está abierto.
     */
    private function isCircuitOpen(): bool
    {
        $circuitKey = "envio-circuit:{$this->channel}";
        return Cache::get($circuitKey) === 'open';
    }

    /**
     * Maneja cuando el circuit breaker está abierto.
     */
    private function handleCircuitOpen(object $job): void
    {
        $recoveryTime = config('envios.circuit_breaker.recovery_time', 60);

        if (config('envios.monitoring.log_circuit_breaker', true)) {
            Log::warning("RateLimitedMiddleware: Circuit breaker open, releasing job", [
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
     */
    private function openCircuit(): void
    {
        $circuitKey = "envio-circuit:{$this->channel}";
        $recoveryTime = config('envios.circuit_breaker.recovery_time', 60);
        $threshold = config('envios.circuit_breaker.failure_threshold', 10);
        $failureKey = "envio-failures:{$this->channel}";
        $failures = (int) Cache::get($failureKey, 0);

        // Store opened_at for monitoring
        Cache::put("circuit_breaker:{$this->channel}:opened_at", now()->toIso8601String(), $recoveryTime);
        Cache::put("circuit_breaker:{$this->channel}:failures", $failures, $recoveryTime);
        Cache::put($circuitKey, 'open', $recoveryTime);

        if (config('envios.monitoring.log_circuit_breaker', true)) {
            Log::error("RateLimitedMiddleware: Circuit breaker OPENED", [
                'channel' => $this->channel,
                'failures' => $failures,
                'threshold' => $threshold,
                'recovery_time' => $recoveryTime,
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
     * Cierra el circuit breaker.
     */
    private function closeCircuit(): void
    {
        $circuitKey = "envio-circuit:{$this->channel}";

        if (Cache::get($circuitKey) === 'open') {
            Cache::forget($circuitKey);

            if (config('envios.monitoring.log_circuit_breaker', true)) {
                Log::info("RateLimitedMiddleware: Circuit breaker CLOSED", [
                    'channel' => $this->channel,
                ]);
            }
        }
    }
}
