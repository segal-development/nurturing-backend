<?php

namespace App\Console\Commands;

use App\Models\ExternalApiSource;
use App\Services\SysgalApiSyncService;
use Illuminate\Console\Command;

/**
 * Comando para sincronizar prospectos desde la API de Sysgal.
 *
 * Uso:
 *   php artisan sync:sysgal no_agendados           # Sync prospectos no agendados (última semana)
 *   php artisan sync:sysgal no_cerrados            # Sync agendas no cerradas (última semana)
 *   php artisan sync:sysgal all                    # Sync ambas fuentes
 *   php artisan sync:sysgal no_agendados --dias=14 # Últimos 14 días
 *   php artisan sync:sysgal --test                 # Test de conexión
 *   php artisan sync:sysgal --stats                # Ver estadísticas
 */
class SyncSysgalCommand extends Command
{
    protected $signature = 'sync:sysgal
                            {source? : Fuente a sincronizar (no_agendados, no_cerrados, all)}
                            {--dias=7 : Días hacia atrás a sincronizar}
                            {--test : Solo probar conexión sin sincronizar}
                            {--stats : Mostrar estadísticas de las fuentes}';

    protected $description = 'Sincroniza prospectos desde la API de Sysgal (Defensoría)';

    private SysgalApiSyncService $syncService;

    public function __construct()
    {
        parent::__construct();
        $this->syncService = new SysgalApiSyncService;
    }

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        if ($this->option('test')) {
            return $this->testConnections();
        }

        $source = $this->argument('source');

        if (empty($source)) {
            $this->error('Debe especificar una fuente: no_agendados, no_cerrados o all');
            $this->line('');
            $this->line('Uso:');
            $this->line('  php artisan sync:sysgal no_agendados    # Prospectos que no agendaron');
            $this->line('  php artisan sync:sysgal no_cerrados     # Agendas que no cerraron');
            $this->line('  php artisan sync:sysgal all             # Ambas fuentes');
            $this->line('  php artisan sync:sysgal --test          # Probar conexión');
            $this->line('  php artisan sync:sysgal --stats         # Ver estadísticas');

            return Command::FAILURE;
        }

        $dias = (int) $this->option('dias');

        if ($source === 'all') {
            return $this->syncAll($dias);
        }

        $sourceName = $this->resolveSourceName($source);

        if ($sourceName === null) {
            $this->error("Fuente '{$source}' no reconocida. Use: no_agendados, no_cerrados o all");

            return Command::FAILURE;
        }

