<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Configurar Sanctum para SPA authentication
        // statefulApi() maneja automáticamente cookies y sesiones
        $middleware->statefulApi();

        // =========================================================================
        // RESILIENCIA: Middleware de health check de BD para toda la API
        // =========================================================================
        // Este middleware verifica que la BD esté disponible antes de procesar
        // requests. Si la BD está saturada, retorna 503 inmediatamente en vez
        // de quedarse colgado esperando. Usa circuit breaker pattern.
        $middleware->appendToGroup('api', \App\Http\Middleware\DatabaseHealthMiddleware::class);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'cron.secret' => \App\Http\Middleware\VerifyCronSecret::class,
            'db.health' => \App\Http\Middleware\DatabaseHealthMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(fn () => response()->json([
            'message' => 'Unauthenticated.',
        ], 401));
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        // Scheduling consolidado 100% en routes/console.php (un solo scheduler).
        //
        // NO agregar tareas acá. bootstrap/app.php (withSchedule) y routes/console.php
        // (Schedule facade) corren AMBOS: duplicar tareas hizo que los syncs de SYSGAL
        // se dispararan en ráfaga (07:00) y devolvieran 403 "No permitido" (mayo 2026).
        // Cualquier tarea programada va en routes/console.php, una sola vez.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always return JSON for API authentication/authorization errors
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error' => 'session_expired',
                ], 401);
            }
        });

        // Handle CSRF token mismatch for API routes
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'CSRF token mismatch. Please refresh the page.',
                    'error' => 'csrf_token_mismatch',
                ], 419);
            }
        });
    })->create();
