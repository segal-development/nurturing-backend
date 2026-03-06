<?php

namespace App\Console\Commands;

use App\Models\ExternalApiSource;
use Illuminate\Console\Command;

/**
 * Comando para actualizar el field_mapping de las fuentes de Sysgal.
 *
 * Agrega el mapeo de TotalDeuda para obtener el monto de deuda
 * de los prospectos desde la API de Sysgal.
 *
 * Ejecutar con: php artisan sysgal:update-field-mapping
 */
class UpdateSysgalFieldMapping extends Command
{
    protected $signature = 'sysgal:update-field-mapping
                            {--dry-run : Mostrar cambios sin aplicarlos}';

    protected $description = 'Actualiza el field_mapping de Sysgal para incluir TotalDeuda';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Actualizando field_mapping de fuentes Sysgal...');

        if ($dryRun) {
            $this->warn('⚠️  Modo dry-run: no se aplicarán cambios');
        }

        $sources = ExternalApiSource::where('name', 'like', 'sysgal%')->get();

        if ($sources->isEmpty()) {
            $this->error('No se encontraron fuentes de Sysgal');

            return Command::FAILURE;
        }

        foreach ($sources as $source) {
            $fieldMapping = $source->field_mapping ?? [];
            $montoDeudaActual = $fieldMapping['monto_deuda'] ?? 'null';

            // Determinar el nuevo valor según el endpoint
            $isNoCerrados = str_contains($source->name, 'no_cerrados');
            $montoDeudaNuevo = $isNoCerrados ? 'Cliente.TotalDeuda' : 'TotalDeuda';

            $this->newLine();
            $this->line("📦 <info>{$source->name}</info>");
            $this->line("   monto_deuda actual: <comment>{$montoDeudaActual}</comment>");
            $this->line("   monto_deuda nuevo:  <info>{$montoDeudaNuevo}</info>");

            if ($dryRun) {
                continue;
            }

            // Actualizar el field_mapping
            $fieldMapping['monto_deuda'] = $montoDeudaNuevo;
            $source->field_mapping = $fieldMapping;
            $source->save();

            $this->line('   ✅ Actualizado');
        }

        $this->newLine();

        if ($dryRun) {
            $this->info('Ejecuta sin --dry-run para aplicar los cambios');
        } else {
            $this->info('✅ Field mappings actualizados correctamente');
            $this->newLine();
            $this->warn('📋 PRÓXIMOS PASOS:');
            $this->line('   1. Ejecuta: php artisan sysgal:unificar-lotes --global');
            $this->line('   2. Dispara un sync manual para probar');
            $this->line('   3. Verifica que los prospectos tengan monto_deuda y nivel_deuda en metadata');
        }

        return Command::SUCCESS;
    }
}