        return $this->syncSource($sourceName, $dias);
    }

    /**
     * Resuelve el nombre corto a nombre completo de la fuente.
     */
    private function resolveSourceName(string $source): ?string
    {
        return match ($source) {
            'no_agendados' => 'sysgal_no_agendados',
            'no_cerrados' => 'sysgal_no_cerrados',
            default => null,
        };
    }

    /**
     * Sincroniza una fuente específica.
     */
    private function syncSource(string $sourceName, int $dias): int
    {
        $source = ExternalApiSource::where('name', $sourceName)->first();

        if (! $source) {
            $this->error("Fuente '{$sourceName}' no encontrada en la base de datos.");
            $this->line('Ejecute: php artisan db:seed --class=SysgalApiSourceSeeder');

            return Command::FAILURE;
        }

        if (! $source->is_active) {
            $this->warn("Fuente '{$source->display_name}' está desactivada.");

            return Command::FAILURE;
        }

        // Actualizar días de sync si se especificó diferente
        if ($dias !== 7) {
            $syncFilters = $source->sync_filters ?? [];
            $syncFilters['dias_atras'] = $dias;
            $source->sync_filters = $syncFilters;
            $source->save();
        }

        $this->info("=== Sincronizando: {$source->display_name} ===");
        $this->line("Endpoint: {$source->endpoint_url}");
        $this->line("Días: {$dias}");
        $this->newLine();

        $startTime = now();

        try {
            $resultado = $this->syncService->sync($source, 1);

            $duration = now()->diffInSeconds($startTime);

            $this->newLine();
            $this->info('Sincronización completada!');
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Lotes creados', count($resultado['lotes'])],
                    ['Total procesados', $resultado['total_prospectos']],
                    ['Nuevos', $resultado['nuevos']],
                    ['Actualizados', $resultado['actualizados']],
                    ['Omitidos (en flujo)', $resultado['omitidos_en_flujo']],
                    ['Duración', "{$duration} segundos"],
                ]
            );

            if (count($resultado['lotes']) > 0) {
                $this->newLine();
                $this->info('Lotes creados:');
                foreach ($resultado['lotes'] as $lote) {
                    $this->line("  - {$lote->nombre}: {$lote->total_registros} prospectos");
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Sincroniza todas las fuentes de Sysgal.
     */
    private function syncAll(int $dias): int
    {
        $this->info('=== Sincronizando todas las fuentes de Sysgal ===');
        $this->newLine();

        $sources = ['sysgal_no_agendados', 'sysgal_no_cerrados'];
        $results = [];

        foreach ($sources as $index => $sourceName) {
            $this->line(">>> Sincronizando {$sourceName} (".($index + 1).'/'.count($sources).')');

            $exitCode = $this->syncSource($sourceName, $dias);
            $results[$sourceName] = $exitCode === Command::SUCCESS ? 'OK' : 'ERROR';

            // Pausa entre fuentes
            if ($index < count($sources) - 1) {
                $this->line('Pausa de 10 segundos...');
                sleep(10);
            }
        }

        $this->newLine();
        $this->info('=== RESUMEN FINAL ===');
        $this->table(
            ['Fuente', 'Resultado'],
            collect($results)->map(fn ($r, $s) => [$s, $r])->values()->toArray()
        );

        return Command::SUCCESS;
    }

    /**
     * Muestra estadísticas de las fuentes Sysgal.
     */
    private function showStats(): int
    {
        $this->info('=== Estadísticas de fuentes Sysgal ===');
        $this->newLine();

        $sources = ExternalApiSource::where('name', 'like', 'sysgal_%')->get();

        if ($sources->isEmpty()) {
            $this->warn('No hay fuentes Sysgal configuradas.');
            $this->line('Ejecute: php artisan db:seed --class=SysgalApiSourceSeeder');

            return Command::FAILURE;
        }

        foreach ($sources as $source) {
            $this->info("--- {$source->display_name} ---");
            $this->table(
                ['Campo', 'Valor'],
                [
                    ['ID', $source->id],
                    ['Nombre', $source->name],
                    ['Endpoint', $source->endpoint_url],
                    ['Activo', $source->is_active ? 'Sí' : 'No'],
                    ['Último sync', $source->last_synced_at?->format('Y-m-d H:i:s') ?? 'Nunca'],
                    ['Registros último sync', $source->last_sync_count],
                    ['Error último sync', $source->last_sync_error ?? 'Ninguno'],
                    ['Días hacia atrás', $source->sync_filters['dias_atras'] ?? 7],
                ]
            );

            // Contar lotes y prospectos
            $lotes = $source->lotes()->count();
            $prospectos = \DB::table('prospectos')
                ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
                ->where('importaciones.external_api_source_id', $source->id)
                ->count();

            $this->line("  Lotes: {$lotes}");
            $this->line("  Prospectos: {$prospectos}");
            $this->newLine();
        }

        return Command::SUCCESS;
    }

    /**
     * Prueba la conexión a las fuentes Sysgal.
     */
    private function testConnections(): int
    {
        $this->info('=== Test de conexión a Sysgal ===');
        $this->newLine();

        $sources = ExternalApiSource::where('name', 'like', 'sysgal_%')->get();

        if ($sources->isEmpty()) {
            $this->warn('No hay fuentes Sysgal configuradas.');
            $this->line('Ejecute: php artisan db:seed --class=SysgalApiSourceSeeder');

            return Command::FAILURE;
        }

        foreach ($sources as $source) {
            $this->line("Probando {$source->display_name}...");

            $result = $this->syncService->testConnection($source);

            if ($result['success']) {
                $this->info("  OK - {$result['message']}");
                $this->line("  Registros encontrados: {$result['sample_count']} (Total: {$result['total']})");
            } else {
                $this->error("  ERROR - {$result['message']}");
            }

            $this->newLine();
        }

        return Command::SUCCESS;
    }
}
