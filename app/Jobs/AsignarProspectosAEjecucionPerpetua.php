<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Services\StageOrderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to add prospects to an EXISTING perpetual execution.
 *
 * Instead of creating a new FlujoEjecucion for each batch of new prospects,
 * this job adds them to the existing perpetual execution with:
 * - ultima_etapa_node_id = NULL (so they start from stage 1)
 * - Added to execution's prospectos_ids
 * - Added to first stage's prospectos_ids
 *
 * This enables new prospects to catch up through the flow independently
 * while existing prospects continue through later stages.
 */
class AsignarProspectosAEjecucionPerpetua implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600; // 10 minutes

    public int $tries = 3;

    private const BATCH_SIZE = 500;

    /**
     * @param  int  $flujoId  The flow to assign prospects to
     * @param  array  $prospectoIds  Array of prospect IDs to assign
     * @param  int|null  $loteId  Optional lote ID for logging/tracking
     */
    public function __construct(
        public int $flujoId,
        public array $prospectoIds,
        public ?int $loteId = null
    ) {
        $this->onQueue('default');
    }

    public function handle(StageOrderResolver $resolver): void
    {
        Log::info('AsignarProspectosAEjecucionPerpetua: Iniciando', [
            'flujo_id' => $this->flujoId,
            'prospectos_count' => count($this->prospectoIds),
            'lote_id' => $this->loteId,
        ]);

        if (empty($this->prospectoIds)) {
            Log::info('AsignarProspectosAEjecucionPerpetua: No hay prospectos para asignar');

            return;
        }

        $flujo = Flujo::find($this->flujoId);

        if (! $flujo) {
            Log::error('AsignarProspectosAEjecucionPerpetua: Flujo no encontrado', [
                'flujo_id' => $this->flujoId,
            ]);

            return;
        }

        // Find or create perpetual execution
        $ejecucionPerpetua = $this->obtenerOCrearEjecucionPerpetua($flujo, $resolver);

        if (! $ejecucionPerpetua) {
            Log::error('AsignarProspectosAEjecucionPerpetua: No se pudo obtener ejecucion perpetua', [
                'flujo_id' => $this->flujoId,
            ]);

            return;
        }

        // Filter out prospects already in the flow
        $nuevosProspectoIds = $this->filtrarProspectosExistentes($flujo, $this->prospectoIds);

        if (empty($nuevosProspectoIds)) {
            Log::info('AsignarProspectosAEjecucionPerpetua: Todos los prospectos ya estan en el flujo', [
                'flujo_id' => $this->flujoId,
            ]);

            return;
        }

        // Assign prospects to prospecto_en_flujo
        $asignados = $this->asignarProspectos($flujo, $nuevosProspectoIds);

        // Update execution's prospectos_ids
        $this->actualizarEjecucion($ejecucionPerpetua, $nuevosProspectoIds);

        // Update first stage's prospectos_ids
        $this->actualizarPrimeraEtapa($ejecucionPerpetua, $nuevosProspectoIds, $resolver);

        Log::info('AsignarProspectosAEjecucionPerpetua: Completado', [
            'flujo_id' => $this->flujoId,
            'ejecucion_id' => $ejecucionPerpetua->id,
            'prospectos_asignados' => $asignados,
            'lote_id' => $this->loteId,
        ]);
    }

    /**
     * Get existing perpetual execution or create one if none exists.
     */
    private function obtenerOCrearEjecucionPerpetua(Flujo $flujo, StageOrderResolver $resolver): ?FlujoEjecucion
    {
        // Try to find existing perpetual execution in progress
        $ejecucion = FlujoEjecucion::where('flujo_id', $flujo->id)
            ->where('es_perpetuo', true)
            ->where('estado', 'in_progress')
            ->first();

        if ($ejecucion) {
            Log::info('AsignarProspectosAEjecucionPerpetua: Usando ejecucion perpetua existente', [
                'ejecucion_id' => $ejecucion->id,
                'flujo_id' => $flujo->id,
            ]);

            return $ejecucion;
        }

        // No perpetual execution exists, create one
        Log::info('AsignarProspectosAEjecucionPerpetua: Creando nueva ejecucion perpetua', [
            'flujo_id' => $flujo->id,
        ]);

        return $this->crearEjecucionPerpetua($flujo, $resolver);
    }

    /**
     * Create a new perpetual execution for the flow.
     */
    private function crearEjecucionPerpetua(Flujo $flujo, StageOrderResolver $resolver): ?FlujoEjecucion
    {
        $configStructure = $flujo->config_structure;

        if (empty($configStructure) || empty($configStructure['stages'])) {
            Log::warning('AsignarProspectosAEjecucionPerpetua: Flujo sin config_structure valido', [
                'flujo_id' => $flujo->id,
            ]);

            return null;
        }

        $firstStageId = $resolver->getFirstStage($flujo);

        if (! $firstStageId) {
            Log::warning('AsignarProspectosAEjecucionPerpetua: No se encontro primera etapa', [
                'flujo_id' => $flujo->id,
            ]);

            return null;
        }

        $stages = $configStructure['stages'];
        $primeraEtapa = collect($stages)->firstWhere('id', $firstStageId);
        $tiempoEspera = $primeraEtapa['tiempo_espera'] ?? 0;

        $fechaInicio = now();
        $fechaProximoNodo = $fechaInicio->copy()->addDays($tiempoEspera);

        try {
            $ejecucion = FlujoEjecucion::create([
                'flujo_id' => $flujo->id,
                'origen_id' => null,
                'prospectos_ids' => [],
                'prospectos_count' => 0,
                'fecha_inicio_programada' => $fechaInicio,
                'fecha_inicio_real' => $fechaInicio,
                'estado' => 'in_progress',
                'es_perpetuo' => $flujo->es_perpetuo ?? false,
                'nodo_actual' => null,
                'proximo_nodo' => $firstStageId,
                'fecha_proximo_nodo' => $fechaProximoNodo,
                'config' => [
                    'created_from' => 'auto_asignar_perpetuo',
                    'created_at' => now()->toISOString(),
                ],
            ]);

            // Create FlujoEjecucionEtapa for each stage
            $this->crearEtapasEjecucion($ejecucion, $configStructure, $firstStageId);

            Log::info('AsignarProspectosAEjecucionPerpetua: Ejecucion perpetua creada', [
                'ejecucion_id' => $ejecucion->id,
                'flujo_id' => $flujo->id,
            ]);

            return $ejecucion;
        } catch (\Exception $e) {
            Log::error('AsignarProspectosAEjecucionPerpetua: Error creando ejecucion perpetua', [
                'flujo_id' => $flujo->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create FlujoEjecucionEtapa records for the execution.
     */
    private function crearEtapasEjecucion(
        FlujoEjecucion $ejecucion,
        array $configStructure,
        string $firstStageId
    ): void {
        $stages = $configStructure['stages'] ?? [];
        $branches = $configStructure['branches'] ?? [];

        // Build execution order
        $ordenEjecucion = $this->construirOrdenEjecucion($stages, $branches, $firstStageId);

        $fechaBase = now();

        foreach ($ordenEjecucion as $stageId) {
            $stage = collect($stages)->firstWhere('id', $stageId);
            if (! $stage) {
                continue;
            }

            $tiempoEspera = $stage['tiempo_espera'] ?? 0;
            $fechaProgramada = $fechaBase->copy()->addDays($tiempoEspera);

            FlujoEjecucionEtapa::create([
                'flujo_ejecucion_id' => $ejecucion->id,
                'etapa_id' => null,
                'node_id' => $stageId,
                'fecha_programada' => $fechaProgramada,
                'estado' => 'pending',
                'ejecutado' => false,
                'prospectos_ids' => [],
                'prospectos_count' => 0,
            ]);

            $fechaBase = $fechaProgramada->copy();
        }
    }

    /**
     * Build execution order from stages and branches.
     *
     * @return string[]
     */
    private function construirOrdenEjecucion(array $stages, array $branches, string $firstStageId): array
    {
        $orden = [];
        $visitados = [];
        $nodoActual = $firstStageId;

        while ($nodoActual && ! in_array($nodoActual, $visitados)) {
            $stage = collect($stages)->firstWhere('id', $nodoActual);

            if ($stage) {
                $type = $stage['type'] ?? null;

                if (in_array($type, ['email', 'sms', 'stage', 'ambos', 'end'])) {
                    $orden[] = $nodoActual;
                }
            }

            $visitados[] = $nodoActual;

            $siguienteConexion = collect($branches)->firstWhere('source_node_id', $nodoActual);
            $nodoActual = $siguienteConexion['target_node_id'] ?? null;
        }

        return $orden;
    }

    /**
     * Filter out prospects that are already in the flow.
     *
     * @return array Prospect IDs not already in the flow
     */
    private function filtrarProspectosExistentes(Flujo $flujo, array $prospectoIds): array
    {
        $existentes = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->whereIn('prospecto_id', $prospectoIds)
            ->pluck('prospecto_id')
            ->toArray();

        return array_values(array_diff($prospectoIds, $existentes));
    }

    /**
     * Assign prospects to prospecto_en_flujo with NULL ultima_etapa_node_id.
     *
     * @return int Number of prospects assigned
     */
    private function asignarProspectos(Flujo $flujo, array $prospectoIds): int
    {
        $asignados = 0;
        $now = now();

        // Process in batches
        $chunks = array_chunk($prospectoIds, self::BATCH_SIZE);

        foreach ($chunks as $chunk) {
            $inserts = [];

            foreach ($chunk as $prospectoId) {
                $inserts[] = [
                    'flujo_id' => $flujo->id,
                    'prospecto_id' => $prospectoId,
                    'canal_asignado' => $this->determinarCanal($flujo),
                    'estado' => 'pendiente',
                    'etapa_actual_id' => null,
                    'ultima_etapa_node_id' => null, // New prospects start fresh
                    'fecha_inicio' => $now,
                    'completado' => false,
                    'cancelado' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            try {
                DB::table('prospecto_en_flujo')->insert($inserts);
                $asignados += count($inserts);
            } catch (\Exception $e) {
                Log::warning('AsignarProspectosAEjecucionPerpetua: Error en batch insert, reintentando uno por uno', [
                    'flujo_id' => $flujo->id,
                    'error' => $e->getMessage(),
                ]);

                // Insert one by one on batch failure
                foreach ($inserts as $insert) {
                    try {
                        DB::table('prospecto_en_flujo')->insert($insert);
                        $asignados++;
                    } catch (\Exception $individualError) {
                        // Silently skip duplicates
                    }
                }
            }
        }

        return $asignados;
    }

    /**
     * Update the execution's prospectos_ids with new prospects.
     */
    private function actualizarEjecucion(FlujoEjecucion $ejecucion, array $nuevosProspectoIds): void
    {
        $existingIds = $ejecucion->prospectos_ids ?? [];
        $mergedIds = array_values(array_unique(array_merge($existingIds, $nuevosProspectoIds)));

        $ejecucion->update([
            'prospectos_ids' => $mergedIds,
            'prospectos_count' => count($mergedIds),
        ]);
    }

    /**
     * Update the first stage's prospectos_ids with new prospects.
     */
    private function actualizarPrimeraEtapa(
        FlujoEjecucion $ejecucion,
        array $nuevosProspectoIds,
        StageOrderResolver $resolver
    ): void {
        $flujo = $ejecucion->flujo;
        $firstStageId = $resolver->getFirstStage($flujo);

        if (! $firstStageId) {
            return;
        }

        $primeraEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $firstStageId)
            ->first();

        if (! $primeraEtapa) {
            Log::warning('AsignarProspectosAEjecucionPerpetua: Primera etapa no encontrada', [
                'ejecucion_id' => $ejecucion->id,
                'node_id' => $firstStageId,
            ]);

            return;
        }

        $existingIds = $primeraEtapa->prospectos_ids ?? [];
        $mergedIds = array_values(array_unique(array_merge($existingIds, $nuevosProspectoIds)));

        $primeraEtapa->update([
            'prospectos_ids' => $mergedIds,
            'prospectos_count' => count($mergedIds),
        ]);

        Log::debug('AsignarProspectosAEjecucionPerpetua: Primera etapa actualizada', [
            'etapa_id' => $primeraEtapa->id,
            'node_id' => $firstStageId,
            'nuevos_prospectos' => count($nuevosProspectoIds),
            'total_prospectos' => count($mergedIds),
        ]);
    }

    /**
     * Determine the channel to assign based on the flow.
     */
    private function determinarCanal(Flujo $flujo): string
    {
        return match ($flujo->canal_envio) {
            'email' => 'email',
            'sms' => 'sms',
            'ambos' => 'email',
            default => 'email',
        };
    }
}
