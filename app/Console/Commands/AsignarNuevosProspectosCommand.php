<?php

namespace App\Console\Commands;

use App\Jobs\AsignarNuevosProspectosAFlujoJob;
use App\Models\Flujo;
use Illuminate\Console\Command;

class AsignarNuevosProspectosCommand extends Command
{
    protected $signature = 'prospectos:asignar-nuevos 
                            {--sync : Ejecutar de forma síncrona (sin queue)}
                            {--flujo= : ID específico de flujo a procesar}
                            {--stats : Mostrar estadísticas sin ejecutar}';

    protected $description = 'Asigna nuevos prospectos a flujos con auto_asignar_nuevos activo';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->mostrarEstadisticas();
        }

        $flujoId = $this->option('flujo');

        if ($flujoId) {
            return $this->procesarFlujoEspecifico((int) $flujoId);
        }

        return $this->procesarTodos();
    }

    private function mostrarEstadisticas(): int
    {
        $this->info('=== Estadísticas de Auto-Asignación ===');
        $this->newLine();

        // Flujos con auto-asignación activa
        $flujosAuto = Flujo::where('activo', true)
            ->where('auto_asignar_nuevos', true)
            ->with('tipoProspecto')
            ->withCount('prospectosEnFlujo')
            ->get();

        if ($flujosAuto->isEmpty()) {
            $this->warn('No hay flujos con auto_asignar_nuevos activo');

            return 0;
        }

        $this->info("Flujos con auto-asignación activa: {$flujosAuto->count()}");
        $this->newLine();

        $headers = ['ID', 'Nombre', 'Origen', 'Prospectos Actuales', 'Prospectos Nuevos Disponibles'];
        $rows = [];

        foreach ($flujosAuto as $flujo) {
            $nuevosDisponibles = $this->contarProspectosNuevos($flujo);
            $rows[] = [
                $flujo->id,
                substr($flujo->nombre, 0, 40),
                substr($flujo->origen, 0, 25),
                $flujo->prospectos_en_flujo_count,
                $nuevosDisponibles,
            ];
        }

        $this->table($headers, $rows);

        return 0;
    }

    private function contarProspectosNuevos(Flujo $flujo): int
    {
        $prospectosEnFlujo = $flujo->prospectosEnFlujo()->pluck('prospecto_id');

        return \App\Models\Prospecto::query()
            ->whereHas('importacion', fn ($q) => $q->where('origen', $flujo->origen))
            ->whereNotIn('id', $prospectosEnFlujo)
            ->where('estado', 'activo')
            ->count();
    }

    private function procesarFlujoEspecifico(int $flujoId): int
    {
        $flujo = Flujo::find($flujoId);

        if (! $flujo) {
            $this->error("Flujo con ID {$flujoId} no encontrado");

            return 1;
        }

        if (! $flujo->auto_asignar_nuevos) {
            $this->warn("El flujo '{$flujo->nombre}' no tiene auto_asignar_nuevos activo");
            if (! $this->confirm('¿Deseas activarlo y continuar?')) {
                return 0;
            }
            $flujo->update(['auto_asignar_nuevos' => true]);
        }

        $this->info("Procesando flujo: {$flujo->nombre}");

        if ($this->option('sync')) {
            $this->info('Ejecutando de forma síncrona...');
            (new AsignarNuevosProspectosAFlujoJob)->handle();
        } else {
            AsignarNuevosProspectosAFlujoJob::dispatch();
            $this->info('Job despachado a la queue');
        }

        return 0;
    }

    private function procesarTodos(): int
    {
        $flujosCount = Flujo::where('activo', true)
            ->where('auto_asignar_nuevos', true)
            ->count();

        if ($flujosCount === 0) {
            $this->warn('No hay flujos con auto_asignar_nuevos activo');

            return 0;
        }

        $this->info("Se procesarán {$flujosCount} flujos");

        if ($this->option('sync')) {
            $this->info('Ejecutando de forma síncrona...');
            (new AsignarNuevosProspectosAFlujoJob)->handle();
            $this->info('Completado');
        } else {
            AsignarNuevosProspectosAFlujoJob::dispatch();
            $this->info('Job despachado a la queue');
        }

        return 0;
    }
}
