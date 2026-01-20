<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCronSecret
{
    /**
     * Handle an incoming request.
     *
     * Verifica que el request venga de Cloud Scheduler usando un secreto
     * o que venga de una IP de Google Cloud.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Opción 1: Verificar header secreto
        $expectedSecret = config('app.cron_secret', env('CRON_SECRET'));
        $providedSecret = $request->header('X-Cron-Secret');

        if ($expectedSecret && $providedSecret === $expectedSecret) {
            return $next($request);
        }

        // Opción 2: Verificar que viene de Cloud Scheduler (header especial)
        $cloudSchedulerHeader = $request->header('X-CloudScheduler');
        if ($cloudSchedulerHeader === 'true') {
            return $next($request);
        }

        // Opción 3: En desarrollo/staging, permitir sin secreto si APP_DEBUG=true
        if (config('app.debug') && config('app.env') !== 'production') {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'error' => 'Unauthorized - Invalid cron secret',
        ], 401);
    }
}
