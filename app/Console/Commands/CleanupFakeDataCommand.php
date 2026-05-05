<?php

namespace App\Console\Commands;

use App\Models\Importacion;
use App\Models\Lote;
use App\Models\Prospecto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando para limpiar datos fake creados por GrupoDeudaFakeDataSeeder.
 *
 * Ejecutar con: php artisan nurturing:cleanup-fake-data
 * Con --force para saltar confirmación: php artisan nurturing:cleanup-fake-data --force
 */
class CleanupFakeDataCommand extends Command
{
    protected $signature = 'nurturing:cleanup-fake-data 
                            {--force : Ejecutar sin pedir confirmación}
                            {--dry-run : Solo mostrar qué se borraría, sin borrar nada}';

    protected $description = 'Elimina los datos fake de prueba creados por GrupoDeudaFakeDataSeeder';

    /**
     * Nombres de lotes creados por el seeder.
     */
    private const FAKE_LOTE_NAMES = [
        'CONTRATOS_NUEVOS_TEST',
        'CUOTAS_VENCIDAS_TEST',
        'CUOTAS_POR_VENCER_TEST',
        'CLIENTES_INGRESO_TEST',
    ];

    public function handle(): int
    {
        $this->info('');
        $this->info('==============================================');
        $this->info('  CLEANUP: Datos Fake de Grupo Deudas');
        $this->info('==============================================');
        $this->info('');

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('🔍 MODO DRY-RUN: Solo se mostrará qué se borraría.');
            $this->info('');
        }

        // Buscar lotes de prueba
        $lotes = Lote::whereIn('nombre', self::FAKE_LOTE_NAMES)->get();

        if ($lotes->isEmpty()) {
            $this->info('✅ No se encontraron datos fake para limpiar.');
            $this->info('');
            return Command::SUCCESS;
        }

        // Mostrar resumen de lo que se va a borrar
        $this->showSummary($lotes);

        // Confirmar antes de borrar (a menos que sea --force o --dry-run)
        if (!$isDryRun && !$this->option('force')) {
            if (!$this->confirm('¿Estás seguro de que querés borrar estos datos?')) {
                $this->info('Operación cancelada.');
                return Command::SUCCESS;
            }
        }

        if ($isDryRun) {
            $this->info('');
            $this->info('🔍 Dry-run completado. No se borró nada.');
            return Command::SUCCESS;
        }

        // Ejecutar el borrado en una transacción
        try {
            DB::transaction(function () use ($lotes) {
                $this->performCleanup($lotes);
            });

            $this->info('');
            $this->info('✅ Datos fake eliminados exitosamente!');
            $this->info('');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('');
            $this->error('❌ Error al eliminar datos: ' . $e->getMessage());
            $this->error('');

            return Command::FAILURE;
        }
    }

    /**
     * Muestra un resumen de los datos que se van a borrar.
     */
    private function showSummary($lotes): void
    {
        $this->info('📋 Datos encontrados para eliminar:');
        $this->info('');

        $totalProspectos = 0;
        $totalImportaciones = 0;

        foreach ($lotes as $lote) {
            $importaciones = Importacion::where('lote_id', $lote->id)->get();
            $prospectos = Prospecto::whereIn('importacion_id', $importaciones->pluck('id'))->count();

            $this->info("  📦 Lote: {$lote->nombre} (ID: {$lote->id})");
            $this->info("     - Importaciones: {$importaciones->count()}");
            $this->info("     - Prospectos: {$prospectos}");
            $this->info('');

            $totalProspectos += $prospectos;
            $totalImportaciones += $importaciones->count();
        }

        $this->warn("  TOTAL:");
        $this->warn("  - Lotes: {$lotes->count()}");
        $this->warn("  - Importaciones: {$totalImportaciones}");
        $this->warn("  - Prospectos: {$totalProspectos}");
        $this->info('');
    }

    /**
     * Ejecuta el borrado de datos.
     */
    private function performCleanup($lotes): void
    {
        foreach ($lotes as $lote) {
            $this->info("🗑️  Procesando lote: {$lote->nombre}...");

            // Obtener importaciones del lote
            $importacionIds = Importacion::where('lote_id', $lote->id)->pluck('id');

            // Borrar prospectos
            $prospectosDeleted = Prospecto::whereIn('importacion_id', $importacionIds)->delete();
            $this->info("   ✓ Prospectos eliminados: {$prospectosDeleted}");

            // Borrar importaciones
            $importacionesDeleted = Importacion::where('lote_id', $lote->id)->delete();
            $this->info("   ✓ Importaciones eliminadas: {$importacionesDeleted}");

            // Borrar lote
            $lote->delete();
            $this->info("   ✓ Lote eliminado: {$lote->nombre}");
        }
    }
}
