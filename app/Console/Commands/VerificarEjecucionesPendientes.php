<?php

namespace App\Console\Commands;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Console\Command;

class VerificarEjecucionesPendientes extends Command
{
    protected $signature = 'flujos:verificar-pendientes';

    protected $description = 'Verifica y muestra el estado de ejecuciones y etapas pendientes';

    public function handle(): int
    {
        $this->info('Verificando ejecuciones y etapas pendientes...');
        $this->newLine();

        // Ejecuciones pendientes que deberían haber comenzado
        $ejecucionesPendientes = FlujoEjecucion::deberianHaberComenzado()->get();

        if ($ejecucionesPendientes->count() > 0) {
            $this->warn("Ejecuciones pendientes que deberían haber comenzado: {$ejecucionesPendientes->count()}");

            foreach ($ejecucionesPendientes as $ejecucion) {
                $this->line("  - Ejecución #{$ejecucion->id} | Flujo: {$ejecucion->flujo->nombre} | Programada: {$ejecucion->fecha_inicio_programada}");
            }

            $this->newLine();
        } else {
            $this->info('No hay ejecuciones pendientes que deberían haber comenzado.');
            $this->newLine();
        }

        // Etapas pendientes que deberían ejecutarse
        $etapasPendientes = FlujoEjecucionEtapa::deberianEjecutarse()->get();

        if ($etapasPendientes->count() > 0) {
            $this->warn("Etapas pendientes que deberían ejecutarse: {$etapasPendientes->count()}");

            foreach ($etapasPendientes as $etapa) {
                $this->line("  - Etapa #{$etapa->id} | Ejecución: {$etapa->flujo_ejecucion_id} | Programada: {$etapa->fecha_programada}");
            }

            $this->newLine();
        } else {
            $this->info('No hay etapas pendientes que deberían ejecutarse.');
            $this->newLine();
        }

        // Resumen general
        $this->info('Resumen General:');
        $this->table(
            ['Estado', 'Cantidad'],
            [
                ['Ejecuciones Pending', FlujoEjecucion::where('estado', 'pending')->count()],
                ['Ejecuciones In Progress', FlujoEjecucion::where('estado', 'in_progress')->count()],
                ['Ejecuciones Completed', FlujoEjecucion::where('estado', 'completed')->count()],
                ['Ejecuciones Failed', FlujoEjecucion::where('estado', 'failed')->count()],
                ['Etapas Pending', FlujoEjecucionEtapa::where('estado', 'pending')->count()],
                ['Etapas Executing', FlujoEjecucionEtapa::where('estado', 'executing')->count()],
                ['Etapas Completed', FlujoEjecucionEtapa::where('estado', 'completed')->count()],
                ['Etapas Failed', FlujoEjecucionEtapa::where('estado', 'failed')->count()],
            ]
        );

        return Command::SUCCESS;
    }
}
