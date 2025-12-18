<?php

namespace App\Console\Commands;

use App\Models\Prospecto;
use Illuminate\Console\Command;

class NormalizarTelefonosProspectos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prospectos:normalizar-telefonos {--dry-run : Ejecutar en modo simulación sin guardar cambios}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normaliza los teléfonos de prospectos agregando el prefijo +56 para Chile';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 Ejecutando en modo DRY-RUN (simulación)');
            $this->info('No se guardarán cambios en la base de datos');
            $this->newLine();
        }

        $this->info('📞 Normalizando teléfonos de prospectos...');
        $this->newLine();

        // Obtener prospectos con teléfono
        $prospectos = Prospecto::whereNotNull('telefono')
            ->where('telefono', '!=', '')
            ->get();

        if ($prospectos->isEmpty()) {
            $this->warn('⚠️  No se encontraron prospectos con teléfono');

            return self::SUCCESS;
        }

        $this->info("📊 Total de prospectos con teléfono: {$prospectos->count()}");
        $this->newLine();

        $actualizados = 0;
        $sinCambios = 0;
        $errores = 0;

        $bar = $this->output->createProgressBar($prospectos->count());
        $bar->start();

        foreach ($prospectos as $prospecto) {
            $telefonoOriginal = $prospecto->getOriginal('telefono');

            try {
                // El mutator se encargará de normalizar automáticamente
                $prospecto->telefono = $telefonoOriginal;

                // Verificar si hubo cambios
                if ($prospecto->telefono !== $telefonoOriginal) {
                    if (! $dryRun) {
                        $prospecto->saveQuietly(); // saveQuietly no dispara eventos
                    }

                    $this->newLine();
                    $this->line("  ✅ ID {$prospecto->id}: '{$telefonoOriginal}' → '{$prospecto->telefono}'");

                    $actualizados++;
                } else {
                    $sinCambios++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("  ❌ Error en ID {$prospecto->id}: {$e->getMessage()}");
                $errores++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Resumen
        $this->info('📈 Resumen de la normalización:');
        $this->table(
            ['Estado', 'Cantidad'],
            [
                ['✅ Actualizados', $actualizados],
                ['➖ Sin cambios', $sinCambios],
                ['❌ Errores', $errores],
                ['📊 Total procesados', $prospectos->count()],
            ]
        );

        if ($dryRun && $actualizados > 0) {
            $this->newLine();
            $this->warn('⚠️  Este fue un DRY-RUN. Ejecuta sin --dry-run para guardar los cambios.');
        }

        if ($actualizados > 0 && ! $dryRun) {
            $this->newLine();
            $this->info("✅ Se normalizaron {$actualizados} teléfonos exitosamente");
        }

        return self::SUCCESS;
    }
}
