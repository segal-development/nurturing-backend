<?php

namespace App\Http\Controllers;

use App\Models\FlujoEjecucionEtapa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Controller para endpoints de monitoreo del sistema.
 *
 * Proporciona información sobre:
 * - Estado de circuit breakers (email/sms)
 * - Etapas pausadas automáticamente
 * - Salud general del sistema de envíos
 */
class MonitoringController extends Controller
{
    /**
     * Obtiene el estado de los circuit breakers y etapas pausadas.
     *
     * GET /api/monitoring/circuit-breakers
     */
    public function circuitBreakers(): JsonResponse
    {
        $channels = ['email', 'sms'];
        $status = [];

        foreach ($channels as $channel) {
            $circuitKey = "envio-circuit:{$channel}";
            $failureKey = "envio-failures:{$channel}";
            $isOpen = Cache::get($circuitKey) === 'open';

            $status[$channel] = [
                'is_open' => $isOpen,
                'status' => $isOpen ? 'open' : 'closed',
                'failures' => (int) Cache::get($failureKey, 0),
                'threshold' => config('envios.circuit_breaker.failure_threshold', 50),
                'opened_at' => Cache::get("circuit_breaker:{$channel}:opened_at"),
                'recovery_time_seconds' => config('envios.circuit_breaker.recovery_time', 60),
            ];
        }

        // Obtener etapas pausadas
        $etapasPausadas = FlujoEjecucionEtapa::where('estado', 'paused')
            ->whereNotNull('pause_reason')
            ->with(['ejecucion:id,flujo_id', 'ejecucion.flujo:id,nombre'])
            ->get()
            ->map(function ($etapa) {
                return [
                    'etapa_id' => $etapa->id,
                    'flujo_ejecucion_id' => $etapa->flujo_ejecucion_id,
                    'flujo_nombre' => $etapa->ejecucion?->flujo?->nombre,
                    'node_id' => $etapa->node_id,
                    'pause_reason' => $etapa->pause_reason,
                    'paused_at' => $etapa->paused_at?->toIso8601String(),
                    'auto_resume_at' => $etapa->auto_resume_at?->toIso8601String(),
                    'tiempo_restante_segundos' => $etapa->auto_resume_at
                        ? max(0, $etapa->auto_resume_at->diffInSeconds(now(), false) * -1)
                        : null,
                ];
            });

        return response()->json([
            'circuit_breakers' => $status,
            'etapas_pausadas' => $etapasPausadas,
            'total_pausadas' => $etapasPausadas->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Obtiene estadísticas generales del sistema de envíos.
     *
     * GET /api/monitoring/envios-stats
     */
    public function enviosStats(): JsonResponse
    {
        $stats = [
            'email' => $this->getChannelStats('email'),
            'sms' => $this->getChannelStats('sms'),
        ];

        return response()->json([
            'stats' => $stats,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Obtiene estadísticas de un canal específico.
     */
    private function getChannelStats(string $channel): array
    {
        $rateLimitKey = "envio-rate:{$channel}";

        return [
            'rate_limit' => [
                'per_minute' => config("envios.rate_limits.{$channel}.per_minute"),
                'per_hour' => config("envios.rate_limits.{$channel}.per_hour"),
            ],
            'circuit_breaker' => [
                'is_open' => Cache::get("envio-circuit:{$channel}") === 'open',
                'failures' => (int) Cache::get("envio-failures:{$channel}", 0),
                'threshold' => config('envios.circuit_breaker.failure_threshold'),
            ],
        ];
    }

    /**
     * Fuerza el cierre de un circuit breaker (para debugging/emergencias).
     *
     * POST /api/monitoring/circuit-breakers/{channel}/close
     */
    public function forceCloseCircuitBreaker(string $channel): JsonResponse
    {
        if (! in_array($channel, ['email', 'sms'])) {
            return response()->json(['error' => 'Canal inválido'], 400);
        }

        $circuitKey = "envio-circuit:{$channel}";
        $failureKey = "envio-failures:{$channel}";

        Cache::forget($circuitKey);
        Cache::forget($failureKey);

        // Disparar evento para reanudar etapas
        \App\Events\CircuitBreakerClosed::dispatch($channel, 'manual_close');

        return response()->json([
            'message' => "Circuit breaker de {$channel} cerrado manualmente",
            'channel' => $channel,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Reanuda manualmente una etapa pausada.
     *
     * POST /api/monitoring/etapas/{id}/resume
     */
    public function resumeEtapa(int $id): JsonResponse
    {
        $etapa = FlujoEjecucionEtapa::find($id);

        if (! $etapa) {
            return response()->json(['error' => 'Etapa no encontrada'], 404);
        }

        if ($etapa->estado !== 'paused') {
            return response()->json(['error' => 'La etapa no está pausada'], 400);
        }

        $etapa->reanudar();

        return response()->json([
            'message' => 'Etapa reanudada manualmente',
            'etapa_id' => $id,
            'nuevo_estado' => $etapa->fresh()->estado,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
