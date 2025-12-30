<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Controller para ejecutar tareas programadas via HTTP
 * Usado por Cloud Scheduler en GCP
 */
class SchedulerController extends Controller
{
    /**
     * Token secreto para autenticar las llamadas del scheduler
     * Se configura en .env como SCHEDULER_SECRET
     */
    private function validateSecret(Request $request): bool
    {
        $secret = config('app.scheduler_secret');
        
        // Si no hay secret configurado, permitir en desarrollo
        if (empty($secret)) {
            return app()->environment('local', 'testing');
        }
        
        $providedSecret = $request->header('X-Scheduler-Secret') 
            ?? $request->input('secret');
            
        return $providedSecret === $secret;
    }

    /**
     * Ejecutar el scheduler de Laravel
     * POST /api/scheduler/run
     */
    public function run(Request $request): JsonResponse
    {
        if (!$this->validateSecret($request)) {
            Log::warning('SchedulerController: Intento de acceso no autorizado', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Log::info('SchedulerController: Ejecutando schedule:run');

        try {
            $exitCode = Artisan::call('schedule:run');
            $output = Artisan::output();

            Log::info('SchedulerController: schedule:run completado', [
                'exit_code' => $exitCode,
                'output' => $output,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Scheduler ejecutado correctamente',
                'exit_code' => $exitCode,
                'output' => $output,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('SchedulerController: Error al ejecutar schedule:run', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Procesar jobs de la queue
     * POST /api/scheduler/queue
     */
    public function processQueue(Request $request): JsonResponse
    {
        if (!$this->validateSecret($request)) {
            Log::warning('SchedulerController: Intento de acceso no autorizado a queue', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Número de jobs a procesar por llamada
        $maxJobs = $request->input('max_jobs', 10);
        $timeout = $request->input('timeout', 60);

        Log::info('SchedulerController: Procesando queue', [
            'max_jobs' => $maxJobs,
            'timeout' => $timeout,
        ]);

        try {
            $exitCode = Artisan::call('queue:work', [
                '--max-jobs' => $maxJobs,
                '--stop-when-empty' => true,
                '--timeout' => $timeout,
                '--tries' => 3,
            ]);
            $output = Artisan::output();

            Log::info('SchedulerController: queue:work completado', [
                'exit_code' => $exitCode,
                'output' => $output,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Queue procesada correctamente',
                'exit_code' => $exitCode,
                'output' => $output,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('SchedulerController: Error al procesar queue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Health check para el scheduler
     * GET /api/scheduler/health
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'queue_connection' => config('queue.default'),
            'environment' => app()->environment(),
        ]);
    }
}
