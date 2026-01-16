<?php

namespace App\Providers;

use App\Events\CircuitBreakerOpened;
use App\Listeners\NotifyCircuitBreakerOpened;
use App\Models\FlujoEjecucion;
use App\Observers\FlujoEjecucionObserver;
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
    }
}
