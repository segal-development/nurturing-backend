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

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
