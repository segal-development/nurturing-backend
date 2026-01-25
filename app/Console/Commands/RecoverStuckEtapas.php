<?php

namespace App\Console\Commands;

use App\Models\FlujoEjecucionEtapa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detecta y recupera etapas de flujo que quedaron "estancadas".
 *
 * Una etapa está estancada cuando:
 * - Estado = 'executing'
 * - No hay jobs en cola para procesarla
 * - No ha tenido actividad (envíos procesados) en los últimos X minutos
 * - Tiene envíos pendientes que nunca se van a procesar
 *
 * Esto puede ocurrir por:
 * - Circuit breaker que bloqueó jobs y expiraron
 * - Workers que crashearon
 * - Jobs que fallaron y fueron a failed_jobs
 */
class RecoverStuckEtapas extends Command
{
    protected $signature = 'etapas:recover-stuck 
                            {--minutes=30 : Minutos sin actividad para considerar estancada}
                            {--dry-run : Solo mostrar qué haría, sin ejecutar cambios}';

    protected $description = 'Detecta y recupera etapas de flujo estancadas';

    public function handle(): int
    {
        $minutesThreshold = (int) $this->option('minutes');
        $dryRun = $this->option('dry-run');

        $this->info("Buscando etapas estancadas (sin actividad por {$minutesThreshold} minutos)...");

        // Buscar etapas en estado 'executing'
        $etapasEjecutando = FlujoEjecucionEtapa::where('estado', 'executing')
            ->with('flujoEjecucion')
            ->get();

        if ($etapasEjecutando->isEmpty()) {
            $this->info('No hay etapas en ejecución.');
            return Command::SUCCESS;
        }

        $this->info("Encontradas {$etapasEjecutando->count()} etapas en ejecución.");

        $recovered = 0;

        foreach ($etapasEjecutando as $etapa) {
            $result = $this->analyzeEtapa($etapa, $minutesThreshold);

            if (!$result['is_stuck']) {
                $this->line("  ✓ Etapa {$etapa->id} ({$etapa->node_id}): Procesando normalmente");
                continue;
            }

            $this->warn("  ⚠ Etapa {$etapa->id} ({$etapa->node_id}): ESTANCADA");
            $this->line("    - Pendientes: {$result['pending_count']}");
            $this->line("    - Jobs en cola: {$result['jobs_count']}");
            $this->line("    - Última actividad: {$result['last_activity']}");
            $this->line("    - Minutos inactiva: {$result['minutes_inactive']}");

            if ($dryRun) {
                $this->info("    [DRY-RUN] Se marcarían {$result['pending_count']} envíos como fallidos y la etapa como completed");
            } else {
                $this->recoverEtapa($etapa, $result);
                $recovered++;
                $this->info("    ✓ Etapa recuperada");
            }
        }

        if ($recovered > 0) {
            Log::info("RecoverStuckEtapas: Recuperadas {$recovered} etapas estancadas");
        }

        $this->newLine();
        $this->info($dryRun 
            ? "Modo dry-run: no se realizaron cambios" 
            : "Proceso completado. Etapas recuperadas: {$recovered}");

        return Command::SUCCESS;
    }

    /**
     * Analiza si una etapa está estancada.
     */
    private function analyzeEtapa(FlujoEjecucionEtapa $etapa, int $minutesThreshold): array
    {
        // Contar envíos pendientes de esta etapa
        $pendingCount = DB::table('envios')
            ->where('flujo_ejecucion_etapa_id', $etapa->id)
            ->where('estado', 'pendiente')
            ->count();

        // Si no hay pendientes, no está estancada (está procesando o ya terminó)
        if ($pendingCount === 0) {
            return [
                'is_stuck' => false,
                'pending_count' => 0,
                'jobs_count' => 0,
                'last_activity' => null,
                'minutes_inactive' => 0,
            ];
        }

        // Contar jobs en cola (aproximado - buscamos jobs que contengan el etapa_id)
        // Nota: Esto es una aproximación ya que los jobs están serializados
        $jobsCount = DB::table('jobs')->count();

        // Si hay muchos jobs en cola, probablemente está procesando
        if ($jobsCount > 100) {
            return [
                'is_stuck' => false,
                'pending_count' => $pendingCount,
                'jobs_count' => $jobsCount,
                'last_activity' => 'Jobs en cola',
                'minutes_inactive' => 0,
            ];
        }

        // Verificar última actividad (último envío procesado de esta etapa)
        $lastProcessed = DB::table('envios')
            ->where('flujo_ejecucion_etapa_id', $etapa->id)
            ->whereIn('estado', ['enviado', 'abierto', 'clickeado', 'fallido'])
            ->max('updated_at');

        $minutesInactive = $lastProcessed 
            ? now()->diffInMinutes($lastProcessed) 
            : 999; // Si nunca se procesó nada, considerar muy inactiva

        $isStuck = $minutesInactive >= $minutesThreshold && $jobsCount < 10;

        return [
            'is_stuck' => $isStuck,
            'pending_count' => $pendingCount,
            'jobs_count' => $jobsCount,
            'last_activity' => $lastProcessed ?? 'Nunca',
            'minutes_inactive' => $minutesInactive,
        ];
    }

    /**
     * Recupera una etapa estancada.
     */
    private function recoverEtapa(FlujoEjecucionEtapa $etapa, array $analysis): void
    {
        // Marcar envíos pendientes como fallidos
        $updated = DB::table('envios')
            ->where('flujo_ejecucion_etapa_id', $etapa->id)
            ->where('estado', 'pendiente')
            ->update([
                'estado' => 'fallido',
                'updated_at' => now(),
            ]);

        Log::info("RecoverStuckEtapas: Marcados {$updated} envíos como fallidos", [
            'etapa_id' => $etapa->id,
            'node_id' => $etapa->node_id,
            'flujo_ejecucion_id' => $etapa->flujo_ejecucion_id,
        ]);

        // Marcar etapa como completada
        $etapa->update([
            'estado' => 'completed',
            'ejecutado' => true,
            'fecha_ejecucion' => now(),
        ]);

        Log::info("RecoverStuckEtapas: Etapa marcada como completed", [
            'etapa_id' => $etapa->id,
            'node_id' => $etapa->node_id,
        ]);

        // TODO: Aquí podríamos disparar el siguiente paso del flujo
        // Por ahora, el scheduler EjecutarNodosProgramados debería detectarlo
    }
}
