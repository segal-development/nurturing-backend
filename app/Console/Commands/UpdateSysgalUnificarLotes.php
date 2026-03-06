<?php

namespace App\Console\Commands;

use App\Models\ExternalApiSource;
use Illuminate\Console\Command;

/**
 * Comando para activar la unificación de lotes en las fuentes de Sysgal.
 *
 * Esto hace que todos los prospectos de cada fuente vayan a un solo lote
 * en vez de crear múltiples lotes por clasificación (Etapa, Estado_Final).
 *
 * La clasificación original se sigue guardando en metadata del prospecto.
 *
 * Ejecutar con: php artisan sysgal:unificar-lotes
 */
class UpdateSysgalUnificarLotes extends Command
{
    protected $signature = 'sysgal:unificar-lotes
                            {--dry-run : Mostrar cambios sin aplicarlos}
                            {--revert : Desactivar la unificación}';

    protected $description = 'Activa/desactiva la unificación de lotes para fuentes de Sysgal';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $revert = $this->option('revert');

        $this->info($revert ? 'Desactivando unificación de lotes...' : 'Activando unificación de lotes...');

        if ($dryRun) {
            $this->warn('⚠️  Modo dry-run: no se aplicarán cambios');
        }

        // Buscar fuentes de Sysgal
        $sources = ExternalApiSource::where('name', 'like', 'sysgal%')->get();

        if ($sources->isEmpty()) {
            $this->error('No se encontraron fuentes de Sysgal');

            return Command::FAILURE;
        }

        $this->table(
            ['ID', 'Nombre', 'Unificar Actual', 'Unificar Nuevo', 'Prefix Actual', 'Prefix Nuevo'],
            $sources->map(function ($source) use ($revert) {
                $syncFilters = $source->sync_filters ?? [];
                $unificarActual = $syncFilters['unificar_lotes'] ?? false;
                $unificarNuevo = ! $revert;

                // Determinar nuevo prefix
                $prefixActual = $source->lote_prefix ?? 'SG';
                if ($revert) {
                    // Volver a prefijos cortos
                    $prefixNuevo = str_contains($source->name, 'no_agendados') ? 'SG_NA' : 'SG_NC';
                } else {
                    // Prefijos descriptivos para lotes unificados
                    $prefixNuevo = str_contains($source->name, 'no_agendados')
                        ? 'SYSGAL_NO_AGENDADOS'
                        : 'SYSGAL_NO_CERRADOS';
                }

                return [
                    $source->id,
                    $source->name,
                    $unificarActual ? '✅ Sí' : '❌ No',
                    $unificarNuevo ? '✅ Sí' : '❌ No',
                    $prefixActual,
                    $prefixNuevo,
                ];
            })
        );

        if ($dryRun) {
            $this->info('Ejecuta sin --dry-run para aplicar los cambios');

            return Command::SUCCESS;
        }

        if (! $this->confirm('¿Aplicar estos cambios?')) {
            $this->info('Operación cancelada');

            return Command::SUCCESS;
        }

        foreach ($sources as $source) {
            $syncFilters = $source->sync_filters ?? [];
            $syncFilters['unificar_lotes'] = ! $revert;

            // Actualizar prefix
            if ($revert) {
                $source->lote_prefix = str_contains($source->name, 'no_agendados') ? 'SG_NA' : 'SG_NC';
            } else {
                $source->lote_prefix = str_contains($source->name, 'no_agendados')
                    ? 'SYSGAL_NO_AGENDADOS'
                    : 'SYSGAL_NO_CERRADOS';
            }

            $source->sync_filters = $syncFilters;
            $source->save();

            $this->line("  ✅ {$source->name} actualizado");
        }

        $this->newLine();
        $this->info('✅ Fuentes de Sysgal actualizadas correctamente');

        if (! $revert) {
            $this->newLine();
            $this->warn('⚠️  IMPORTANTE: Los lotes viejos (SG_NA_*, SG_NC_*) seguirán existiendo.');
            $this->warn('   Los nuevos syncs crearán lotes unificados (SYSGAL_NO_AGENDADOS, SYSGAL_NO_CERRADOS).');
            $this->warn('   Podés renombrar o archivar los lotes viejos manualmente si querés.');
        }

        return Command::SUCCESS;
    }
}
