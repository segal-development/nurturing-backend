<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job para asignar automáticamente nuevos prospectos a flujos activos.
 *
 * Este job busca flujos con `auto_asignar_nuevos = true` y asigna
 * prospectos del mismo origen que aún no están en el flujo.
 *
 * ARQUITECTURA (Opción A - Cohortes):
 * 1. Busca prospectos nuevos del mismo origen
 * 2. Los inserta en `prospecto_en_flujo`
 * 3. Crea una `FlujoEjecucion` para esta cohorte
 * 4. El cron `EjecutarNodosProgramados` los procesa
 *
 * Se ejecuta los viernes a las 7am (después del sync de Sysgal/IC).
 */
class AsignarNuevosProspectosAFlujoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800; // 30 minutos

    public int $tries = 3;

    private const BATCH_SIZE = 500;

    public function __construct(private ?array $asignacionEspecifica = null)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // Asignación por ID (onboarding doble membresía): mete prospectos puntuales al flujo aunque
        // ya estén en otro flujo. Ruta separada y gateada — la asignación normal por lote no cambia.
        if ($this->asignacionEspecifica !== null) {
            $this->handleAsignacionEspecifica();

            return;
        }

        Log::info('=== Iniciando AsignarNuevosProspectosAFlujoJob ===');

        // Buscar flujos activos con auto_asignar_nuevos = true
        $flujos = Flujo::where('activo', true)
            ->where('auto_asignar_nuevos', true)
            ->get();

        if ($flujos->isEmpty()) {
            Log::info('No hay flujos con auto_asignar_nuevos activo');

            return;
        }

        Log::info("Encontrados {$flujos->count()} flujos con auto-asignación activa");

        $totalAsignados = 0;
        $totalEjecuciones = 0;

        foreach ($flujos as $flujo) {
            $resultado = $this->procesarFlujo($flujo);
            $totalAsignados += $resultado['asignados'];
            $totalEjecuciones += $resultado['ejecucion_creada'] ? 1 : 0;
        }

        Log::info('=== AsignarNuevosProspectosAFlujoJob completado ===', [
            'flujos_procesados' => $flujos->count(),
            'total_asignados' => $totalAsignados,
            'ejecuciones_creadas' => $totalEjecuciones,
        ]);
    }

    /**
     * Asignación por ID a un flujo puntual (onboarding doble membresía).
     *
     * Mete los prospectos indicados al flujo AUNQUE ya estén en otro flujo, con el guard
     * "no está ya en ESTE flujo" para no duplicar envíos. Reusa asignarBatch + la ejecución
     * perpetua igual que procesarFlujo, así el comportamiento (canal, perpetua) es idéntico.
     * Solo procesa los IDs recibidos (lo de un sync puntual), nunca consulta el backlog.
     */
    private function handleAsignacionEspecifica(): void
    {
        $flujoId = $this->asignacionEspecifica['flujo_id'] ?? null;
        $ids = $this->asignacionEspecifica['prospecto_ids'] ?? [];

        if (! $flujoId || empty($ids)) {
            return;
        }

        $flujo = Flujo::find($flujoId);

        if (! $flujo || ! $flujo->activo) {
            Log::warning("AsignacionEspecifica: flujo {$flujoId} inexistente o inactivo, saltando");

            return;
        }

        $configStructure = $flujo->config_structure;

        if (empty($configStructure) || empty($configStructure['stages'])) {
            Log::warning("AsignacionEspecifica: flujo {$flujo->id} sin config_structure válido, saltando");

            return;
        }

        // Solo prospectos activos que NO estén YA en ESTE flujo (anti doble-envío).
        $batch = Prospecto::whereIn('id', $ids)
            ->where('estado', 'activo')
            ->whereDoesntHave('prospectosEnFlujo', function ($q) use ($flujo) {
                $q->where('flujo_id', $flujo->id);
            })
            ->select('id', 'email', 'telefono')
            ->get();

        if ($batch->isEmpty()) {
            Log::info("AsignacionEspecifica: flujo {$flujo->id}, nada para asignar (ya estaban o inactivos)");

            return;
        }

        $canalAsignado = $this->determinarCanal($flujo);
        $asignados = $this->asignarBatch($flujo, $batch, $canalAsignado);
        $prospectoIds = $batch->pluck('id')->toArray();

        Log::info("AsignacionEspecifica: asignados {$asignados} prospectos al flujo {$flujo->id} (onboarding doble membresía)");

        // Perpetuo con ejecución activa: sumarlos a la ejecución en curso (igual que procesarFlujo).
        if ($flujo->es_perpetuo) {
            $ejecucionExistente = FlujoEjecucion::where('flujo_id', $flujo->id)
                ->where('es_perpetuo', true)
                ->whereIn('estado', ['in_progress', 'waiting'])
                ->first();

            if ($ejecucionExistente) {
                AsignarProspectosAEjecucionPerpetua::dispatch($flujo->id, $prospectoIds);

                return;
            }
        }

        // Sin ejecución activa: crear una para esta cohorte.
        $this->crearEjecucion($flujo, $prospectoIds, $configStructure);
    }

    /**
     * Procesa un flujo y asigna los prospectos nuevos que correspondan.
     *
     * @return array{asignados: int, ejecucion_creada: bool}
     */
    private function procesarFlujo(Flujo $flujo): array
    {
        Log::info("Procesando flujo: {$flujo->nombre}", [
            'flujo_id' => $flujo->id,
            'lotes_ids' => $flujo->lotes_ids,
            'origen' => $flujo->origen,
            'nivel_deuda_target' => $flujo->nivel_deuda_target,
        ]);

        // ✅ Verificar que el flujo tenga config_structure (Flow Builder)
        $configStructure = $flujo->config_structure;

        if (empty($configStructure) || empty($configStructure['stages'])) {
            Log::warning("Flujo {$flujo->id} no tiene config_structure válido, saltando", [
                'tiene_config_structure' => ! empty($configStructure),
                'tiene_stages' => ! empty($configStructure['stages'] ?? null),
            ]);

            return ['asignados' => 0, 'ejecucion_creada' => false];
        }

        // Build the query for new prospects (not yet in this flujo)
        $query = $this->buildProspectosNuevosQuery($flujo);

        if ($query === null) {
            return ['asignados' => 0, 'ejecucion_creada' => false];
        }

        // Count first to avoid loading everything into memory unnecessarily
        $totalNuevos = $query->count();

        if ($totalNuevos === 0) {
            Log::info("No hay prospectos nuevos para flujo {$flujo->id}");

            // Para flujos perpetuos: verificar si hay prospectos pendientes sin ejecución activa
            $ejecucionCreada = $this->verificarYCrearEjecucionPendiente($flujo, $configStructure);

            return ['asignados' => 0, 'ejecucion_creada' => $ejecucionCreada];
        }

        Log::info("Encontrados {$totalNuevos} prospectos nuevos para flujo {$flujo->id}");

        // Determinar canal basado en el flujo
        $canalAsignado = $this->determinarCanal($flujo);

        // Collect IDs and assign in chunks to avoid memory issues with 87k+ prospects
        $prospectoIds = [];
        $asignados = 0;

        $query->select('id', 'email', 'telefono')
            ->chunkById(self::BATCH_SIZE, function ($batch) use ($flujo, $canalAsignado, &$prospectoIds, &$asignados) {
                $prospectoIds = array_merge($prospectoIds, $batch->pluck('id')->toArray());
                $asignados += $this->asignarBatch($flujo, $batch, $canalAsignado);
            });

        if ($asignados === 0) {
            Log::warning("No se pudo asignar ningún prospecto al flujo {$flujo->id}");

            return ['asignados' => 0, 'ejecucion_creada' => false];
        }

        Log::info("Asignados {$asignados} prospectos al flujo {$flujo->id}");

        // Para flujos perpetuos: agregar a ejecución existente en vez de crear nueva
        if ($flujo->es_perpetuo) {
            $ejecucionExistente = FlujoEjecucion::where('flujo_id', $flujo->id)
                ->where('es_perpetuo', true)
                ->whereIn('estado', ['in_progress', 'waiting'])
                ->first();
            
            if ($ejecucionExistente) {
                Log::info("Flujo perpetuo {$flujo->id}: agregando prospectos a ejecución existente", [
                    'ejecucion_id' => $ejecucionExistente->id,
                    'nuevos_prospectos' => count($prospectoIds),
                ]);
                
                // Dispatch job para agregar a la ejecución existente
                AsignarProspectosAEjecucionPerpetua::dispatch($flujo->id, $prospectoIds);
                
                return [
                    'asignados' => $asignados,
                    'ejecucion_creada' => false,
                    'agregados_a_perpetua' => true,
                ];
            }
        }

        // Crear FlujoEjecucion para esta cohorte (flujos no perpetuos o perpetuos sin ejecución)
        $ejecucionCreada = $this->crearEjecucion($flujo, $prospectoIds, $configStructure);

        return [
            'asignados' => $asignados,
            'ejecucion_creada' => $ejecucionCreada,
        ];
    }

    /**
     * Builds the query for prospects not yet in the flujo.
     *
     * Returns the query builder (NOT a collection) so the caller can use
     * chunkById() to process results without loading 87k+ models into memory.
     *
     * Prioridad de filtros:
     * 1. Si lotes_ids está definido → filtra por esos lotes específicos
     * 2. Si origen está definido → filtra por origen de importación
     * 3. Si ninguno está definido → no retorna prospectos (seguridad)
     *
     * Uses NOT EXISTS subquery for the "not already in flujo" check —
     * the DB handles the exclusion internally without parameter overhead.
     *
     * @return \Illuminate\Database\Eloquent\Builder|null
     */
    private function buildProspectosNuevosQuery(Flujo $flujo)
    {
        Log::info("Buscando prospectos nuevos para flujo {$flujo->id}", [
            'lotes_ids' => $flujo->lotes_ids,
            'origen' => $flujo->origen,
            'nivel_deuda_target' => $flujo->nivel_deuda_target,
            'filtro_usado' => ! empty($flujo->lotes_ids) ? 'lotes_ids' : ($flujo->origen ? 'origen' : 'ninguno'),
        ]);

        $query = Prospecto::query();

        // Filter by importacion (lotes_ids or origen)
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

        // Safety guard: if no filtering criteria at all, don't return prospects
        if (! $hasImportacionFilter && ! $flujo->usarFiltroNivelDeuda()) {
            Log::warning("Flujo {$flujo->id} no tiene lotes_ids, origen, ni nivel_deuda_target definido");

            return null;
        }

        // Warn when only nivel_deuda_target is set (no origen/lotes_ids)
        if (! $hasImportacionFilter && $flujo->usarFiltroNivelDeuda()) {
            Log::warning("Flujo {$flujo->id} solo tiene nivel_deuda_target definido (sin lotes_ids ni origen), filtrando todos los prospectos por nivel_deuda");
        }

        // Filter by nivel_deuda when flujo has nivel_deuda_target set
        if ($flujo->usarFiltroNivelDeuda()) {
            $nivelDeudaTarget = $flujo->nivel_deuda_target;
            $query->where(function ($q) use ($nivelDeudaTarget) {
                $q->whereIn(
                    DB::raw("metadata->>'nivel_deuda'"),
                    $nivelDeudaTarget
                );

                // If 'sin_informacion' is in target, also include NULL nivel_deuda
                if (in_array('sin_informacion', $nivelDeudaTarget)) {
                    $q->orWhereNull(DB::raw("metadata->>'nivel_deuda'"));
                    $q->orWhere(DB::raw("metadata->>'nivel_deuda'"), '');
                }
            });
        }

        return $query
            ->whereDoesntHave('prospectosEnFlujo', function ($q) use ($flujo) {
                $q->where('flujo_id', $flujo->id);
            })
            ->where('estado', 'activo');
    }

    /**
     * Asigna un batch de prospectos al flujo.
     *
     * Called per-chunk from procesarFlujo() — each batch is already ≤ BATCH_SIZE.
     * Ya NO necesita etapa_actual_id (legacy) - el Flow Builder maneja el progreso.
     */
    private function asignarBatch(Flujo $flujo, $batch, string $canalAsignado): int
    {
        $now = now();
        $prospectoIds = collect($batch)->pluck('id')->map(fn ($id) => (int) $id)->values()->toArray();

        return ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, $canalAsignado, $now);
    }

    /**
     * Para flujos perpetuos: verifica si hay prospectos asignados pero sin ejecución activa.
     * Si es así, crea una nueva ejecución para procesarlos.
     */
    private function verificarYCrearEjecucionPendiente(Flujo $flujo, array $configStructure): bool
    {
        // Solo para flujos perpetuos
        if (! $flujo->es_perpetuo) {
            return false;
        }

        // Verificar si hay una ejecución activa o en espera
        $ejecucionActiva = FlujoEjecucion::where('flujo_id', $flujo->id)
            ->whereIn('estado', ['in_progress', 'paused', 'waiting', 'pending'])
            ->exists();

        if ($ejecucionActiva) {
            Log::info("Flujo perpetuo {$flujo->id} ya tiene ejecución activa");
            return false;
        }

        // Buscar prospectos pendientes (asignados pero no completados)
        $prospectosPendientes = \App\Models\ProspectoEnFlujo::where('flujo_id', $flujo->id)
            ->where('completado', false)
            ->where('cancelado', false)
            ->pluck('prospecto_id')
            ->toArray();

        if (empty($prospectosPendientes)) {
            Log::info("Flujo perpetuo {$flujo->id} no tiene prospectos pendientes");
            return false;
        }

        Log::info("Flujo perpetuo tiene prospectos pendientes sin ejecución, creando ejecución", [
            'flujo_id' => $flujo->id,
            'prospectos_pendientes' => count($prospectosPendientes),
        ]);

        return $this->crearEjecucion($flujo, $prospectosPendientes, $configStructure);
    }

    /**
     * Crea una FlujoEjecucion para la cohorte de nuevos prospectos.
     *
     * Replica la lógica de FlujoEjecucionController::execute() pero sin request.
     */
    private function crearEjecucion(Flujo $flujo, array $prospectoIds, array $configStructure): bool
    {
        try {
            $stages = $configStructure['stages'] ?? [];
            $branches = $configStructure['branches'] ?? [];
            $initialNode = $configStructure['initial_node'] ?? null;

            // Encontrar el nodo inicial
            $startNodeId = $initialNode;

            if (! $startNodeId) {
                // Fallback: buscar nodo tipo 'start'
                $startNode = collect($stages)->firstWhere('type', 'start');
                $startNodeId = $startNode['id'] ?? null;
            }

            if (! $startNodeId) {
                Log::error("Flujo {$flujo->id} no tiene nodo inicial definido");

                return false;
            }

            // Buscar la primera etapa después del start
            $primeraConexion = collect($branches)->firstWhere('source_node_id', $startNodeId);

            if (! $primeraConexion) {
                // Fallback: primera etapa por orden
                $primeraEtapa = collect($stages)
                    ->filter(fn ($s) => in_array($s['type'] ?? '', ['email', 'sms', 'stage', 'ambos']))
                    ->sortBy('orden')
                    ->first();

                if (! $primeraEtapa) {
                    Log::error("Flujo {$flujo->id} no tiene etapas ejecutables");

                    return false;
                }

                $primeraEtapaId = $primeraEtapa['id'];
            } else {
                $primeraEtapaId = $primeraConexion['target_node_id'];
            }

            $primeraEtapa = collect($stages)->firstWhere('id', $primeraEtapaId);

            if (! $primeraEtapa) {
                Log::error("No se encontró la primera etapa {$primeraEtapaId} en el flujo");

                return false;
            }

            // Calcular fechas
            $fechaInicio = now();
            $tiempoEsperaPrimeraEtapa = $primeraEtapa['tiempo_espera'] ?? 0;
            $fechaEjecucionPrimeraEtapa = $fechaInicio->copy()->addDays($tiempoEsperaPrimeraEtapa);

            // Crear la ejecución
            $ejecucion = FlujoEjecucion::create([
                'flujo_id' => $flujo->id,
                'origen_id' => null, // Auto-asignación no tiene origen específico
                'prospectos_ids' => $prospectoIds,
                'prospectos_count' => count($prospectoIds),
                'fecha_inicio_programada' => $fechaInicio,
                'fecha_inicio_real' => $fechaInicio,
                'estado' => 'in_progress',
                'es_perpetuo' => $flujo->es_perpetuo ?? false,
                'nodo_actual' => null,
                'proximo_nodo' => $primeraEtapaId,
                'fecha_proximo_nodo' => $fechaEjecucionPrimeraEtapa,
                'config' => [
                    'created_from' => 'auto_asignar_nuevos',
                    'job_run_at' => now()->toISOString(),
                    'total_prospectos' => count($prospectoIds),
                    'nivel_deuda_target' => $flujo->nivel_deuda_target,
                ],
            ]);

            Log::info('FlujoEjecucion creada para auto-asignación', [
                'ejecucion_id' => $ejecucion->id,
                'flujo_id' => $flujo->id,
                'prospectos_count' => count($prospectoIds),
                'primera_etapa_id' => $primeraEtapaId,
                'fecha_proximo_nodo' => $fechaEjecucionPrimeraEtapa,
            ]);

            // Crear FlujoEjecucionEtapa para cada nodo
            $this->crearEtapasEjecucion($ejecucion, $stages, $branches, $primeraEtapaId, $fechaInicio, $prospectoIds);

            return true;
        } catch (\Exception $e) {
            Log::error("Error creando FlujoEjecucion para flujo {$flujo->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Crea los registros de FlujoEjecucionEtapa para cada nodo del flujo.
     */
    private function crearEtapasEjecucion(
        FlujoEjecucion $ejecucion,
        array $stages,
        array $branches,
        string $primeraEtapaId,
        \Carbon\Carbon $fechaInicio,
        array $prospectoIds
    ): void {
        // Construir el orden de ejecución siguiendo las conexiones
        $ordenEjecucion = $this->construirOrdenEjecucion($stages, $branches, $primeraEtapaId);

        $fechaBase = $fechaInicio->copy();
        $primeraCreada = false;

        foreach ($ordenEjecucion as $index => $stageId) {
            $stage = collect($stages)->firstWhere('id', $stageId);
            if (! $stage) {
                continue;
            }

            // Calcular fecha programada acumulativa
            $tiempoEspera = $stage['tiempo_espera'] ?? 0;
            $fechaProgramada = $fechaBase->copy()->addDays($tiempoEspera);

            $etapaData = [
                'flujo_ejecucion_id' => $ejecucion->id,
                'etapa_id' => null,
                'node_id' => $stageId,
                'fecha_programada' => $fechaProgramada,
                'estado' => 'pending',
                'ejecutado' => false,
            ];

            // La primera etapa necesita los prospectos_ids
            if (! $primeraCreada) {
                $etapaData['prospectos_ids'] = $prospectoIds;
                $etapaData['prospectos_count'] = count($prospectoIds);
                $primeraCreada = true;
            }

            FlujoEjecucionEtapa::create($etapaData);

            // La fecha base para la siguiente etapa es la fecha programada de esta
            $fechaBase = $fechaProgramada->copy();
        }

        Log::info('Etapas de ejecución creadas', [
            'ejecucion_id' => $ejecucion->id,
            'total_etapas' => count($ordenEjecucion),
        ]);
    }

    /**
     * Construye el orden de ejecución siguiendo las conexiones del flujo.
     *
     * Replica la lógica de FlujoEjecucionController::construirOrdenEjecucion()
     */
    private function construirOrdenEjecucion(array $stages, array $branches, string $primeraEtapaId): array
    {
        $orden = [];
        $visitados = [];
        $nodoActual = $primeraEtapaId;

        while ($nodoActual && ! in_array($nodoActual, $visitados)) {
            $stage = collect($stages)->firstWhere('id', $nodoActual);

            if ($stage) {
                $type = $stage['type'] ?? null;

                if (in_array($type, ['email', 'sms', 'stage', 'ambos', 'condition', 'end'])) {
                    $orden[] = $nodoActual;
                }
            }

            $visitados[] = $nodoActual;

            // Buscar siguiente conexión
            $siguienteConexion = collect($branches)->firstWhere('source_node_id', $nodoActual);
            $nodoActual = $siguienteConexion['target_node_id'] ?? null;
        }

        return $orden;
    }

    /**
     * Determina el canal a asignar basándose en el flujo.
     */
    private function determinarCanal(Flujo $flujo): string
    {
        return match ($flujo->canal_envio) {
            'email' => 'email',
            'sms' => 'sms',
            'ambos' => 'email', // Default para flujos mixtos
            default => 'email',
        };
    }
}
