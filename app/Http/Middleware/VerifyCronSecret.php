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
        // NOTA: Este header puede ser spoofeado, solo usar como fallback con IP verification
        $cloudSchedulerHeader = $request->header('X-CloudScheduler');
        $isGoogleCloudIP = $this->isGoogleCloudIP($request->ip());
        
        if ($cloudSchedulerHeader === 'true' && $isGoogleCloudIP) {
            return $next($request);
        }

        // Opción 3: Solo en LOCAL development (no staging, no production)
        if (config('app.env') === 'local' && config('app.debug')) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'error' => 'Unauthorized - Invalid cron secret',
        ], 401);
    }

    /**
     * Verifica si la IP pertenece a rangos de Google Cloud.
     * 
     * Google Cloud Scheduler usa IPs de estos rangos:
     * https://cloud.google.com/compute/docs/faq#find_ip_range
     */
    private function isGoogleCloudIP(?string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        // Rangos de IP conocidos de Google Cloud (simplificado)
        // En producción, considera usar una lista actualizada o el header X-Forwarded-For
        $googleRanges = [
            '35.187.0.0/16',
            '35.189.0.0/16', 
            '35.190.0.0/16',
            '35.192.0.0/14',
            '35.196.0.0/15',
            '35.198.0.0/16',
            '35.199.0.0/17',
            '35.199.128.0/18',
            '35.200.0.0/13',
            '35.208.0.0/12',
            '35.224.0.0/12',
            '35.240.0.0/13',
        ];

        foreach ($googleRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si una IP está dentro de un rango CIDR.
     */
    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - (int) $bits);
        
        $subnet &= $mask;
        
        return ($ip & $mask) === $subnet;
    }
}
