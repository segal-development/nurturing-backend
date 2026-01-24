<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de protección de la API cuando la BD está saturada.
 * 
 * Estrategia:
 * 1. Verifica si hay un circuit breaker abierto (cache)
 * 2. Si no, hace un health check rápido a la BD
 * 3. Si falla, abre el circuit breaker y retorna 503
 * 4. Si pasa, continúa con el request
 * 
 * Esto previene que la API se quede colgada esperando una BD saturada,
 * fallando rápido con un error claro en vez de timeout.
 */
class DatabaseHealthMiddleware
{
    /**
     * Duración del circuit breaker abierto (segundos).
     */
    private const CIRCUIT_OPEN_DURATION = 30;

    /**
     * Timeout para el health check (milisegundos).
     */
    private const HEALTH_CHECK_TIMEOUT_MS = 2000;

    /**
     * Número de fallos consecutivos antes de abrir el circuit.
     */
    private const FAILURE_THRESHOLD = 3;

    /**
     * Rutas que deben funcionar incluso con BD caída (no requieren BD).
     */
    private const BYPASS_ROUTES = [
        'up',
        'api/health',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Bypass para rutas de health check
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        // Verificar si el circuit breaker está abierto
        if ($this->isCircuitOpen()) {
            return $this->serviceUnavailableResponse('Base de datos temporalmente no disponible. Reintente en 30 segundos.');
        }

        // Hacer health check rápido
        if (!$this->isDatabaseHealthy()) {
            $this->recordFailure();
            
            // Si superamos el threshold, abrir el circuit
            if ($this->shouldOpenCircuit()) {
                $this->openCircuit();
                return $this->serviceUnavailableResponse('Base de datos saturada. Reintente en 30 segundos.');
            }
            
            // Aún no abrimos el circuit, pero advertimos
            return $this->serviceUnavailableResponse('Base de datos lenta. Reintente en unos segundos.');
        }

        // BD saludable, resetear contador de fallos
        $this->resetFailures();

        return $next($request);
    }

    /**
     * Verifica si la ruta debe omitir el health check.
     */
    private function shouldBypass(Request $request): bool
    {
        $path = $request->path();
        
        foreach (self::BYPASS_ROUTES as $route) {
            if (str_starts_with($path, $route)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Verifica si el circuit breaker está abierto.
     */
    private function isCircuitOpen(): bool
    {
        return Cache::get('db_circuit_breaker_open', false);
    }

    /**
     * Abre el circuit breaker.
     */
    private function openCircuit(): void
    {
        Cache::put('db_circuit_breaker_open', true, self::CIRCUIT_OPEN_DURATION);
        
        Log::critical('DatabaseHealthMiddleware: Circuit breaker ABIERTO - BD no disponible', [
            'duration' => self::CIRCUIT_OPEN_DURATION,
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Verifica si la BD está saludable con un query rápido.
     */
    private function isDatabaseHealthy(): bool
    {
        try {
            $startTime = microtime(true);
            
            // Query simple y rápido - solo verifica conexión
            DB::select('SELECT 1');
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            // Si tarda más de 2 segundos, consideramos que está lenta
            if ($duration > self::HEALTH_CHECK_TIMEOUT_MS) {
                Log::warning('DatabaseHealthMiddleware: BD lenta', [
                    'duration_ms' => round($duration, 2),
                    'threshold_ms' => self::HEALTH_CHECK_TIMEOUT_MS,
                ]);
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('DatabaseHealthMiddleware: BD no disponible', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Registra un fallo de conexión.
     */
    private function recordFailure(): void
    {
        $failures = Cache::get('db_health_failures', 0);
        Cache::put('db_health_failures', $failures + 1, 60); // TTL 60 segundos
    }

    /**
     * Verifica si debemos abrir el circuit breaker.
     */
    private function shouldOpenCircuit(): bool
    {
        return Cache::get('db_health_failures', 0) >= self::FAILURE_THRESHOLD;
    }

    /**
     * Resetea el contador de fallos.
     */
    private function resetFailures(): void
    {
        Cache::forget('db_health_failures');
    }

    /**
     * Retorna respuesta 503 Service Unavailable.
     */
    private function serviceUnavailableResponse(string $message): Response
    {
        return response()->json([
            'error' => 'service_unavailable',
            'message' => $message,
            'retry_after' => self::CIRCUIT_OPEN_DURATION,
        ], 503, [
            'Retry-After' => self::CIRCUIT_OPEN_DURATION,
        ]);
    }
}
