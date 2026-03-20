<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
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

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
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

        // Buscar prospectos del mismo origen que NO están en este flujo
        $prospectosNuevos = $this->buscarProspectosNuevos($flujo);

        if ($prospectosNuevos->isEmpty()) {
            Log::info("No hay prospectos nuevos para flujo {$flujo->id}");

            return ['asignados' => 0, 'ejecucion_creada' => false];
        }

        Log::info("Encontrados {$prospectosNuevos->count()} prospectos nuevos para flujo {$flujo->id}");

        // Determinar canal basado en el flujo
        $canalAsignado = $this->determinarCanal($flujo);

        // Asignar en batches a prospecto_en_flujo
        $prospectoIds = $prospectosNuevos->pluck('id')->toArray();
        $asignados = $this->asignarProspectos($flujo, $prospectosNuevos, $canalAsignado);

        if ($asignados === 0) {
            Log::warning("No se pudo asignar ningún prospecto al flujo {$flujo->id}");

            return ['asignados' => 0, 'ejecucion_creada' => false];
        }

        Log::info("Asignados {$asignados} prospectos al flujo {$flujo->id}");

        // ✅ NUEVO: Crear FlujoEjecucion para esta cohorte
        $ejecucionCreada = $this->crearEjecucion($flujo, $prospectoIds, $configStructure);

        return [
            'asignados' => $asignados,
            'ejecucion_creada' => $ejecucionCreada,
        ];
    }

    /**
     * Busca prospectos que aún no están en el flujo.
     *
     * Prioridad de filtros:
     * 1. Si lotes_ids está definido → filtra por esos lotes específicos
     * 2. Si origen está definido → filtra por origen de importación
     * 3. Si ninguno está definido → no retorna prospectos (seguridad)
     *
     * OPTIMIZADO: Usa NOT EXISTS subquery en vez de pluck + whereNotIn.
     * Antes: Cargaba todos los IDs de prospectos_en_flujo en memoria (300k+ IDs)
     * Ahora: La DB maneja la exclusión internamente, sin cargar IDs en PHP
     */
    private function buscarProspectosNuevos(Flujo $flujo)
    {
        // Log filter criteria for debugging
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

            return collect();
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
            ->where('estado', 'activo')
            ->select('id', 'email', 'telefono')
            ->get();
    }

    /**
     * Asigna los prospectos al flujo.
     * Ya NO necesita etapa_actual_id (legacy) - el Flow Builder maneja el progreso.
     */
    private function asignarProspectos(Flujo $flujo, $prospectos, string $canalAsignado): int
    {
        $asignados = 0;
        $now = now();

        // Procesar en batches para evitar memory issues
        $prospectos->chunk(self::BATCH_SIZE)->each(function ($batch) use ($flujo, $canalAsignado, $now, &$asignados) {
            $inserts = [];

            foreach ($batch as $prospecto) {
                $inserts[] = [
                    'flujo_id' => $flujo->id,
                    'prospecto_id' => $prospecto->id,
                    'canal_asignado' => $canalAsignado,
                    'estado' => 'pendiente',
                    'etapa_actual_id' => null, // Flow Builder no usa esto
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
                Log::error('Error insertando batch de prospectos', [
                    'flujo_id' => $flujo->id,
                    'error' => $e->getMessage(),
                ]);

                // Insertar uno por uno si falla el batch (duplicados, etc)
                foreach ($inserts as $insert) {
                    try {
                        DB::table('prospecto_en_flujo')->insert($insert);
                        $asignados++;
                    } catch (\Exception $individualError) {
                        Log::debug('Error insertando prospecto individual', [
                            'prospecto_id' => $insert['prospecto_id'],
                            'error' => $individualError->getMessage(),
                        ]);
                    }
                }
            }
        });

        return $asignados;
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
                    ->filter(fn ($s) => in_array($s['type'] ?? '', ['email', 'sms', 'stage']))
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

                if (in_array($type, ['email', 'sms', 'stage', 'condition', 'end'])) {
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
