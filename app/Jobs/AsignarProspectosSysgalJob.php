<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job para asignar automáticamente nuevos prospectos de Sysgal a flujos existentes.
 *
 * DIFERENCIA con AsignarNuevosProspectosAFlujoJob:
 * - Este job clasifica por nivel_deuda (metadata->nivel_deuda)
 * - Agrega prospectos a EJECUCIONES EXISTENTES (no crea nuevas)
 * - Es específico para el origen Sysgal
 *
 * MAPEO nivel_deuda → Flujo:
 * - baja, sin_informacion, null → SEGMENTO 1 (id: 39)
 * - media                       → SEGMENTO 2 (id: 40)
 * - alta                        → SEGMENTO 3 (id: 41)
 *
 * Se ejecuta los viernes a las 7am (después del sync de Sysgal).
 */
class AsignarProspectosSysgalJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800; // 30 minutos

    public int $tries = 3;

    private const BATCH_SIZE = 500;

    /**
     * Mapeo de nivel_deuda a flujo_id.
     * Configurado según los segmentos de Grupo Segal.
     */
    private const NIVEL_DEUDA_FLUJO_MAP = [
        'baja' => 39,            // SEGMENTO 1
        'sin_informacion' => 39, // SEGMENTO 1
        'null' => 39,            // SEGMENTO 1 (prospectos sin nivel_deuda)
        'media' => 40,           // SEGMENTO 2
        'alta' => 41,            // SEGMENTO 3
    ];

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info('=== Iniciando AsignarProspectosSysgalJob ===');

        // Buscar prospectos de Sysgal que NO están en ningún flujo de segmento
        $prospectosNuevos = $this->buscarProspectosNuevosSysgal();

        if ($prospectosNuevos->isEmpty()) {
            Log::info('No hay prospectos nuevos de Sysgal para asignar');

            return;
        }

        Log::info("Encontrados {$prospectosNuevos->count()} prospectos nuevos de Sysgal");

        // Agrupar por nivel_deuda
        $prospectosPorNivel = $this->agruparPorNivelDeuda($prospectosNuevos);

        $totalAsignados = 0;
        $totalAgregadosAEjecucion = 0;

        foreach ($prospectosPorNivel as $nivelDeuda => $prospectos) {
            $flujoId = self::NIVEL_DEUDA_FLUJO_MAP[$nivelDeuda] ?? null;

            if (! $flujoId) {
                Log::warning("Nivel de deuda '{$nivelDeuda}' no tiene flujo mapeado, saltando", [
                    'prospectos_count' => count($prospectos),
                ]);

                continue;
            }

            $resultado = $this->procesarGrupo($flujoId, $prospectos, $nivelDeuda);
            $totalAsignados += $resultado['asignados'];
            $totalAgregadosAEjecucion += $resultado['agregados_a_ejecucion'];
        }

        Log::info('=== AsignarProspectosSysgalJob completado ===', [
            'total_prospectos_nuevos' => $prospectosNuevos->count(),
            'total_asignados_a_flujos' => $totalAsignados,
            'total_agregados_a_ejecuciones' => $totalAgregadosAEjecucion,
        ]);
    }

    /**
     * Busca prospectos de Sysgal que no están en ningún flujo de segmento.
     */
    private function buscarProspectosNuevosSysgal()
    {
        $flujoIds = array_unique(array_values(self::NIVEL_DEUDA_FLUJO_MAP));

        return Prospecto::query()
            // Filtrar por source = sysgal en metadata
            ->whereRaw("metadata->>'source' = ?", ['sysgal'])
            // Que no estén en ninguno de los flujos de segmento
            ->whereDoesntHave('prospectosEnFlujo', function ($q) use ($flujoIds) {
                $q->whereIn('flujo_id', $flujoIds);
            })
            // Solo activos
            ->where('estado', 'activo')
            // Seleccionar campos necesarios incluyendo metadata para nivel_deuda
            ->select('id', 'email', 'telefono', 'metadata')
            ->get();
    }

    /**
     * Agrupa los prospectos por nivel de deuda.
     *
     * @return array<string, array<int, Prospecto>>
     */
    private function agruparPorNivelDeuda($prospectos): array
    {
        $grupos = [];

        foreach ($prospectos as $prospecto) {
            $metadata = $prospecto->metadata ?? [];
            $nivelDeuda = $metadata['nivel_deuda'] ?? null;

            // Normalizar null y sin_informacion al mismo grupo
            if ($nivelDeuda === null || $nivelDeuda === '') {
                $nivelDeuda = 'null';
            }

            if (! isset($grupos[$nivelDeuda])) {
                $grupos[$nivelDeuda] = [];
            }

            $grupos[$nivelDeuda][] = $prospecto;
        }

        // Log de distribución
        foreach ($grupos as $nivel => $lista) {
            Log::info("Nivel '{$nivel}': ".count($lista).' prospectos');
        }

        return $grupos;
    }

    /**
     * Procesa un grupo de prospectos para un flujo específico.
     * IMPORTANTE: Crea una NUEVA ejecución (cohorte) para que los prospectos
     * empiecen desde la primera etapa del flujo.
     *
     * @param  array<int, Prospecto>  $prospectos
     * @return array{asignados: int, agregados_a_ejecucion: int}
     */
    private function procesarGrupo(int $flujoId, array $prospectos, string $nivelDeuda): array
    {
        $flujo = Flujo::find($flujoId);

        if (! $flujo) {
            Log::error("Flujo {$flujoId} no encontrado");

            return ['asignados' => 0, 'agregados_a_ejecucion' => 0];
        }

        Log::info("Procesando grupo para flujo: {$flujo->nombre}", [
            'flujo_id' => $flujoId,
            'nivel_deuda' => $nivelDeuda,
            'prospectos_count' => count($prospectos),
        ]);

        // 1. Asignar prospectos a prospecto_en_flujo
        $canalAsignado = $this->determinarCanal($flujo);
        $prospectoIds = collect($prospectos)->pluck('id')->toArray();
        $asignados = $this->asignarProspectos($flujo, $prospectos, $canalAsignado);

        if ($asignados === 0) {
            Log::warning("No se pudo asignar ningún prospecto al flujo {$flujoId}");

            return ['asignados' => 0, 'agregados_a_ejecucion' => 0];
        }

        // 2. Crear NUEVA ejecución para que empiecen desde la primera etapa
        $ejecucionCreada = $this->crearNuevaEjecucion($flujo, $prospectoIds, $nivelDeuda);

        if (! $ejecucionCreada) {
            Log::warning("No se pudo crear ejecución para flujo {$flujoId}, prospectos quedan asignados pero no en ejecución", [
                'flujo_id' => $flujoId,
                'asignados' => $asignados,
            ]);

            return ['asignados' => $asignados, 'agregados_a_ejecucion' => 0];
        }

        return ['asignados' => $asignados, 'agregados_a_ejecucion' => count($prospectoIds)];
    }

    /**
     * Crea una NUEVA ejecución para los prospectos nuevos.
     * Así empiezan desde la primera etapa del flujo.
     */
    private function crearNuevaEjecucion(Flujo $flujo, array $prospectoIds, string $nivelDeuda): bool
    {
        try {
            $configStructure = $flujo->config_structure;

            if (empty($configStructure) || empty($configStructure['stages'])) {
                Log::error("Flujo {$flujo->id} no tiene config_structure válido");

                return false;
            }

            $stages = $configStructure['stages'] ?? [];
            $branches = $configStructure['branches'] ?? [];

            // Buscar el nodo inicial (start)
            $startNodeId = null;
            foreach ($stages as $stage) {
                if (($stage['type'] ?? '') === 'initial' || ($stage['type'] ?? '') === 'start') {
                    $startNodeId = $stage['id'];
                    break;
                }
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

            // Calcular fechas - empezar desde ahora
            $fechaInicio = now();
            $tiempoEsperaPrimeraEtapa = $primeraEtapa['tiempo_espera'] ?? 0;
            $fechaEjecucionPrimeraEtapa = $fechaInicio->copy()->addDays($tiempoEsperaPrimeraEtapa);

            // Crear la ejecución
            $ejecucion = FlujoEjecucion::create([
                'flujo_id' => $flujo->id,
                'origen_id' => null,
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
                    'created_from' => 'auto_asignar_sysgal',
                    'nivel_deuda' => $nivelDeuda,
                    'job_run_at' => now()->toISOString(),
                    'total_prospectos' => count($prospectoIds),
                ],
            ]);

            Log::info('Nueva FlujoEjecucion creada para prospectos Sysgal', [
                'ejecucion_id' => $ejecucion->id,
                'flujo_id' => $flujo->id,
                'nivel_deuda' => $nivelDeuda,
                'prospectos_count' => count($prospectoIds),
                'primera_etapa_id' => $primeraEtapaId,
                'fecha_proximo_nodo' => $fechaEjecucionPrimeraEtapa,
            ]);

            // Crear FlujoEjecucionEtapa para cada nodo del flujo
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

        foreach ($ordenEjecucion as $stageId) {
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
     */
    private function construirOrdenEjecucion(array $stages, array $branches, string $primeraEtapaId): array
    {
        $orden = [];
        $visitados = [];
        $nodoActual = $primeraEtapaId;

        while ($nodoActual && ! in_array($nodoActual, $visitados)) {
            $stage = collect($stages)->firstWhere('id', $nodoActual);

            if (! $stage) {
                break;
            }

            $visitados[] = $nodoActual;

            // Solo agregar nodos ejecutables (no start, no end)
            $tipo = $stage['type'] ?? null;
            if (in_array($tipo, ['email', 'sms', 'stage', 'ambos', 'condition'])) {
                $orden[] = $nodoActual;
            }

            // Buscar la siguiente conexión
            $siguienteConexion = collect($branches)->firstWhere('source_node_id', $nodoActual);

            if ($siguienteConexion) {
                $nodoActual = $siguienteConexion['target_node_id'];
            } else {
                break;
            }
        }

        return $orden;
    }

    /**
     * Asigna los prospectos al flujo en prospecto_en_flujo.
     *
     * @param  array<int, Prospecto>  $prospectos
     */
    private function asignarProspectos(Flujo $flujo, array $prospectos, string $canalAsignado): int
    {
        $now = now();
        $prospectoIds = array_map(fn ($p) => (int) $p->id, $prospectos);

        return ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, $canalAsignado, $now);
    }

    /**
     * Determina el canal a asignar basándose en el flujo.
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
