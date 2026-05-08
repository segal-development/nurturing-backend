<?php

namespace App\Console\Commands;

use App\Models\ExternalApiSource;
use App\Services\GrupoDeudaApiSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando para sincronizar prospectos desde la API de Grupo Deudas.
 *
 * Uso:
 *   php artisan sync:grupo-deuda                              # Sync contratos (default)
 *   php artisan sync:grupo-deuda --endpoint=contratos         # Sync contratos nuevos
 *   php artisan sync:grupo-deuda --endpoint=cuotas-vencer     # Sync cuotas por vencer
 *   php artisan sync:grupo-deuda --endpoint=cuotas-vencidas   # Sync cuotas vencidas
 *   php artisan sync:grupo-deuda --endpoint=clientes-ingreso  # Sync clientes ingreso
 *   php artisan sync:grupo-deuda --test                       # Test conexión (contratos)
 *   php artisan sync:grupo-deuda --test --endpoint=cuotas-vencer  # Test endpoint específico
 *   php artisan sync:grupo-deuda --stats                      # Ver estadísticas
 *   php artisan sync:grupo-deuda --horas=48                   # Últimas 48 horas
 */
class SyncGrupoDeudaCommand extends Command
{
    protected $signature = 'sync:grupo-deuda
                            {--endpoint= : Endpoint a sincronizar (contratos, cuotas-vencer, cuotas-vencidas, clientes-ingreso)}
                            {--test : Solo probar conexión sin sincronizar}
                            {--stats : Mostrar estadísticas de la fuente}
                            {--horas= : Forzar sync de las últimas N horas (ignora last_synced_at)}';

    protected $description = 'Sincroniza prospectos desde la API de Grupo Deudas';

    private GrupoDeudaApiSyncService $syncService;

