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
        // Obtener estadísticas de envíos de esta etapa
        $envioStats = DB::table('envios')
            ->where('flujo_ejecucion_etapa_id', $etapa->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN estado IN ('enviado', 'abierto', 'clickeado') THEN 1 ELSE 0 END) as exitosos,
                SUM(CASE WHEN estado = 'fallido' THEN 1 ELSE 0 END) as fallidos,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes
            ")
            ->first();

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

        // Generar message_id si no existe (necesario para condiciones de apertura)
        $messageId = $etapa->message_id ?: rand(100000, 999999);

        // Construir response_athenacampaign con datos reales
        $existingResponse = $etapa->response_athenacampaign ?? [];
        $responseData = array_merge($existingResponse, [
            'messageID' => $messageId,
            'Recipients' => $envioStats->exitosos ?? 0,
            'Errores' => ($envioStats->fallidos ?? 0) + $updated,
            'total_enviados' => $envioStats->total ?? 0,
            'recovered_at' => now()->toIso8601String(),
            'recovered_pending' => $updated,
        ]);

        // Marcar etapa como completada con toda la info necesaria
        $etapa->update([
            'estado' => 'completed',
            'ejecutado' => true,
            'fecha_ejecucion' => now(),
            'message_id' => $messageId,
            'response_athenacampaign' => $responseData,
        ]);

        Log::info("RecoverStuckEtapas: Etapa marcada como completed", [
            'etapa_id' => $etapa->id,
            'node_id' => $etapa->node_id,
            'message_id' => $messageId,
            'recipients' => $responseData['Recipients'],
        ]);

        // Programar el siguiente nodo del flujo
        $this->scheduleNextNode($etapa, $messageId);
    }

    /**
     * Programa el siguiente nodo del flujo después de recuperar una etapa.
     */
    private function scheduleNextNode(FlujoEjecucionEtapa $etapa, int $messageId): void
    {
        $ejecucion = $etapa->flujoEjecucion;
        if (!$ejecucion || !$ejecucion->flujo) {
            Log::warning("RecoverStuckEtapas: No se pudo cargar flujo para programar siguiente nodo", [
                'etapa_id' => $etapa->id,
            ]);
            return;
        }

        $flujoData = $ejecucion->flujo->flujo_data;
        $branches = $flujoData['branches'] ?? [];
        $stages = $flujoData['stages'] ?? [];
        $conditions = $flujoData['conditions'] ?? [];

        // Buscar conexiones desde esta etapa
        $conexiones = collect($branches)->filter(function ($branch) use ($etapa) {
            return $branch['source_node_id'] === $etapa->node_id;
        });

        if ($conexiones->isEmpty()) {
            // No hay siguiente nodo - finalizar flujo
            Log::info("RecoverStuckEtapas: No hay siguiente nodo, finalizando flujo", [
                'flujo_ejecucion_id' => $ejecucion->id,
            ]);
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);
            return;
        }

        $primeraConexion = $conexiones->first();
        $targetNodeId = $primeraConexion['target_node_id'];

        // Si es un nodo de fin
        if (str_starts_with($targetNodeId, 'end-')) {
            Log::info("RecoverStuckEtapas: Siguiente es nodo fin, finalizando flujo", [
                'flujo_ejecucion_id' => $ejecucion->id,
            ]);
            $ejecucion->update([
                'estado' => 'completed',
                'fecha_fin' => now(),
            ]);
            return;
        }

        // Buscar el nodo destino
        $targetNode = collect($stages)->firstWhere('id', $targetNodeId);
        if (!$targetNode) {
            $targetNode = collect($conditions)->firstWhere('id', $targetNodeId);
        }

        if (!$targetNode) {
            Log::warning("RecoverStuckEtapas: No se encontró nodo destino", [
                'target_node_id' => $targetNodeId,
            ]);
            return;
        }

        $tipoNodo = $targetNode['type'] ?? 'stage';

        // Calcular fecha programada
        if ($tipoNodo === 'condition') {
            // Las condiciones se verifican después del tiempo de verificación
            $tiempoVerificacion = $this->getStageData($etapa, $stages)['tiempo_verificacion_condicion'] ?? 24;
            $fechaProgramada = now()->addHours($tiempoVerificacion);
        } else {
            // Las etapas tienen tiempo de espera
            $tiempoEspera = $targetNode['tiempo_espera'] ?? 0;
            $fechaProgramada = now()->addDays($tiempoEspera);
        }

        // Crear o actualizar la etapa siguiente
        $siguienteEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $targetNodeId)
            ->first();

        $etapaData = [
            'prospectos_ids' => $etapa->prospectos_ids,
            'fecha_programada' => $fechaProgramada,
            'estado' => 'pending',
        ];

        // Si es condición, agregar info del message_id fuente
        if ($tipoNodo === 'condition') {
            $etapaData['response_athenacampaign'] = [
                'pending_condition' => true,
                'source_message_id' => $messageId,
                'source_etapa_id' => $etapa->id,
                'conexion' => $primeraConexion,
            ];
        }

        if ($siguienteEtapa) {
            $siguienteEtapa->update($etapaData);
        } else {
            FlujoEjecucionEtapa::create(array_merge($etapaData, [
                'flujo_ejecucion_id' => $ejecucion->id,
                'etapa_id' => null,
                'node_id' => $targetNodeId,
            ]));
        }

        // Actualizar ejecución con próximo nodo
        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $targetNodeId,
            'fecha_proximo_nodo' => $fechaProgramada,
        ]);

        Log::info("RecoverStuckEtapas: Programado siguiente nodo", [
            'target_node_id' => $targetNodeId,
            'tipo' => $tipoNodo,
            'fecha_programada' => $fechaProgramada,
        ]);
    }

    /**
     * Obtiene los datos del stage desde el flujo_data.
     */
    private function getStageData(FlujoEjecucionEtapa $etapa, array $stages): array
    {
        return collect($stages)->firstWhere('id', $etapa->node_id) ?? [];
    }
}
