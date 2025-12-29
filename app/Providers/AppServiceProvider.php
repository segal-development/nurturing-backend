<?php

namespace App\Providers;

use App\Models\FlujoEjecucion;
use App\Observers\FlujoEjecucionObserver;
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

        // Force HTTPS in production/cloud environments
        if (config('app.env') !== 'local') {
            URL::forceScheme('https');
        }
    }
}
