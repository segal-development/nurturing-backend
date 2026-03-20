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
        $query = \App\Models\Prospecto::query();

        // Filter by importacion (lotes_ids or origen) — mirrors job logic
        $hasImportacionFilter = ! empty($flujo->lotes_ids) || ! empty($flujo->origen);

        if ($hasImportacionFilter) {
            $query->whereHas('importacion', function ($q) use ($flujo) {
                if (! empty($flujo->lotes_ids)) {
                    $q->whereIn('lote_id', $flujo->lotes_ids);
                } elseif ($flujo->origen) {
                    $q->where('origen', $flujo->origen);
                }
            });
        }

        if (! $hasImportacionFilter && ! $flujo->usarFiltroNivelDeuda()) {
            return 0;
        }

        // Filter by nivel_deuda when flujo has nivel_deuda_target set
        if ($flujo->usarFiltroNivelDeuda()) {
            $nivelDeudaTarget = $flujo->nivel_deuda_target;
            $query->where(function ($q) use ($nivelDeudaTarget) {
                $q->whereIn(
                    \Illuminate\Support\Facades\DB::raw("metadata->>'nivel_deuda'"),
                    $nivelDeudaTarget
                );

                if (in_array('sin_informacion', $nivelDeudaTarget)) {
                    $q->orWhereNull(\Illuminate\Support\Facades\DB::raw("metadata->>'nivel_deuda'"));
                    $q->orWhere(\Illuminate\Support\Facades\DB::raw("metadata->>'nivel_deuda'"), '');
                }
            });
        }

        // Use NOT EXISTS subquery instead of whereNotIn with plucked IDs
        // Before: pluck() loaded 87k+ IDs into memory, whereNotIn created 87k+ parameter bindings
        // After: DB handles the exclusion via subquery — zero parameter overhead
        return $query
            ->whereDoesntHave('prospectosEnFlujo', function ($q) use ($flujo) {
                $q->where('flujo_id', $flujo->id);
            })
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
