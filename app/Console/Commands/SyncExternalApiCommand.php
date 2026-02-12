<?php

namespace App\Console\Commands;

use App\Models\ExternalApiSource;
use App\Services\ExternalApiSyncService;
use Illuminate\Console\Command;

/**
 * Comando para sincronizar prospectos desde APIs externas.
 *
 * Uso:
 *   php artisan sync:external-api informes_comerciales --status=form
 *   php artisan sync:external-api informes_comerciales --all
 *   php artisan sync:external-api informes_comerciales --stats
 *   php artisan sync:external-api informes_comerciales --clean
 */
class SyncExternalApiCommand extends Command
{
    protected $signature = 'sync:external-api 
                            {source : Nombre de la fuente (ej: informes_comerciales)}
                            {--status= : Sync solo un status específico (ej: form, iniciado)}
                            {--all : Sync todos los status uno por uno}
                            {--stats : Mostrar estadísticas de la fuente}
                            {--clean : Eliminar todos los datos de prueba de esta fuente}';

    protected $description = 'Sincroniza prospectos desde una API externa';

    public function handle(): int
    {
        $sourceName = $this->argument('source');
        $source = ExternalApiSource::where('name', $sourceName)->first();

        if (! $source) {
            $this->error("Fuente '{$sourceName}' no encontrada.");
            $this->line('Fuentes disponibles:');
            ExternalApiSource::all()->each(fn ($s) => $this->line("  - {$s->name} ({$s->display_name})"));

            return Command::FAILURE;
        }

        if ($this->option('stats')) {
            return $this->showStats($source);
        }

        if ($this->option('clean')) {
            return $this->cleanData($source);
        }

        if ($this->option('status')) {
            return $this->syncSingleStatus($source, $this->option('status'));
        }

        if ($this->option('all')) {
            return $this->syncAllStatus($source);
        }

        $this->error('Debe especificar una opción: --status=X, --all, --stats o --clean');

        return Command::FAILURE;
    }

