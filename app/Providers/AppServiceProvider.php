<?php

namespace App\Providers;

use App\Events\CircuitBreakerOpened;
use App\Listeners\NotifyCircuitBreakerOpened;
use App\Models\FlujoEjecucion;
use App\Observers\FlujoEjecucionObserver;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register observers
        FlujoEjecucion::observe(FlujoEjecucionObserver::class);

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
}
