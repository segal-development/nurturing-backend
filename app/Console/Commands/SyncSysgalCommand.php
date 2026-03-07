<?php

namespace App\Console\Commands;

use App\Models\ExternalApiSource;
use App\Services\SysgalApiSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando para sincronizar prospectos desde la API de Sysgal.
 *
 * Uso:
 *   php artisan sync:sysgal                        # Sync todos los endpoints
 *   php artisan sync:sysgal no_agendados           # Sync solo prospectos no agendados
 *   php artisan sync:sysgal no_cerrados            # Sync solo agendas no cerradas
 *   php artisan sync:sysgal --dias=14              # Últimos 14 días
 *   php artisan sync:sysgal --test                 # Test de conexión
 *   php artisan sync:sysgal --stats                # Ver estadísticas
 */
class SyncSysgalCommand extends Command
{
    protected $signature = 'sync:sysgal
                            {endpoint? : Endpoint específico a sincronizar (no_agendados, no_cerrados)}
                            {--dias=7 : Días hacia atrás a sincronizar}
                            {--test : Solo probar conexión sin sincronizar}
                            {--stats : Mostrar estadísticas de la fuente}';

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
            return $this->testConnection();
        }

        return $this->syncSource();
    }

    /**
     * Sincroniza la fuente Sysgal.
     */
    private function syncSource(): int
    {
        $source = $this->getSysgalSource();

        if (! $source) {
            return Command::FAILURE;
        }

        $endpointName = $this->argument('endpoint');
        $dias = (int) $this->option('dias');

        // Validar endpoint si se especificó
        if ($endpointName !== null && ! in_array($endpointName, ['no_agendados', 'no_cerrados'])) {
            $this->error("Endpoint '{$endpointName}' no reconocido. Use: no_agendados o no_cerrados");

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

        if ($endpointName) {
            $this->line("Endpoint: {$endpointName}");
        } else {
            $endpoints = $source->sync_filters['endpoints'] ?? [];
            $this->line('Endpoints: '.count($endpoints).' configurados');
        }

        $this->line("Días: {$dias}");
        $this->newLine();

        $startTime = now();

        try {
            $resultado = $this->syncService->sync($source, 1, $endpointName);

            $duration = now()->diffInSeconds($startTime);

            $this->newLine();
            $this->info('Sincronización completada!');
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Lotes procesados', count($resultado['lotes'])],
                    ['Total procesados', $resultado['total_prospectos']],
                    ['Nuevos', $resultado['nuevos']],
                    ['Actualizados', $resultado['actualizados']],
                    ['Omitidos (en flujo)', $resultado['omitidos_en_flujo']],
                    ['Duración', "{$duration} segundos"],
                ]
            );

            if (count($resultado['lotes']) > 0) {
                $this->newLine();
                $this->info('Lotes actualizados:');
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
     * Muestra estadísticas de la fuente Sysgal.
     */
    private function showStats(): int
    {
        $this->info('=== Estadísticas de Sysgal ===');
        $this->newLine();

        $source = $this->getSysgalSource();

        if (! $source) {
            return Command::FAILURE;
        }

        $this->info("--- {$source->display_name} ---");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['ID', $source->id],
                ['Nombre', $source->name],
                ['Activo', $source->is_active ? 'Sí' : 'No'],
                ['Último sync', $source->last_synced_at?->format('Y-m-d H:i:s') ?? 'Nunca'],
                ['Registros último sync', $source->last_sync_count],
                ['Error último sync', $source->last_sync_error ?? 'Ninguno'],
                ['Días hacia atrás', $source->sync_filters['dias_atras'] ?? 7],
            ]
        );

        // Mostrar endpoints configurados
        $endpoints = $source->sync_filters['endpoints'] ?? [];
        if (! empty($endpoints)) {
            $this->newLine();
            $this->info('Endpoints configurados:');
            foreach ($endpoints as $endpoint) {
                $this->line("  - {$endpoint['name']}: {$endpoint['display_name']}");
                $this->line("    URL: {$endpoint['url']}");
            }
        }

        // Contar lotes y prospectos
        $lotes = $source->lotes()->count();
        $prospectos = DB::table('prospectos')
            ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
            ->where('importaciones.external_api_source_id', $source->id)
            ->count();

        $this->newLine();
        $this->line("Lotes: {$lotes}");
        $this->line("Prospectos totales: {$prospectos}");

        // Estadísticas por nivel de deuda
        $this->newLine();
        $this->info('Prospectos por nivel de deuda:');
        $niveles = DB::table('prospectos')
            ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
            ->where('importaciones.external_api_source_id', $source->id)
            ->selectRaw("prospectos.metadata->>'nivel_deuda' as nivel, COUNT(*) as count")
            ->groupByRaw("prospectos.metadata->>'nivel_deuda'")
            ->pluck('count', 'nivel')
            ->toArray();

        foreach ($niveles as $nivel => $count) {
            $nivel = $nivel ?: 'sin_informacion';
            $this->line("  - {$nivel}: {$count}");
        }

        return Command::SUCCESS;
    }

    /**
     * Prueba la conexión a Sysgal.
     */
    private function testConnection(): int
    {
        $this->info('=== Test de conexión a Sysgal ===');
        $this->newLine();

        $source = $this->getSysgalSource();

        if (! $source) {
            return Command::FAILURE;
        }

        $this->line("Probando {$source->display_name}...");
        $this->newLine();

        $result = $this->syncService->testConnection($source);

        if ($result['success']) {
            $this->info("✓ {$result['message']}");

            if (isset($result['endpoints'])) {
                $this->newLine();
                foreach ($result['endpoints'] as $name => $endpointResult) {
                    if ($endpointResult['success']) {
                        $this->info("  ✓ {$name}: {$endpointResult['sample_count']} registros (Total: {$endpointResult['total']})");
                    } else {
                        $this->error("  ✗ {$name}: {$endpointResult['message']}");
                    }
                }
            }

            if (isset($result['total_records'])) {
                $this->newLine();
                $this->line("Total registros encontrados: {$result['total_records']}");
            }
        } else {
            $this->error("✗ {$result['message']}");

            if (isset($result['endpoints'])) {
                $this->newLine();
                foreach ($result['endpoints'] as $name => $endpointResult) {
                    $status = $endpointResult['success'] ? '✓' : '✗';
                    $this->line("  {$status} {$name}: {$endpointResult['message']}");
                }
            }
        }

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Obtiene la fuente Sysgal (unificada o legacy).
     */
    private function getSysgalSource(): ?ExternalApiSource
    {
        // Primero buscar la fuente unificada
        $source = ExternalApiSource::where('name', 'sysgal')
            ->where('is_active', true)
            ->first();

        if ($source) {
            return $source;
        }

        // Fallback: buscar fuentes legacy
        $source = ExternalApiSource::where('name', 'like', 'sysgal_%')
            ->where('is_active', true)
            ->first();

        if (! $source) {
            $this->error('No se encontró fuente Sysgal activa.');
            $this->line('Ejecute: php artisan db:seed --class=SysgalApiSourceSeeder');
            $this->line('O ejecute la migración: php artisan migrate');

            return null;
        }

        return $source;
    }
}
