<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller para monitoreo del sistema de colas y envíos
 * 
 * Proporciona visibilidad sobre:
 * - Estado de la cola de jobs (pendientes, fallidos, procesados)
 * - Estado del circuit breaker
 * - Métricas de rate limiting
 * - Health check general del sistema
 */
class MonitoreoController extends Controller
{
    /**
     * Dashboard general de monitoreo
     * 
     * GET /api/monitoreo/dashboard
     */
    public function dashboard(): JsonResponse
    {
        $queueStats = $this->getQueueStats();
        $circuitStatus = $this->getCircuitBreakerStatus();
        $rateLimitStats = $this->getRateLimitStats();
        $envioStats = $this->getEnvioStats();

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'queue' => $queueStats,
                'circuit_breaker' => $circuitStatus,
                'rate_limiting' => $rateLimitStats,
                'envios_hoy' => $envioStats,
                'system_health' => $this->calculateSystemHealth($queueStats, $circuitStatus),
            ],
        ]);
    }

    /**
     * Estado detallado de la cola de jobs
     * 
     * GET /api/monitoreo/queue
     */
    public function queueStatus(): JsonResponse
    {
        $stats = $this->getQueueStats();
        $recentFailed = $this->getRecentFailedJobs(10);

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'stats' => $stats,
                'recent_failed_jobs' => $recentFailed,
                'recommendations' => $this->getQueueRecommendations($stats),
            ],
        ]);
    }

    /**
     * Estado del circuit breaker
     * 
     * GET /api/monitoreo/circuit-breaker
     */
    public function circuitBreakerStatus(): JsonResponse
    {
        $emailStatus = $this->getCircuitStatusForChannel('email');
        $smsStatus = $this->getCircuitStatusForChannel('sms');

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'email' => $emailStatus,
                'sms' => $smsStatus,
                'alert' => $emailStatus['is_open'] || $smsStatus['is_open'],
                'alert_message' => $this->getCircuitAlertMessage($emailStatus, $smsStatus),
            ],
        ]);
    }

    /**
     * Reiniciar circuit breaker manualmente
     * 
     * POST /api/monitoreo/circuit-breaker/reset
     */
    public function resetCircuitBreaker(): JsonResponse
    {
        Cache::forget('circuit_breaker:email:failures');
        Cache::forget('circuit_breaker:email:opened_at');
        Cache::forget('circuit_breaker:sms:failures');
        Cache::forget('circuit_breaker:sms:opened_at');

        Log::warning('Circuit breaker reseteado manualmente', [
            'user_id' => auth()->user()?->id,
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Circuit breaker reseteado para email y SMS',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Estadísticas de rate limiting
     * 
     * GET /api/monitoreo/rate-limits
     */
    public function rateLimitStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'email' => $this->getRateLimitStatsForChannel('email'),
                'sms' => $this->getRateLimitStatsForChannel('sms'),
                'config' => [
                    'email' => config('envios.rate_limits.email'),
                    'sms' => config('envios.rate_limits.sms'),
                ],
            ],
        ]);
    }

    /**
     * Health check para alertas externas (ej: UptimeRobot, Pingdom)
     * 
     * GET /api/monitoreo/health
     */
    public function health(): JsonResponse
    {
        $queueStats = $this->getQueueStats();
        $circuitStatus = $this->getCircuitBreakerStatus();
        $health = $this->calculateSystemHealth($queueStats, $circuitStatus);

        $httpStatus = match ($health['status']) {
            'healthy' => 200,
            'degraded' => 200, // Still operational
            'critical' => 503,
            default => 500,
        };

        return response()->json([
            'status' => $health['status'],
            'message' => $health['message'],
            'checks' => $health['checks'],
            'timestamp' => now()->toIso8601String(),
        ], $httpStatus);
    }

    /**
     * Reintentar todos los jobs fallidos
     * 
     * POST /api/monitoreo/queue/retry-failed
     */
    public function retryFailedJobs(): JsonResponse
    {
        $failedCount = DB::table('failed_jobs')->count();
        
        if ($failedCount === 0) {
            return response()->json([
                'success' => true,
                'message' => 'No hay jobs fallidos para reintentar',
                'retried' => 0,
            ]);
        }

        // Mover jobs fallidos de vuelta a la cola principal
        $failedJobs = DB::table('failed_jobs')->get();
        $retried = 0;

        foreach ($failedJobs as $failedJob) {
            try {
                // Re-encolar el job
                DB::table('jobs')->insert([
                    'queue' => $failedJob->queue,
                    'payload' => $failedJob->payload,
                    'attempts' => 0,
                    'reserved_at' => null,
                    'available_at' => now()->timestamp,
                    'created_at' => now()->timestamp,
                ]);

                // Eliminar de failed_jobs
                DB::table('failed_jobs')->where('id', $failedJob->id)->delete();
                $retried++;
            } catch (\Exception $e) {
                Log::error('Error reintentando job fallido', [
                    'failed_job_id' => $failedJob->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Jobs fallidos reintentados', [
            'total_failed' => $failedCount,
            'retried' => $retried,
            'user_id' => auth()->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Se reintentaron {$retried} de {$failedCount} jobs fallidos",
            'retried' => $retried,
            'total_failed' => $failedCount,
        ]);
    }

    /**
     * Limpiar jobs fallidos (sin reintentar)
     * 
     * DELETE /api/monitoreo/queue/failed
     */
    public function clearFailedJobs(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();

        Log::warning('Jobs fallidos eliminados', [
            'count' => $count,
            'user_id' => auth()->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Se eliminaron {$count} jobs fallidos",
            'deleted' => $count,
        ]);
    }

    // ============================================
    // Métodos privados de ayuda
    // ============================================

    private function getQueueStats(): array
    {
        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();
        
        // Jobs procesados hoy (de la tabla envios)
        $processedToday = DB::table('envios')
            ->whereDate('created_at', today())
            ->count();

        // Jobs pendientes por cola
        $pendingByQueue = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as count'))
            ->groupBy('queue')
            ->pluck('count', 'queue')
            ->toArray();

        // Job más antiguo pendiente
        $oldestJob = DB::table('jobs')
            ->orderBy('created_at', 'asc')
            ->first();

        $oldestJobAge = $oldestJob 
            ? now()->diffInMinutes(\Carbon\Carbon::createFromTimestamp($oldestJob->created_at))
            : 0;

        return [
            'pending' => $pending,
            'failed' => $failed,
            'processed_today' => $processedToday,
            'pending_by_queue' => $pendingByQueue,
            'oldest_job_age_minutes' => $oldestJobAge,
            'is_processing' => $pending > 0 && $oldestJobAge < 5, // Si hay jobs y el más viejo tiene menos de 5 min
        ];
    }

    private function getRecentFailedJobs(int $limit): array
    {
        return DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);
                return [
                    'id' => $job->uuid,
                    'queue' => $job->queue,
                    'job_class' => $payload['displayName'] ?? 'Unknown',
                    'failed_at' => $job->failed_at,
                    'exception_summary' => \Illuminate\Support\Str::limit($job->exception, 200),
                ];
            })
            ->toArray();
    }

    private function getCircuitBreakerStatus(): array
    {
        return [
            'email' => $this->getCircuitStatusForChannel('email'),
            'sms' => $this->getCircuitStatusForChannel('sms'),
        ];
    }

    private function getCircuitStatusForChannel(string $channel): array
    {
        $failures = (int) Cache::get("circuit_breaker:{$channel}:failures", 0);
        $openedAt = Cache::get("circuit_breaker:{$channel}:opened_at");
        $threshold = config('envios.circuit_breaker.failure_threshold', 10);
        $recoveryTime = config('envios.circuit_breaker.recovery_time', 60);

        $isOpen = false;
        $timeUntilRecovery = null;

        if ($openedAt) {
            $openedAtCarbon = \Carbon\Carbon::parse($openedAt);
            $recoveryAt = $openedAtCarbon->addSeconds($recoveryTime);
            
            if (now()->lt($recoveryAt)) {
                $isOpen = true;
                $timeUntilRecovery = now()->diffInSeconds($recoveryAt);
            }
        }

        return [
            'is_open' => $isOpen,
            'failures' => $failures,
            'threshold' => $threshold,
            'opened_at' => $openedAt,
            'time_until_recovery_seconds' => $timeUntilRecovery,
            'status' => $isOpen ? 'OPEN (bloqueando envíos)' : 'CLOSED (operando normal)',
        ];
    }

    private function getRateLimitStats(): array
    {
        return [
            'email' => $this->getRateLimitStatsForChannel('email'),
            'sms' => $this->getRateLimitStatsForChannel('sms'),
        ];
    }

    private function getRateLimitStatsForChannel(string $channel): array
    {
        $currentSecond = now()->format('Y-m-d-H-i-s');
        $currentMinute = now()->format('Y-m-d-H-i');

        $usedPerSecond = (int) Cache::get("rate_limit:{$channel}:second:{$currentSecond}", 0);
        $usedPerMinute = (int) Cache::get("rate_limit:{$channel}:minute:{$currentMinute}", 0);

        $limitPerSecond = config("envios.rate_limits.{$channel}.per_second", 10);
        $limitPerMinute = config("envios.rate_limits.{$channel}.per_minute", 500);

        return [
            'current_second' => [
                'used' => $usedPerSecond,
                'limit' => $limitPerSecond,
                'percentage' => $limitPerSecond > 0 ? round(($usedPerSecond / $limitPerSecond) * 100, 1) : 0,
            ],
            'current_minute' => [
                'used' => $usedPerMinute,
                'limit' => $limitPerMinute,
                'percentage' => $limitPerMinute > 0 ? round(($usedPerMinute / $limitPerMinute) * 100, 1) : 0,
            ],
        ];
    }

    private function getEnvioStats(): array
    {
        $today = today();

        $stats = DB::table('envios')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'enviado' THEN 1 ELSE 0 END) as enviados,
                SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN tipo = 'email' THEN 1 ELSE 0 END) as emails,
                SUM(CASE WHEN tipo = 'sms' THEN 1 ELSE 0 END) as sms
            ")
            ->whereDate('created_at', $today)
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'enviados' => (int) ($stats->enviados ?? 0),
            'fallidos' => (int) ($stats->fallidos ?? 0),
            'pendientes' => (int) ($stats->pendientes ?? 0),
            'por_tipo' => [
                'email' => (int) ($stats->emails ?? 0),
                'sms' => (int) ($stats->sms ?? 0),
            ],
            'tasa_exito' => $stats->total > 0 
                ? round(($stats->enviados / $stats->total) * 100, 1) 
                : 100,
        ];
    }

    private function calculateSystemHealth(array $queueStats, array $circuitStatus): array
    {
        $checks = [];
        $status = 'healthy';

        // Check 1: Circuit breaker
        if ($circuitStatus['email']['is_open'] || $circuitStatus['sms']['is_open']) {
            $checks['circuit_breaker'] = [
                'status' => 'critical',
                'message' => 'Circuit breaker abierto - envíos bloqueados',
            ];
            $status = 'critical';
        } else {
            $checks['circuit_breaker'] = [
                'status' => 'healthy',
                'message' => 'Circuit breaker cerrado',
            ];
        }

        // Check 2: Failed jobs
        if ($queueStats['failed'] > 100) {
            $checks['failed_jobs'] = [
                'status' => 'critical',
                'message' => "Hay {$queueStats['failed']} jobs fallidos",
            ];
            $status = 'critical';
        } elseif ($queueStats['failed'] > 10) {
            $checks['failed_jobs'] = [
                'status' => 'degraded',
                'message' => "Hay {$queueStats['failed']} jobs fallidos",
            ];
            if ($status === 'healthy') $status = 'degraded';
        } else {
            $checks['failed_jobs'] = [
                'status' => 'healthy',
                'message' => "Jobs fallidos: {$queueStats['failed']}",
            ];
        }

        // Check 3: Queue backlog
        if ($queueStats['pending'] > 10000) {
            $checks['queue_backlog'] = [
                'status' => 'degraded',
                'message' => "Cola con {$queueStats['pending']} jobs pendientes",
            ];
            if ($status === 'healthy') $status = 'degraded';
        } else {
            $checks['queue_backlog'] = [
                'status' => 'healthy',
                'message' => "Jobs pendientes: {$queueStats['pending']}",
            ];
        }

        // Check 4: Stale jobs (más de 30 min sin procesar)
        if ($queueStats['oldest_job_age_minutes'] > 30 && $queueStats['pending'] > 0) {
            $checks['stale_jobs'] = [
                'status' => 'critical',
                'message' => "Job más antiguo tiene {$queueStats['oldest_job_age_minutes']} minutos - posible worker caído",
            ];
            $status = 'critical';
        } else {
            $checks['stale_jobs'] = [
                'status' => 'healthy',
                'message' => 'Worker procesando normalmente',
            ];
        }

        return [
            'status' => $status,
            'message' => match ($status) {
                'healthy' => 'Sistema operando normalmente',
                'degraded' => 'Sistema operando con advertencias',
                'critical' => 'Sistema con problemas críticos',
                default => 'Estado desconocido',
            },
            'checks' => $checks,
        ];
    }

    private function getQueueRecommendations(array $stats): array
    {
        $recommendations = [];

        if ($stats['failed'] > 0) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => "Hay {$stats['failed']} jobs fallidos. Revisá los errores y considerá reintentarlos.",
                'action' => 'POST /api/monitoreo/queue/retry-failed',
            ];
        }

        if ($stats['oldest_job_age_minutes'] > 30 && $stats['pending'] > 0) {
            $recommendations[] = [
                'type' => 'critical',
                'message' => 'Los jobs no se están procesando. Verificá que el worker está corriendo.',
                'action' => 'Revisar logs del worker en Cloud Run',
            ];
        }

        if ($stats['pending'] > 50000) {
            $recommendations[] = [
                'type' => 'info',
                'message' => 'Cola muy grande. Considerá aumentar workers o ajustar rate limits.',
                'action' => 'Escalar nurturing-queue-worker-qa',
            ];
        }

        return $recommendations;
    }

    private function getCircuitAlertMessage(array $emailStatus, array $smsStatus): ?string
    {
        $messages = [];

        if ($emailStatus['is_open']) {
            $messages[] = "Email circuit ABIERTO ({$emailStatus['failures']} fallos). Se recupera en {$emailStatus['time_until_recovery_seconds']}s";
        }

        if ($smsStatus['is_open']) {
            $messages[] = "SMS circuit ABIERTO ({$smsStatus['failures']} fallos). Se recupera en {$smsStatus['time_until_recovery_seconds']}s";
        }

        return empty($messages) ? null : implode(' | ', $messages);
    }
}