    public function __construct()
    {
        parent::__construct();
        $this->syncService = new GrupoDeudaApiSyncService;
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
     * Sincroniza la fuente Grupo Deudas.
     */
    private function syncSource(): int
    {
        $endpoint = $this->option('endpoint') ?? GrupoDeudaApiSyncService::ENDPOINT_CONTRATOS_NUEVOS;
        $source = $this->getGrupoDeudaSourceByEndpoint($endpoint);

        if (! $source) {
            return Command::FAILURE;
        }

        // Si se especifica --horas, resetear last_synced_at temporalmente
        $horas = $this->option('horas');
        $originalLastSyncedAt = $source->last_synced_at;

        if ($horas !== null) {
            $source->last_synced_at = now()->subHours((int) $horas);
            $this->line("Forzando sync de las últimas {$horas} horas");
        }

        $this->info("=== Sincronizando: {$source->display_name} ===");
        $this->line("Endpoint: {$endpoint}");

        // Los endpoints de cuotas/clientes siempre usan el día actual
        if ($endpoint === GrupoDeudaApiSyncService::ENDPOINT_CONTRATOS_NUEVOS) {
            $this->line('Desde: '.($source->last_synced_at?->format('Y-m-d H:i:s') ?? 'últimas 24 horas'));
            $this->line('Hasta: '.now()->format('Y-m-d H:i:s'));
        } else {
            $this->line('Desde: '.now()->startOfDay()->format('Y-m-d H:i:s'));
            $this->line('Hasta: '.now()->endOfDay()->format('Y-m-d H:i:s'));
        }
        $this->newLine();

        $startTime = now();

        try {
            $resultado = $this->executeSync($endpoint, $source);

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

            // Restaurar last_synced_at si fue modificado
            if ($horas !== null) {
                $source->last_synced_at = $originalLastSyncedAt;
            }

            // Disparar asignación automática solo para contratos nuevos (sync horario)
            if ($endpoint === GrupoDeudaApiSyncService::ENDPOINT_CONTRATOS_NUEVOS && $resultado['nuevos'] > 0) {
                $this->info('Disparando asignación automática a flujos...');
                \App\Jobs\AsignarNuevosProspectosAFlujoJob::dispatch();
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Ejecuta la sincronización según el endpoint.
     */
    private function executeSync(string $endpoint, ExternalApiSource $source): array
    {
        return match ($endpoint) {
            GrupoDeudaApiSyncService::ENDPOINT_CONTRATOS_NUEVOS => $this->syncService->sync($source, 1),
            GrupoDeudaApiSyncService::ENDPOINT_CUOTAS_POR_VENCER => $this->syncService->syncCuotasPorVencer($source, 1),
            GrupoDeudaApiSyncService::ENDPOINT_CUOTAS_VENCIDAS => $this->syncService->syncCuotasVencidas($source, 1),
            GrupoDeudaApiSyncService::ENDPOINT_CLIENTES_INGRESO => $this->syncService->syncClientesPorFechaIngreso($source, 1),
            default => throw new \InvalidArgumentException("Endpoint desconocido: {$endpoint}"),
        };
    }

    /**
     * Muestra estadísticas de la fuente.
     */
    private function showStats(): int
    {
        $this->info('=== Estadísticas de Grupo Deudas ===');
        $this->newLine();

        $sources = $this->getAllGrupoDeudaSources();

        if ($sources->isEmpty()) {
            return Command::FAILURE;
        }

        // Tabla resumen de todas las sources
        $this->info('--- Resumen de Sources ---');
        $summaryData = $sources->map(fn ($s) => [
            $s->id,
            $s->display_name,
            $s->is_active ? '✓' : '✗',
            $s->last_synced_at?->format('Y-m-d H:i:s') ?? 'Nunca',
            $s->last_sync_count ?? 0,
            $s->last_sync_error ? '⚠ Error' : '✓ OK',
        ])->toArray();

        $this->table(
            ['ID', 'Nombre', 'Activo', 'Último Sync', 'Count', 'Estado'],
            $summaryData
        );

        // Totales
        $totalLotes = 0;
        $totalProspectos = 0;

        foreach ($sources as $source) {
            $totalLotes += $source->lotes()->count();
            $totalProspectos += DB::table('prospectos')
                ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
                ->where('importaciones.external_api_source_id', $source->id)
                ->count();
        }

        $this->newLine();
        $this->line("Total lotes: {$totalLotes}");
        $this->line("Total prospectos: {$totalProspectos}");

        // Mostrar errores si hay
        $sourcesConError = $sources->filter(fn ($s) => $s->last_sync_error !== null);
        if ($sourcesConError->isNotEmpty()) {
            $this->newLine();
            $this->warn('⚠ Sources con errores:');
            foreach ($sourcesConError as $source) {
                $this->error("  [{$source->id}] {$source->display_name}: {$source->last_sync_error}");
            }
        }

        // Últimos prospectos importados (de todas las sources)
        if ($totalProspectos > 0) {
            $this->newLine();
            $this->info('Últimos 5 prospectos importados (todas las sources):');

            $sourceIds = $sources->pluck('id');
            $ultimos = DB::table('prospectos')
                ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
                ->join('external_api_sources', 'importaciones.external_api_source_id', '=', 'external_api_sources.id')
                ->whereIn('importaciones.external_api_source_id', $sourceIds)
                ->select('prospectos.nombre', 'prospectos.email', 'prospectos.monto_deuda', 'prospectos.created_at', 'external_api_sources.display_name as source')
                ->orderBy('prospectos.created_at', 'desc')
                ->limit(5)
                ->get();

            $this->table(
                ['Nombre', 'Email', 'Monto', 'Fecha', 'Source'],
                $ultimos->map(fn ($p) => [
                    $p->nombre,
                    $p->email ?? 'N/A',
                    '$'.number_format($p->monto_deuda ?? 0, 0, ',', '.'),
                    $p->created_at,
                    $p->source,
                ])->toArray()
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Prueba la conexión a Grupo Deudas.
     */
    private function testConnection(): int
    {
        $endpoint = $this->option('endpoint') ?? GrupoDeudaApiSyncService::ENDPOINT_CONTRATOS_NUEVOS;

        $this->info('=== Test de conexión a Grupo Deudas ===');
        $this->newLine();

        $source = $this->getGrupoDeudaSourceByEndpoint($endpoint);

        if (! $source) {
            return Command::FAILURE;
        }

        $this->line("Probando endpoint: {$endpoint}");
        $this->line("Fuente: {$source->display_name}");
        $this->newLine();

        $result = $this->syncService->testEndpoint($endpoint, $source);

        if ($result['success']) {
            $this->info("✓ {$result['message']}");
            $this->line("Endpoint probado: {$result['endpoint']}");
            $this->line("Registros encontrados: {$result['sample_count']}");
            $this->line("Total reportado por API: {$result['total']}");
        } else {
            $this->error("✗ {$result['message']}");
        }

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Obtiene la fuente de Grupo Deudas según el endpoint.
     */
    private function getGrupoDeudaSourceByEndpoint(string $endpoint): ?ExternalApiSource
    {
        $sourceMap = [
            GrupoDeudaApiSyncService::ENDPOINT_CONTRATOS_NUEVOS => 'grupo_deuda_contratos',
            GrupoDeudaApiSyncService::ENDPOINT_CUOTAS_POR_VENCER => 'grupo_deuda_cuotas_por_vencer',
            GrupoDeudaApiSyncService::ENDPOINT_CUOTAS_VENCIDAS => 'grupo_deuda_cuotas_vencidas',
            GrupoDeudaApiSyncService::ENDPOINT_CLIENTES_INGRESO => 'grupo_deuda_clientes_ingreso',
        ];

        $sourceName = $sourceMap[$endpoint] ?? null;

        if ($sourceName === null) {
            $this->error("Endpoint desconocido: {$endpoint}");
            $this->line('Endpoints válidos: contratos, cuotas-vencer, cuotas-vencidas, clientes-ingreso');

            return null;
        }

        $source = ExternalApiSource::where('name', $sourceName)->first();

        if (! $source) {
            $this->error("No se encontró fuente: {$sourceName}");
            $this->line('Ejecute: php artisan db:seed --class=GrupoDeudaApiSourceSeeder');

            return null;
        }

        if (! $source->is_active) {
            $this->warn("La fuente {$source->display_name} está inactiva.");
        }

        return $source;
    }

    /**
     * Obtiene todas las fuentes de Grupo Deudas.
     */
    private function getAllGrupoDeudaSources(): \Illuminate\Database\Eloquent\Collection
    {
        $sources = ExternalApiSource::where('name', 'like', 'grupo_deuda%')
            ->orderBy('id')
            ->get();

        if ($sources->isEmpty()) {
            $this->error('No se encontraron fuentes de Grupo Deudas.');
            $this->line('Ejecute: php artisan db:seed --class=GrupoDeudaApiSourceSeeder');
        }

        return $sources;
    }
}
