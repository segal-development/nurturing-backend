<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\ProspectoEnFlujo;
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
 * - Added to ALL pending stages' prospectos_ids
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

        // Filter out prospects already in the EXECUTION (not the flow)
        // Prospectos pueden estar en prospecto_en_flujo pero no en la ejecución activa
        $existentesEnEjecucion = $ejecucionPerpetua->prospectos()->pluck('prospectos.id')->toArray();
        $nuevosProspectoIds = array_values(array_diff($this->prospectoIds, $existentesEnEjecucion));

        if (empty($nuevosProspectoIds)) {
            Log::info('AsignarProspectosAEjecucionPerpetua: Todos los prospectos ya estan en la ejecucion', [
                'flujo_id' => $this->flujoId,
                'ejecucion_id' => $ejecucionPerpetua->id,
            ]);

            return;
        }

        // Check if prospects need to be added to prospecto_en_flujo
        // (they might already be there from a previous job)
        $yaEnFlujo = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->whereIn('prospecto_id', $nuevosProspectoIds)
            ->pluck('prospecto_id')
            ->toArray();

        $necesitanInsertar = array_values(array_diff($nuevosProspectoIds, $yaEnFlujo));

        // Assign only prospects NOT already in prospecto_en_flujo
        $asignados = 0;
        if (! empty($necesitanInsertar)) {
            $asignados = $this->asignarProspectos($flujo, $necesitanInsertar);
        }

        // Update execution's prospectos_ids (ALL nuevos, even if already in prospecto_en_flujo)
        $this->actualizarEjecucion($ejecucionPerpetua, $nuevosProspectoIds);

        // Agregar SOLO a la primera etapa del drip. La progresión a etapas posteriores se
        // hace via BatchCompletedCallback cuando la etapa actual completa (respeta tiempo_espera
        // entre stages). Antes esto agregaba a TODAS las etapas pendientes — eso rompía la
        // secuencia: un prospecto recién entrado se agregaba a la FEE de etapa 2 atascada de
        // hace días, y cuando esa FEE eventualmente disparaba, el prospecto recibía email 2
        // sin haber pasado por etapa 1 con su delay.
        $this->actualizarPrimeraEtapaPendiente($ejecucionPerpetua, $resolver, $flujo, $nuevosProspectoIds);

        Log::info('AsignarProspectosAEjecucionPerpetua: Completado', [
            'flujo_id' => $this->flujoId,
            'ejecucion_id' => $ejecucionPerpetua->id,
            'prospectos_asignados' => $asignados,
            'lote_id' => $this->loteId,
        ]);
    }

    /**
     * Get existing perpetual execution or create one if none exists.
     *
     * Para flujos perpetuos, la ejecución puede estar en:
     * - 'in_progress': procesando un batch
     * - 'waiting': sin trabajo activo, esperando nuevos prospectos
     * - 'paused': temporalmente pausada por circuit breaker
     * - 'completed': bug histórico — algunos perpetuos quedaron en este estado
     *
     * Todos son estados VIVOS para un flujo perpetuo; el sync debe reusarlos.
     * Si no encontramos ninguno, recién creamos uno nuevo.
     */
    private function obtenerOCrearEjecucionPerpetua(Flujo $flujo, StageOrderResolver $resolver): ?FlujoEjecucion
    {
        // Try to find ANY existing perpetual execution (in_progress, waiting, paused, completed)
        $ejecucion = FlujoEjecucion::where('flujo_id', $flujo->id)
            ->where('es_perpetuo', true)
            ->whereIn('estado', ['in_progress', 'waiting', 'paused', 'completed'])
            ->orderByDesc('id')
            ->first();

        if ($ejecucion) {
            // Si la ejecución está en un estado "muerto" (completed), reactivarla.
            // En flujos perpetuos, completed es siempre incorrecto — siempre deben estar vivos.
            if ($ejecucion->estado === 'completed') {
                Log::warning('AsignarProspectosAEjecucionPerpetua: Reactivando ejecucion perpetua que estaba en completed', [
                    'ejecucion_id' => $ejecucion->id,
                    'flujo_id' => $flujo->id,
                ]);
                $ejecucion->update(['estado' => 'waiting']);
                $ejecucion->refresh();
            }

            Log::info('AsignarProspectosAEjecucionPerpetua: Usando ejecucion perpetua existente', [
                'ejecucion_id' => $ejecucion->id,
                'estado' => $ejecucion->estado,
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
        $now = now();

        return ProspectoEnFlujo::crearBatch(
            $flujo,
            $prospectoIds,
            $this->determinarCanal($flujo),
            $now,
            ultimaEtapaNodeId: null,
        );
    }

    /**
     * Update the execution's prospectos_ids with new prospects.
     */
    private function actualizarEjecucion(FlujoEjecucion $ejecucion, array $nuevosProspectoIds): void
    {
        $ejecucion->prospectos()->syncWithoutDetaching($nuevosProspectoIds);
        $mergedIds = $ejecucion->prospectos()->pluck('prospectos.id')->toArray();

        $ejecucion->update([
            'prospectos_ids' => $mergedIds,
            'prospectos_count' => count($mergedIds),
        ]);
    }

    /**
     * Agrega los nuevos prospectos SOLO a la primera etapa del drip.
     *
     * En flujos perpetuos secuenciales, los prospectos progresan etapa por etapa
     * (BatchCompletedCallback promueve al siguiente al terminar la actual, respetando
     * tiempo_espera). Si los agregáramos a TODAS las etapas pendientes, se les enviaría
     * el email N sin haber pasado por etapas anteriores ni haber esperado los días
     * correspondientes.
     *
     * Para encontrar la "primera etapa": el target del branch initial-* del flujo.
     * Si la FEE de esa etapa no existe o está completed/executing, no agregamos —
     * BatchCompletedCallback o el siguiente ciclo del scheduler manejarán la creación.
     */
    private function actualizarPrimeraEtapaPendiente(
        FlujoEjecucion $ejecucion,
        StageOrderResolver $resolver,
        Flujo $flujo,
        array $nuevosProspectoIds
    ): void {
        $firstStageId = $resolver->getFirstStage($flujo);
        if (! $firstStageId) {
            Log::warning('AsignarProspectosAEjecucionPerpetua: Sin primera etapa identificable', [
                'ejecucion_id' => $ejecucion->id,
            ]);

            return;
        }

        $primeraEtapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $firstStageId)
            ->where('estado', 'pending')
            ->first();

        if (! $primeraEtapa) {
            Log::info('AsignarProspectosAEjecucionPerpetua: Primera etapa no está pendiente, omitiendo', [
                'ejecucion_id' => $ejecucion->id,
                'first_stage_id' => $firstStageId,
            ]);

            return;
        }

        $primeraEtapa->prospectos()->syncWithoutDetaching($nuevosProspectoIds);
        $mergedIds = $primeraEtapa->prospectos()->pluck('prospectos.id')->toArray();

        $primeraEtapa->update([
            'prospectos_ids' => $mergedIds,
            'prospectos_count' => count($mergedIds),
        ]);

        Log::info('AsignarProspectosAEjecucionPerpetua: Primera etapa actualizada', [
            'ejecucion_id' => $ejecucion->id,
            'first_etapa_id' => $primeraEtapa->id,
            'nuevos_prospectos' => count($nuevosProspectoIds),
            'total_en_etapa' => count($mergedIds),
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
