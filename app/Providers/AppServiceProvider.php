<?php

namespace App\Providers;

use App\Models\FlujoEjecucion;
use App\Observers\FlujoEjecucionObserver;
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
    }
}
