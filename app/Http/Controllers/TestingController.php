<?php

namespace App\Http\Controllers;

use App\Jobs\EjecutarNodosProgramados;
use App\Jobs\VerificarCondicionJob;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionCondicion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller para testing de condiciones y flujos
 * 
 * IMPORTANTE: Solo usar en desarrollo/staging, no en producción
 * 
 * Permite:
 * - Simular estadísticas de AthenaCampaign
 * - Forzar verificación de condiciones
 * - Ver estado de condiciones evaluadas
 */
class TestingController extends Controller
{
    /**
     * Simula estadísticas de AthenaCampaign para un mensaje
     * 
     * POST /api/testing/simular-estadisticas
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * 
     * Body esperado:
     * {
     *   "message_id": 12345,
     *   "views": 5,
     *   "clicks": 2,
     *   "bounces": 0,
     *   "unsubscribes": 0
     * }
     */
    public function simularEstadisticas(Request $request)
    {
        $validated = $request->validate([
            'message_id' => 'required|integer',
            'views' => 'sometimes|integer|min:0',
            'clicks' => 'sometimes|integer|min:0',
            'bounces' => 'sometimes|integer|min:0',
            'unsubscribes' => 'sometimes|integer|min:0',
        ]);

        // Guardar en cache para que el servicio mock lo use
        $cacheKey = "mock_stats_{$validated['message_id']}";
        $stats = [
            'messageID' => $validated['message_id'],
            'Recipients' => 1,
            'Views' => $validated['views'] ?? 0,
            'Clicks' => $validated['clicks'] ?? 0,
            'Bounces' => $validated['bounces'] ?? 0,
            'Unsubscribes' => $validated['unsubscribes'] ?? 0,
        ];

        cache()->put($cacheKey, $stats, now()->addHours(24));

        Log::info('Testing: Estadísticas simuladas guardadas', [
            'message_id' => $validated['message_id'],
            'stats' => $stats,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estadísticas simuladas guardadas',
            'data' => $stats,
            'cache_key' => $cacheKey,
            'expires_at' => now()->addHours(24)->toIso8601String(),
        ]);
    }

    /**
     * Fuerza la verificación de una condición inmediatamente
     * 
     * POST /api/testing/forzar-verificacion-condicion
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * 
     * Body esperado:
     * {
     *   "flujo_ejecucion_id": 1,
     *   "etapa_ejecucion_id": 2,
     *   "condition_node_id": "condition_123",
     *   "message_id": 12345,
     *   "check_param": "Views",
     *   "check_operator": ">",
     *   "check_value": 0
     * }
     */
    public function forzarVerificacionCondicion(Request $request)
    {
        $validated = $request->validate([
            'flujo_ejecucion_id' => 'required|integer|exists:flujo_ejecuciones,id',
            'etapa_ejecucion_id' => 'required|integer|exists:flujo_ejecucion_etapas,id',
            'condition_node_id' => 'required|string',
            'message_id' => 'required|integer',
            'check_param' => 'sometimes|string|in:Views,Clicks,Bounces,Unsubscribes',
            'check_operator' => 'sometimes|string|in:>,>=,==,!=,<,<=,in,not_in',
            'check_value' => 'sometimes',
        ]);

        $condicion = [
            'target_node_id' => $validated['condition_node_id'],
            'data' => [
                'check_param' => $validated['check_param'] ?? 'Views',
                'check_operator' => $validated['check_operator'] ?? '>',
                'check_value' => $validated['check_value'] ?? 0,
            ],
        ];

        Log::info('Testing: Forzando verificación de condición', [
            'flujo_ejecucion_id' => $validated['flujo_ejecucion_id'],
            'etapa_ejecucion_id' => $validated['etapa_ejecucion_id'],
            'message_id' => $validated['message_id'],
            'condicion' => $condicion,
        ]);

        // Despachar el job inmediatamente (sin delay)
        VerificarCondicionJob::dispatchSync(
            $validated['flujo_ejecucion_id'],
            $validated['etapa_ejecucion_id'],
            $condicion,
            $validated['message_id']
        );

        return response()->json([
            'success' => true,
            'message' => 'Verificación de condición ejecutada',
            'data' => [
                'flujo_ejecucion_id' => $validated['flujo_ejecucion_id'],
                'etapa_ejecucion_id' => $validated['etapa_ejecucion_id'],
                'condition_node_id' => $validated['condition_node_id'],
            ],
        ]);
    }

