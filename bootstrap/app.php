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
        // Ejecutar verificación de nodos programados cada minuto
        $schedule->job(\App\Jobs\EjecutarNodosProgramados::class)
            ->everyMinute()
            ->name('ejecutar-nodos-programados')
            ->withoutOverlapping();

        // Sincronizar prospectos desde APIs externas - todos los viernes a las 2am
        $schedule->job(\App\Jobs\SyncExternalApiJob::class)
            ->fridays()
            ->at('02:00')
            ->name('sync-external-api-prospectos')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('SyncExternalApiJob: Job programado falló');
            });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
