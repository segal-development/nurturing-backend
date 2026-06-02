<?php

namespace App\Providers;

use App\Events\CircuitBreakerOpened;
use App\Listeners\NotifyCircuitBreakerOpened;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\ProspectoEnFlujo;
use App\Observers\FlujoEjecucionEtapaObserver;
use App\Observers\FlujoEjecucionObserver;
use App\Observers\ProspectoEnFlujoObserver;
use App\Services\GuardedTransition;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // GuardedTransition es la autoridad central para avance y completitud.
        // Registrado como singleton para evitar re-construcción por llamada
        // (puede ser invocado desde observers, jobs y servicios).
        $this->app->singleton(GuardedTransition::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        // Register observers
        FlujoEjecucion::observe(FlujoEjecucionObserver::class);
        FlujoEjecucionEtapa::observe(FlujoEjecucionEtapaObserver::class);
        ProspectoEnFlujo::observe(ProspectoEnFlujoObserver::class);

        // Register event listeners
        Event::listen(CircuitBreakerOpened::class, NotifyCircuitBreakerOpened::class);

        // Force HTTPS in production/cloud environments
        if (config('app.env') !== 'local') {
            URL::forceScheme('https');
        }

        // =========================================================================
        // RESILIENCIA: Configurar timeouts de PostgreSQL cuando se establece conexión
        // =========================================================================
        // Usamos el evento ConnectionEstablished para configurar timeouts UNA vez
        // por conexión, no en cada request. Esto previene:
        // - Conexiones colgadas cuando Cloud Run mata instancias
        // - Transacciones zombie que bloquean tablas
        // - Queries infinitos que saturan la BD
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            $connection = $event->connection;
            $driverName = $connection->getDriverName();

            if ($driverName !== 'pgsql') {
                return;
            }

            try {
                // statement_timeout: Máximo tiempo para un query individual
                // Previene queries que nunca terminan
                $connection->statement("SET statement_timeout = '30s'");

                // idle_in_transaction_session_timeout: Máximo tiempo idle en transacción
                // Previene transacciones abiertas que bloquean filas
                $connection->statement("SET idle_in_transaction_session_timeout = '60s'");

                // lock_timeout: Máximo tiempo esperando un lock
                // Falla rápido en vez de esperar indefinidamente
                $connection->statement("SET lock_timeout = '10s'");

            } catch (\Exception $e) {
                // Log pero no fallar - los defaults de PostgreSQL aplican
                \Illuminate\Support\Facades\Log::warning('AppServiceProvider: No se pudieron configurar timeouts de PostgreSQL', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Configure rate limiters for the application.
     *
     * Rate limiters protect the API from abuse and ensure fair usage:
     * - 'api': General API endpoints (60/min per user or IP)
     * - 'auth': Login/register endpoints (5/min per IP - prevents brute force)
     * - 'heavy': Expensive operations like reports/exports (10/min per user)
     * - 'cron': Cloud Scheduler endpoints (no limit - internal use only)
     */
    protected function configureRateLimiting(): void
    {
        // =========================================================================
        // API General: 60 requests/minuto
        // =========================================================================
        // Para usuarios autenticados: por user ID
        // Para usuarios no autenticados: por IP
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            )->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Demasiadas solicitudes. Por favor, espera antes de continuar.',
                    'retry_after' => $headers['Retry-After'] ?? 60,
                ], 429, $headers);
            });
        });

        // =========================================================================
        // Autenticación: 5 requests/minuto (prevenir brute force)
        // =========================================================================
        // Siempre por IP porque el usuario aún no está autenticado
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by(
                $request->ip()
            )->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Demasiados intentos de autenticación. Espera un momento.',
                    'retry_after' => $headers['Retry-After'] ?? 60,
                ], 429, $headers);
            });
        });

        // =========================================================================
        // Operaciones pesadas: 10 requests/minuto
        // =========================================================================
        // Para dashboards, reportes, exports que son costosos en DB
        RateLimiter::for('heavy', function (Request $request) {
            return Limit::perMinute(10)->by(
                $request->user()?->id ?: $request->ip()
            )->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Esta operación es costosa. Por favor, espera antes de continuar.',
                    'retry_after' => $headers['Retry-After'] ?? 60,
                ], 429, $headers);
            });
        });

        // =========================================================================
        // Cloud Scheduler (CRON): Sin límite
        // =========================================================================
        // Rutas internas protegidas por cron.secret middleware
        RateLimiter::for('cron', function (Request $request) {
            return Limit::none();
        });

        // =========================================================================
        // Health Check: 30 requests/minuto por IP
        // =========================================================================
        // Público pero limitado para prevenir reconnaissance/abuse
        RateLimiter::for('health', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // =========================================================================
        // AI Chat: 20 requests/minuto
        // =========================================================================
        // Para el chat de IA que consume tokens de API externos
        // Límite más estricto para controlar costos
        RateLimiter::for('ai-chat', function (Request $request) {
            return Limit::perMinute(20)->by(
                $request->user()?->id ?: $request->ip()
            )->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Demasiadas solicitudes al asistente de IA. Por favor, espera un momento.',
                    'retry_after' => $headers['Retry-After'] ?? 60,
                ], 429, $headers);
            });
        });
    }
}
