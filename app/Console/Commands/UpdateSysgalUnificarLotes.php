<?php

namespace App\Console\Commands;

use App\Models\ExternalApiSource;
use Illuminate\Console\Command;

/**
 * Comando para configurar la unificación de lotes en las fuentes de Sysgal.
 *
 * Modos de operación:
 * 1. --global: UN SOLO lote "SYSGAL" para TODAS las fuentes (recomendado)
 * 2. Sin --global: Un lote por fuente (SYSGAL_NO_AGENDADOS, SYSGAL_NO_CERRADOS)
 * 3. --revert: Volver al sistema anterior (múltiples lotes por clasificación)
 *
 * La clasificación original (Etapa, Estado_Final) se guarda en metadata del prospecto.
 *
 * Ejecutar con: php artisan sysgal:unificar-lotes --global
 */
class UpdateSysgalUnificarLotes extends Command
{
    protected $signature = 'sysgal:unificar-lotes
                            {--dry-run : Mostrar cambios sin aplicarlos}
                            {--global : Usar UN SOLO lote "SYSGAL" para todas las fuentes}
                            {--revert : Desactivar la unificación y volver al sistema anterior}';

    protected $description = 'Configura la unificación de lotes para fuentes de Sysgal';

    private const LOTE_GLOBAL_NOMBRE = 'SYSGAL';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $global = $this->option('global');
        $revert = $this->option('revert');

        if ($revert) {
            $this->info('Desactivando unificación de lotes...');
        } elseif ($global) {
            $this->info('Activando lote GLOBAL "SYSGAL" para todas las fuentes...');
        } else {
            $this->info('Activando lote unificado por fuente...');
        }

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
            ['ID', 'Nombre', 'Lote Global Actual', 'Lote Global Nuevo'],
            $sources->map(function ($source) use ($revert, $global) {
                $syncFilters = $source->sync_filters ?? [];
                $loteGlobalActual = $syncFilters['lote_global'] ?? null;

                if ($revert) {
                    $loteGlobalNuevo = null;
                } elseif ($global) {
                    $loteGlobalNuevo = self::LOTE_GLOBAL_NOMBRE;
                } else {
                    $loteGlobalNuevo = null; // Usa prefix por fuente
                }

                return [
                    $source->id,
                    $source->name,
                    $loteGlobalActual ?: '❌ No',
                    $loteGlobalNuevo ?: '❌ No (por fuente)',
                ];
            })
        );

        if ($global) {
            $this->newLine();
            $this->info('📦 Con --global, AMBAS fuentes irán al mismo lote: "'.self::LOTE_GLOBAL_NOMBRE.'"');
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Ejecuta sin --dry-run para aplicar los cambios');

            return Command::SUCCESS;
        }

        if (! $this->confirm('¿Aplicar estos cambios?')) {
            $this->info('Operación cancelada');

            return Command::SUCCESS;
        }

        foreach ($sources as $source) {
            $syncFilters = $source->sync_filters ?? [];

            if ($revert) {
                // Desactivar todo
                unset($syncFilters['lote_global']);
                unset($syncFilters['unificar_lotes']);
                $source->lote_prefix = str_contains($source->name, 'no_agendados') ? 'SG_NA' : 'SG_NC';
            } elseif ($global) {
                // Lote global compartido
                $syncFilters['lote_global'] = self::LOTE_GLOBAL_NOMBRE;
                $syncFilters['unificar_lotes'] = true;
            } else {
                // Lote unificado por fuente
                unset($syncFilters['lote_global']);
                $syncFilters['unificar_lotes'] = true;
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
            $this->warn('⚠️  IMPORTANTE: Los lotes viejos seguirán existiendo.');

            if ($global) {
                $this->warn('   Los nuevos syncs crearán prospectos en el lote "'.self::LOTE_GLOBAL_NOMBRE.'".');
                $this->warn('   Este lote es COMPARTIDO por todas las fuentes de Sysgal.');
            } else {
                $this->warn('   Los nuevos syncs crearán lotes por fuente.');
            }

            $this->warn('   Podés archivar los lotes viejos manualmente si querés.');
        }

        return Command::SUCCESS;
    }
}
