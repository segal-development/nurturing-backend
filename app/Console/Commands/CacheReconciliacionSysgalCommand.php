<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia SYSGAL (contratos + clientes-ingreso) HOY y mes en curso, y deja el
 * resumen en la tabla `cache` (Cloud SQL, compartida VM↔API) para que el dashboard lo
 * lea sin pegarle en vivo a SYSGAL.
 *
 * Por qué tabla `cache` con KEY LITERAL (y no la facade Cache):
 * - El reconcile hace llamadas live a SYSGAL (solo desde la VM whitelisteada), así que
 *   no puede correr por request; lo corre el scheduler y deja el resultado listo.
 * - La VM y la API/Cloud Run NO comparten Redis (la VM usa Redis local). La DB sí.
 * - Usamos una key LITERAL ('reconciliacion-sysgal', sin el prefijo de Laravel) para que
 *   VM y API lean/escriban exactamente la misma fila, sin depender de que el cache.prefix
 *   coincida entre los dos servicios (que es lo que rompía la lectura antes).
 */
class CacheReconciliacionSysgalCommand extends Command
{
    protected $signature = 'nurturing:cache-reconciliacion';

    protected $description = 'Reconcilia SYSGAL (contratos + clientes-ingreso) HOY y mes, y lo guarda en la tabla cache para el dashboard';

    /** Key LITERAL en la tabla cache (sin prefijo Laravel). La lee MetricasService. */
    public const CACHE_KEY = 'reconciliacion-sysgal';

    public function handle(): int
    {
        $mesDesde = now()->startOfMonth()->toDateString();
        $hoyDesde = now()->toDateString();

        $resultado = [];
        foreach (['contratos', 'clientes-ingreso'] as $endpoint) {
            $mes = $this->reconcile($endpoint, $mesDesde);
            $hoy = $this->reconcile($endpoint, $hoyDesde);

            $periodos = array_filter(['mes' => $mes, 'hoy' => $hoy], fn ($v) => $v !== null);
            if (! empty($periodos)) {
                $resultado[$endpoint] = $periodos;
            }
        }

        if (empty($resultado)) {
            $this->error('No se pudo reconciliar ningún endpoint; cache sin cambios.');

            return self::FAILURE;
        }

        $payload = $resultado + ['generado_at' => now()->toIso8601String()];

        DB::table('cache')->updateOrInsert(
            ['key' => self::CACHE_KEY],
            ['value' => json_encode($payload), 'expiration' => now()->addHours(12)->timestamp]
        );

        $this->info('Reconciliación SYSGAL (hoy + mes) cacheada para: '.implode(', ', array_keys($resultado)));

        return self::SUCCESS;
    }

    /**
     * Corre el reconcile de un endpoint desde $desdeDate (Y-m-d) hasta ahora.
     *
     * @return array<string, mixed>|null  El resumen, o null si falló/no parseó.
     */
    private function reconcile(string $endpoint, string $desdeDate): ?array
    {
        try {
            Artisan::call('nurturing:reconcile-sysgal', [
                '--endpoint' => $endpoint,
                '--desde' => $desdeDate,
                '--json' => true,
            ]);

            $json = json_decode(trim(Artisan::output()), true);

            if (is_array($json) && isset($json['sysgal_total'])) {
                return $json;
            }

            $this->warn("Reconcile '{$endpoint}' desde {$desdeDate}: salida no parseable.");

            return null;
        } catch (\Throwable $e) {
            Log::warning('CacheReconciliacionSysgal: error reconciliando '.$endpoint, [
                'desde' => $desdeDate,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
