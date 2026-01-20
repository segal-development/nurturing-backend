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

        // ✅ CRÍTICO: Configurar timeouts de PostgreSQL para prevenir conexiones/transacciones colgadas
        // Esto evita que Cloud Run deje transacciones abiertas cuando mata instancias
        if (config('database.default') === 'pgsql') {
            try {
                DB::statement("SET statement_timeout = '60s'");
                DB::statement("SET idle_in_transaction_session_timeout = '120s'");
            } catch (\Exception $e) {
                // Silenciar error si la conexión aún no está lista
                // Los timeouts ya están configurados a nivel de DATABASE en PostgreSQL
            }
        }
    }
}
