<?php

namespace App\Console\Commands;

use App\Models\ExternalApiSource;
use App\Services\GrupoDeudaApiSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando para sincronizar contratos nuevos desde la API de Grupo Deudas.
 *
 * Uso:
 *   php artisan sync:grupo-deuda                # Sync incremental
 *   php artisan sync:grupo-deuda --test         # Test de conexión
 *   php artisan sync:grupo-deuda --stats        # Ver estadísticas
 *   php artisan sync:grupo-deuda --horas=48     # Últimas 48 horas (ignora last_synced_at)
 */
class SyncGrupoDeudaCommand extends Command
{
    protected $signature = 'sync:grupo-deuda
                            {--test : Solo probar conexión sin sincronizar}
                            {--stats : Mostrar estadísticas de la fuente}
                            {--horas= : Forzar sync de las últimas N horas (ignora last_synced_at)}';

    protected $description = 'Sincroniza contratos nuevos desde la API de Grupo Deudas';

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
        $source = $this->getGrupoDeudaSource();

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
        $this->line('Desde: '.($source->last_synced_at?->format('Y-m-d H:i:s') ?? 'últimas 24 horas'));
        $this->line('Hasta: '.now()->format('Y-m-d H:i:s'));
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

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Muestra estadísticas de la fuente.
     */
    private function showStats(): int
    {
        $this->info('=== Estadísticas de Grupo Deudas ===');
        $this->newLine();

        $source = $this->getGrupoDeudaSource();

        if (! $source) {
            return Command::FAILURE;
        }

        $this->info("--- {$source->display_name} ---");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['ID', $source->id],
                ['Nombre', $source->name],
                ['Endpoint', $source->endpoint_url],
                ['Activo', $source->is_active ? 'Sí' : 'No'],
                ['Último sync', $source->last_synced_at?->format('Y-m-d H:i:s') ?? 'Nunca'],
                ['Registros último sync', $source->last_sync_count ?? 0],
                ['Error último sync', $source->last_sync_error ?? 'Ninguno'],
                ['Frecuencia', $source->sync_frequency],
            ]
        );

        // Contar lotes y prospectos
        $lotes = $source->lotes()->count();
        $prospectos = DB::table('prospectos')
            ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
            ->where('importaciones.external_api_source_id', $source->id)
            ->count();

        $this->newLine();
        $this->line("Lotes: {$lotes}");
        $this->line("Prospectos totales: {$prospectos}");

        // Últimos prospectos importados
        if ($prospectos > 0) {
            $this->newLine();
            $this->info('Últimos 5 contratos importados:');

            $ultimos = DB::table('prospectos')
                ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
                ->where('importaciones.external_api_source_id', $source->id)
                ->select('prospectos.nombre', 'prospectos.email', 'prospectos.monto_deuda', 'prospectos.created_at')
                ->orderBy('prospectos.created_at', 'desc')
                ->limit(5)
                ->get();

            $this->table(
                ['Nombre', 'Email', 'Monto', 'Fecha'],
                $ultimos->map(fn ($p) => [
                    $p->nombre,
                    $p->email ?? 'N/A',
                    '$'.number_format($p->monto_deuda ?? 0, 0, ',', '.'),
                    $p->created_at,
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
        $this->info('=== Test de conexión a Grupo Deudas ===');
        $this->newLine();

        $source = $this->getGrupoDeudaSource();

        if (! $source) {
            return Command::FAILURE;
        }

        $this->line("Probando {$source->display_name}...");
        $this->line("Endpoint: {$source->endpoint_url}");
        $this->newLine();

        $result = $this->syncService->testConnection($source);

        if ($result['success']) {
            $this->info("✓ {$result['message']}");
            $this->line("Contratos encontrados (últimas 24h): {$result['sample_count']}");
            $this->line("Total reportado por API: {$result['total']}");
        } else {
            $this->error("✗ {$result['message']}");
        }

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Obtiene la fuente de Grupo Deudas.
     */
    private function getGrupoDeudaSource(): ?ExternalApiSource
    {
        $source = ExternalApiSource::where('name', 'grupo_deuda_contratos')
            ->first();

        if (! $source) {
            $this->error('No se encontró fuente de Grupo Deudas.');
            $this->line('Ejecute: php artisan db:seed --class=GrupoDeudaApiSourceSeeder');

            return null;
        }

        if (! $source->is_active) {
            $this->warn('La fuente de Grupo Deudas está inactiva.');
        }

        return $source;
    }
}