    /**
     * Lista todas las condiciones evaluadas de una ejecución
     * 
     * GET /api/testing/condiciones-evaluadas/{flujoEjecucionId}
     * 
     * @param int $flujoEjecucionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function condicionesEvaluadas(int $flujoEjecucionId)
    {
        $ejecucion = FlujoEjecucion::with(['flujo'])->findOrFail($flujoEjecucionId);

        $condiciones = FlujoEjecucionCondicion::where('flujo_ejecucion_id', $flujoEjecucionId)
            ->orderBy('fecha_verificacion', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'ejecucion' => [
                    'id' => $ejecucion->id,
                    'flujo_id' => $ejecucion->flujo_id,
                    'flujo_nombre' => $ejecucion->flujo->nombre,
                    'estado' => $ejecucion->estado,
                    'nodo_actual' => $ejecucion->nodo_actual,
                    'proximo_nodo' => $ejecucion->proximo_nodo,
                ],
                'condiciones' => $condiciones->map(function ($cond) {
                    return [
                        'id' => $cond->id,
                        'condition_node_id' => $cond->condition_node_id,
                        'check_param' => $cond->check_param,
                        'check_operator' => $cond->check_operator,
                        'check_value' => $cond->check_value,
                        'check_result_value' => $cond->check_result_value,
                        'resultado' => $cond->resultado, // 'yes' o 'no'
                        'fecha_verificacion' => $cond->fecha_verificacion,
                        'response_raw' => $cond->response_athenacampaign,
                    ];
                }),
                'total_condiciones' => $condiciones->count(),
                'condiciones_yes' => $condiciones->where('resultado', 'yes')->count(),
                'condiciones_no' => $condiciones->where('resultado', 'no')->count(),
            ],
        ]);
    }

    /**
     * Lista todas las etapas de ejecución de un flujo
     * 
     * GET /api/testing/etapas-ejecucion/{flujoEjecucionId}
     * 
     * @param int $flujoEjecucionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function etapasEjecucion(int $flujoEjecucionId)
    {
        $ejecucion = FlujoEjecucion::findOrFail($flujoEjecucionId);

        $etapas = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $flujoEjecucionId)
            ->orderBy('fecha_programada', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'ejecucion_id' => $flujoEjecucionId,
                'estado_ejecucion' => $ejecucion->estado,
                'etapas' => $etapas->map(function ($etapa) {
                    return [
                        'id' => $etapa->id,
                        'node_id' => $etapa->node_id,
                        'estado' => $etapa->estado,
                        'message_id' => $etapa->message_id,
                        'fecha_programada' => $etapa->fecha_programada,
                        'fecha_ejecucion' => $etapa->fecha_ejecucion,
                        'error_mensaje' => $etapa->error_mensaje,
                        'response_athenacampaign' => $etapa->response_athenacampaign,
                    ];
                }),
                'total_etapas' => $etapas->count(),
                'etapas_completadas' => $etapas->where('estado', 'completed')->count(),
                'etapas_pendientes' => $etapas->where('estado', 'pending')->count(),
                'etapas_fallidas' => $etapas->where('estado', 'failed')->count(),
            ],
        ]);
    }

    /**
     * Lista todos los jobs de un flujo de ejecución
     * 
     * GET /api/testing/jobs/{flujoEjecucionId}
     * 
     * @param int $flujoEjecucionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function jobsEjecucion(int $flujoEjecucionId)
    {
        $jobs = FlujoJob::where('flujo_ejecucion_id', $flujoEjecucionId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'ejecucion_id' => $flujoEjecucionId,
                'jobs' => $jobs->map(function ($job) {
                    return [
                        'id' => $job->id,
                        'job_type' => $job->job_type,
                        'estado' => $job->estado,
                        'job_data' => $job->job_data,
                        'fecha_queued' => $job->fecha_queued,
                        'fecha_procesado' => $job->fecha_procesado,
                        'error_details' => $job->error_details,
                        'intentos' => $job->intentos,
                    ];
                }),
                'total_jobs' => $jobs->count(),
                'jobs_completados' => $jobs->where('estado', 'completed')->count(),
                'jobs_pendientes' => $jobs->where('estado', 'pending')->count(),
                'jobs_fallidos' => $jobs->where('estado', 'failed')->count(),
            ],
        ]);
    }

    /**
     * Verifica la IP de salida del servidor
     * 
     * GET /api/testing/check-ip
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkIp()
    {
        $ip = @file_get_contents('https://api.ipify.org');
        
        return response()->json([
            'success' => true,
            'data' => [
                'egress_ip' => $ip ?: 'No se pudo obtener',
                'expected_ip' => '136.115.89.134',
                'match' => $ip === '136.115.89.134',
            ],
        ]);
    }

    /**
     * Evalúa una condición manualmente sin afectar la ejecución
     * 
     * POST /api/testing/evaluar-condicion
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * 
     * Body esperado:
     * {
     *   "actual_value": 5,
     *   "check_operator": ">",
     *   "check_value": 0
     * }
     */
    public function evaluarCondicion(Request $request)
    {
        $validated = $request->validate([
            'actual_value' => 'required|integer',
            'check_operator' => 'required|string|in:>,>=,==,!=,<,<=,in,not_in',
            'check_value' => 'required',
        ]);

        $actualValue = (int) $validated['actual_value'];
        $operator = $validated['check_operator'];
        $expectedValue = $validated['check_value'];

        $resultado = match ($operator) {
            '>' => $actualValue > (int) $expectedValue,
            '>=' => $actualValue >= (int) $expectedValue,
            '==' => $actualValue == (int) $expectedValue,
            '!=' => $actualValue != (int) $expectedValue,
            '<' => $actualValue < (int) $expectedValue,
            '<=' => $actualValue <= (int) $expectedValue,
            'in' => in_array($actualValue, array_map('intval', explode(',', $expectedValue))),
            'not_in' => ! in_array($actualValue, array_map('intval', explode(',', $expectedValue))),
            default => false,
        };

        return response()->json([
            'success' => true,
            'data' => [
                'actual_value' => $actualValue,
                'operator' => $operator,
                'expected_value' => $expectedValue,
                'resultado' => $resultado,
                'resultado_label' => $resultado ? 'yes (condición cumplida)' : 'no (condición no cumplida)',
                'expresion' => "{$actualValue} {$operator} {$expectedValue}",
            ],
        ]);
    }