    /**
     * Muestra estadísticas de la fuente.
     */
    private function showStats(ExternalApiSource $source): int
    {
        $this->info("=== Estadísticas: {$source->display_name} ===");
        $this->newLine();

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
                ['Campo clasificación', $source->clasificacion_field ?? 'N/A'],
                ['Valores clasificación', implode(', ', $source->clasificacion_values ?? [])],
            ]
        );

        // Contar lotes y prospectos
        $lotes = $source->lotes()->count();
        $prospectos = \DB::table('prospectos')
            ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
            ->where('importaciones.external_api_source_id', $source->id)
            ->count();

        $this->newLine();
        $this->info('Datos actuales:');
        $this->line("  - Lotes: {$lotes}");
        $this->line("  - Prospectos: {$prospectos}");

        if ($lotes > 0) {
            $this->newLine();
            $this->info('Lotes por clasificación:');
            $source->lotes()->get()->each(function ($lote) {
                $this->line("  - {$lote->nombre}: {$lote->total_registros} prospectos ({$lote->estado})");
            });
        }

        return Command::SUCCESS;
    }

    /**
     * Elimina todos los datos de prueba de esta fuente.
     */
    private function cleanData(ExternalApiSource $source): int
    {
        $this->warn("=== LIMPIEZA DE DATOS: {$source->display_name} ===");
        $this->newLine();

        // Contar lo que se va a eliminar
        $lotes = $source->lotes()->count();
        $importaciones = \App\Models\Importacion::where('external_api_source_id', $source->id)->count();
        $prospectos = \DB::table('prospectos')
            ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
            ->where('importaciones.external_api_source_id', $source->id)
            ->count();

        $this->line('Se eliminarán:');
        $this->line("  - {$prospectos} prospectos");
        $this->line("  - {$importaciones} importaciones");
        $this->line("  - {$lotes} lotes");
        $this->newLine();

        if (! $this->confirm('¿Estás seguro de que querés eliminar todos estos datos?')) {
            $this->info('Operación cancelada.');

            return Command::SUCCESS;
        }

        $this->info('Eliminando datos...');

        // 1. Eliminar prospectos
        $this->line('  Eliminando prospectos...');
        \DB::table('prospectos')
            ->whereIn('importacion_id', function ($query) use ($source) {
                $query->select('id')
                    ->from('importaciones')
                    ->where('external_api_source_id', $source->id);
            })
            ->delete();

        // 2. Eliminar importaciones
        $this->line('  Eliminando importaciones...');
        \App\Models\Importacion::where('external_api_source_id', $source->id)->delete();

        // 3. Eliminar lotes
        $this->line('  Eliminando lotes...');
        $source->lotes()->delete();

        // 4. Resetear fuente
        $this->line('  Reseteando fuente...');
        $source->update([
            'last_synced_at' => null,
            'last_sync_count' => 0,
            'last_sync_error' => null,
        ]);

        $this->newLine();
        $this->info('✓ Limpieza completada.');

        return Command::SUCCESS;
    }

    /**
     * Sincroniza un solo status.
     */
    private function syncSingleStatus(ExternalApiSource $source, string $status): int
    {
        $this->info("=== Sync Status: {$status} ===");
        $this->newLine();

        // Validar que el status existe en la configuración
        $validStatus = $source->clasificacion_values ?? [];
        if (! empty($validStatus) && ! in_array($status, $validStatus)) {
            $this->error("Status '{$status}' no es válido para esta fuente.");
            $this->line('Status válidos: '.implode(', ', $validStatus));

            return Command::FAILURE;
        }

        $this->line("Fuente: {$source->display_name}");
        $this->line("Status: {$status}");
        $this->line('Delay entre páginas: 2 segundos');
        $this->newLine();

        $startTime = now();

        try {
            $service = new ExternalApiSyncService;

            // Usar reflection para llamar al método privado
            $reflection = new \ReflectionClass($service);

            // Cargar cache de prospectos
            $cacheMethod = $reflection->getMethod('loadProspectosEnFlujoActivo');
            $cacheMethod->setAccessible(true);

            $cacheProperty = $reflection->getProperty('cacheService');
            $cacheProperty->setAccessible(true);
            $cacheService = $cacheProperty->getValue($service);
            $cacheService->loadExistingProspectos();

            $cacheMethod->invoke($service);

            // Fetch del status
            $this->line('Obteniendo datos de la API...');
            $fetchMethod = $reflection->getMethod('fetchFromApiByStatus');
            $fetchMethod->setAccessible(true);
            $data = $fetchMethod->invoke($service, $source, $status);

            $count = count($data);
            $this->info("  → {$count} registros obtenidos");

            if (empty($data)) {
                $this->warn('No hay registros para este status.');

                return Command::SUCCESS;
            }

            // Procesar
            $this->line('Procesando prospectos...');

            $lote = $source->obtenerOCrearLote($status, 1);

            $importacion = \App\Models\Importacion::create([
                'lote_id' => $lote->id,
                'external_api_source_id' => $source->id,
                'nombre_archivo' => "sync_{$source->name}_{$status}_".now()->format('Y-m-d_His'),
                'ruta_archivo' => null,
                'origen' => $source->display_name,
                'total_registros' => count($data),
                'registros_exitosos' => 0,
                'registros_fallidos' => 0,
                'user_id' => 1,
                'estado' => 'procesando',
                'fecha_importacion' => now(),
                'metadata' => [
                    'source_name' => $source->name,
                    'status' => $status,
                    'synced_at' => now()->toISOString(),
                ],
            ]);

            // Procesar prospectos
            $processMethod = $reflection->getMethod('processProspectos');
            $processMethod->setAccessible(true);

            $loadTiposMethod = $reflection->getMethod('loadTiposProspecto');
            $loadTiposMethod->setAccessible(true);
            $tiposProspecto = $loadTiposMethod->invoke($service);

            $resultado = $processMethod->invoke(
                $service,
                $importacion,
                $data,
                $source->getFieldMappingWithDefaults(),
                $tiposProspecto,
                $source
            );

            // Finalizar
            $finalizeMethod = $reflection->getMethod('finalizeImportacion');
            $finalizeMethod->setAccessible(true);
            $finalizeMethod->invoke($service, $importacion, $resultado);

            $lote->recalcularTotales();
            $lote->update(['estado' => 'completado']);

            // Actualizar fuente
            $source->markAsSynced($resultado['exitosos']);

            $duration = now()->diffInSeconds($startTime);

            $this->newLine();
            $this->info('✓ Sync completado!');
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Lote', $lote->nombre],
                    ['Total procesados', $resultado['exitosos']],
                    ['Nuevos', $resultado['nuevos']],
                    ['Actualizados', $resultado['actualizados']],
                    ['Omitidos (en flujo)', $resultado['omitidos_en_flujo']],
                    ['Fallidos', $resultado['fallidos']],
                    ['Duración', "{$duration} segundos"],
                ]
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Sincroniza todos los status uno por uno.
     */
    private function syncAllStatus(ExternalApiSource $source): int
    {
        $this->info("=== Sync Completo: {$source->display_name} ===");
        $this->newLine();

        $clasificacionValues = $source->clasificacion_values ?? [];

        if (empty($clasificacionValues)) {
            $this->error('Esta fuente no tiene valores de clasificación configurados.');

            return Command::FAILURE;
        }

        // Excluir "pagado"
        $statusToSync = array_filter($clasificacionValues, fn ($s) => $s !== 'pagado');

        $this->line('Status a sincronizar: '.implode(', ', $statusToSync));
        $this->line('Pausa entre status: 60 segundos');
        $this->newLine();

        if (! $this->confirm('¿Continuar con el sync completo?')) {
            return Command::SUCCESS;
        }

        $totalStart = now();
        $results = [];

        foreach ($statusToSync as $index => $status) {
            $this->newLine();
            $this->info(">>> Sincronizando: {$status} (".($index + 1).'/'.count($statusToSync).')');

            $exitCode = $this->syncSingleStatus($source, $status);

            $results[$status] = $exitCode === Command::SUCCESS ? 'OK' : 'ERROR';

            // Pausa entre status (excepto el último)
            if ($index < count($statusToSync) - 1) {
                $this->line('Pausa de 60 segundos antes del siguiente status...');
                sleep(60);
            }
        }

        $totalDuration = now()->diffInMinutes($totalStart);

        $this->newLine();
        $this->info('=== RESUMEN FINAL ===');
        $this->table(
            ['Status', 'Resultado'],
            collect($results)->map(fn ($r, $s) => [$s, $r])->values()->toArray()
        );
        $this->line("Duración total: {$totalDuration} minutos");

        return Command::SUCCESS;
    }
}
