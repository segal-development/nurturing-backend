<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\ImportacionRecoveryService;
use Illuminate\Console\Command;

/**
 * Comando para recuperar importaciones "stuck".
 * 
 * Se ejecuta automáticamente al inicio de cada queue worker en Cloud Run
 * para garantizar que ninguna importación quede abandonada.
 * 
 * Uso:
 *   php artisan importaciones:recover          # Ejecutar recovery
 *   php artisan importaciones:recover --stats  # Solo mostrar estadísticas
 *   php artisan importaciones:recover --dry-run # Mostrar qué haría sin ejecutar
 */
class RecoverStuckImportations extends Command
{
    protected $signature = 'importaciones:recover 
                            {--stats : Solo mostrar estadísticas sin recuperar}
                            {--dry-run : Mostrar qué importaciones se recuperarían sin ejecutar}';

    protected $description = 'Detecta y recupera importaciones que quedaron en estado "procesando" sin updates recientes';

    public function handle(): int
    {
        $service = new ImportacionRecoveryService();

        // Modo estadísticas
        if ($this->option('stats')) {
            return $this->showStats($service);
        }

        // Modo dry-run
        if ($this->option('dry-run')) {
            return $this->dryRun($service);
        }

        // Modo normal: ejecutar recovery
        return $this->executeRecovery($service);
    }

    private function showStats(ImportacionRecoveryService $service): int
    {
        $stats = $service->getHealthStats();

        $this->info('=== Estadísticas de Importaciones ===');
        $this->newLine();

        $this->info('Por estado:');
        foreach ($stats['importaciones_por_estado'] as $estado => $count) {
            $this->line("  - {$estado}: {$count}");
        }
        $this->newLine();

        $stuckStyle = $stats['stuck_count'] > 0 ? 'error' : 'info';
        $this->line("Importaciones stuck (>{$stats['threshold_minutes']} min): <{$stuckStyle}>{$stats['stuck_count']}</{$stuckStyle}>");
        $this->line("Jobs de importación en cola: {$stats['jobs_in_queue']}");
        $this->newLine();

        return self::SUCCESS;
    }

    private function dryRun(ImportacionRecoveryService $service): int
    {
        $this->info('=== Modo Dry-Run ===');
        $this->warn('No se ejecutará ninguna acción.');
        $this->newLine();

        // Obtener importaciones stuck sin procesarlas
        $stats = $service->getHealthStats();
        
        if ($stats['stuck_count'] === 0) {
            $this->info('No hay importaciones stuck para recuperar.');
            return self::SUCCESS;
        }

        $this->warn("Se encontraron {$stats['stuck_count']} importación(es) stuck.");
        $this->line('Ejecute sin --dry-run para recuperarlas.');

        return self::SUCCESS;
    }

    private function executeRecovery(ImportacionRecoveryService $service): int
    {
        $this->info('=== Ejecutando Recovery de Importaciones ===');
        $this->newLine();

        $result = $service->recoverStuckImportations();

        if ($result['recovered'] === 0) {
            $this->info('No se encontraron importaciones stuck para recuperar.');
            return self::SUCCESS;
        }

        $this->info("Recuperadas: {$result['recovered']} importación(es)");
        
        foreach ($result['importaciones'] as $id) {
            $this->line("  - Importación #{$id} re-encolada");
        }

        $this->newLine();
        $this->info('Recovery completado exitosamente.');

        return self::SUCCESS;
    }
}