    /**
     * Procesa jobs pendientes de la cola
     * 
     * POST /api/cron/process-queue
     * 
     * Este endpoint es llamado por Cloud Scheduler cada minuto
     * para procesar los jobs pendientes ya que Cloud Run no mantiene
     * workers persistentes.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function processQueue(Request $request)
    {
        $startTime = microtime(true);
        $jobsProcessed = 0;
        $errors = [];

        Log::info('CronProcessQueue: Iniciando procesamiento de jobs');

        try {
            // Procesar hasta 10 jobs o 50 segundos (lo que ocurra primero)
            $maxJobs = 10;
            $maxTime = 50; // segundos

            while ($jobsProcessed < $maxJobs && (microtime(true) - $startTime) < $maxTime) {
                // Obtener un job pendiente
                $job = DB::table('jobs')
                    ->where('queue', 'default')
                    ->whereNull('reserved_at')
                    ->orderBy('id', 'asc')
                    ->first();

                if (!$job) {
                    Log::info('CronProcessQueue: No hay más jobs pendientes');
                    break;
                }

                try {
                    // Procesar el job usando artisan
                    Artisan::call('queue:work', [
                        '--once' => true,
                        '--queue' => 'default',
                        '--timeout' => 30,
                    ]);

                    $jobsProcessed++;
                    Log::info('CronProcessQueue: Job procesado', ['job_id' => $job->id]);

                } catch (\Exception $e) {
                    $errors[] = [
                        'job_id' => $job->id,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('CronProcessQueue: Error procesando job', [
                        'job_id' => $job->id,
                        'error' => $e->getMessage(),
                    ]);
                    break; // Salir si hay error para no quedarnos en loop
                }
            }

            // También ejecutar EjecutarNodosProgramados directamente
            try {
                $ejecutarNodos = new EjecutarNodosProgramados();
                $ejecutarNodos->handle(app(\App\Services\EnvioService::class));
                Log::info('CronProcessQueue: EjecutarNodosProgramados ejecutado');
            } catch (\Exception $e) {
                $errors[] = [
                    'job' => 'EjecutarNodosProgramados',
                    'error' => $e->getMessage(),
                ];
                Log::error('CronProcessQueue: Error en EjecutarNodosProgramados', [
                    'error' => $e->getMessage(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('CronProcessQueue: Error general', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        $duration = round(microtime(true) - $startTime, 2);

        // Contar jobs restantes
        $jobsRemaining = DB::table('jobs')->count();

        Log::info('CronProcessQueue: Procesamiento completado', [
            'jobs_processed' => $jobsProcessed,
            'jobs_remaining' => $jobsRemaining,
            'duration_seconds' => $duration,
            'errors_count' => count($errors),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'jobs_processed' => $jobsProcessed,
                'jobs_remaining' => $jobsRemaining,
                'duration_seconds' => $duration,
                'errors' => $errors,
            ],
        ]);
    }

    /**
     * Debug endpoint para ver estado de ejecuciones (solo cron)
     */
    public function debugEjecuciones()
    {
        // Obtener ejecuciones activas
        $ejecuciones = FlujoEjecucion::where('estado', 'in_progress')
            ->with(['flujo:id,nombre'])
            ->get()
            ->map(function ($ejecucion) {
                // Obtener etapas
                $etapas = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
                    ->orderBy('id')
                    ->get(['id', 'node_id', 'estado', 'ejecutado', 'message_id', 'fecha_programada', 'fecha_ejecucion']);
                
                // Obtener condiciones evaluadas
                $condiciones = FlujoEjecucionCondicion::where('flujo_ejecucion_id', $ejecucion->id)
                    ->get(['id', 'condition_node_id', 'check_param', 'check_operator', 'check_value', 'check_result_value', 'resultado', 'fecha_verificacion']);

                return [
                    'id' => $ejecucion->id,
                    'flujo_id' => $ejecucion->flujo_id,
                    'flujo_nombre' => $ejecucion->flujo->nombre ?? null,
                    'estado' => $ejecucion->estado,
                    'nodo_actual' => $ejecucion->nodo_actual,
                    'proximo_nodo' => $ejecucion->proximo_nodo,
                    'fecha_proximo_nodo' => $ejecucion->fecha_proximo_nodo,
                    'porcentaje_completado' => $ejecucion->porcentaje_completado,
                    'etapas' => $etapas,
                    'condiciones' => $condiciones,
                ];
            });

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            'ejecuciones_activas' => $ejecuciones,
        ]);
    }
}
