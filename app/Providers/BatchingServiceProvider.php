<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Batching\BatchingStrategyInterface;
use App\Services\Batching\EnvioBatchService;
use App\Services\Batching\FixedBatchingStrategy;
use Illuminate\Support\ServiceProvider;

/**
 * Service Provider para el sistema de batching de envíos.
 *
 * Registra las implementaciones de la estrategia de batching
 * y el servicio de orquestación.
 */
class BatchingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Registrar la estrategia de batching (singleton para reutilizar config)
        $this->app->singleton(BatchingStrategyInterface::class, FixedBatchingStrategy::class);

        // Registrar el servicio de batching
        $this->app->singleton(EnvioBatchService::class, function ($app) {
            return new EnvioBatchService(
                $app->make(BatchingStrategyInterface::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publicar configuración si es necesario
        $this->publishes([
            __DIR__ . '/../../config/batching.php' => config_path('batching.php'),
        ], 'batching-config');
    }
}
