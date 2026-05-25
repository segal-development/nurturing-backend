<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Corre el reconcile de SYSGAL (contratos + clientes-ingreso) del mes en curso y
 * cachea el resumen para que el dashboard lo muestre SIN pegarle en vivo a SYSGAL.
 *
 * Por qué cachear: el reconcile hace llamadas live a la API de SYSGAL (solo desde la
 * VM whitelisteada). Correrlo en cada carga del panel la saturaría y dispararía 403.
 * Este comando lo corre en background (scheduler) y deja el resultado listo en cache.
 *
 * El dashboard lee la cache via MetricasService::getReconciliacionSysgal().
 */
class CacheReconciliacionSysgalCommand extends Command
{
    protected $signature = 'nurturing:cache-reconciliacion';

    protected $description = 'Corre el reconcile SYSGAL (contratos + clientes-ingreso) del mes y cachea el resumen para el dashboard';

    public const CACHE_KEY = 'metricas:reconciliacion-sysgal';

    public function handle(): int
    {
        $desde = now()->startOfMonth()->toDateString();
        $resultado = [];

        foreach (['contratos', 'clientes-ingreso'] as $endpoint) {
            try {
                Artisan::call('nurturing:reconcile-sysgal', [
                    '--endpoint' => $endpoint,
                    '--desde' => $desde,
                    '--json' => true,
                ]);

                $json = json_decode(trim(Artisan::output()), true);

                if (is_array($json) && isset($json['sysgal_total'])) {
                    $resultado[$endpoint] = $json;
                } else {
                    $this->warn("Reconcile '{$endpoint}': salida no parseable, se conserva el cache previo de este endpoint.");
                }
            } catch (\Throwable $e) {
                Log::warning('CacheReconciliacionSysgal: error reconciliando '.$endpoint, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($resultado)) {
            $this->error('No se pudo reconciliar ningún endpoint; cache sin cambios.');

            return self::FAILURE;
        }

        // Store en BASE DE DATOS, NO en el Redis default: la VM (que corre este comando)
        // y la API/Cloud Run NO comparten Redis (la VM usa Redis local 127.0.0.1). La DB
        // (Cloud SQL) SÍ es compartida, así que el dashboard de la API puede leer lo que
        // escribe la VM acá.
        $store = Cache::store('database');

        // Merge sobre lo previo: si un endpoint falló, conserva su último valor bueno.
        $payload = array_merge(
            (array) $store->get(self::CACHE_KEY, []),
            $resultado,
            ['generado_at' => now()->toIso8601String(), 'desde' => $desde]
        );

        $store->put(self::CACHE_KEY, $payload, now()->addHours(12));

        $this->info('Reconciliación SYSGAL cacheada para: '.implode(', ', array_keys($resultado)));

        return self::SUCCESS;
    }
}
