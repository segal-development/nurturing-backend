<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class GoogleCloudStorageServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Storage::extend('gcs', function ($app, $config) {
            $storageClient = new \Google\Cloud\Storage\StorageClient([
                'projectId' => $config['project_id'],
            ]);

            $bucket = $storageClient->bucket($config['bucket']);

            $adapter = new \League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter($bucket, $config['path_prefix'] ?? '');

            return new \Illuminate\Filesystem\FilesystemAdapter(
                new \League\Flysystem\Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }
}
