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

        // 2. Buscar ejecución activa existente
        $ejecucionActiva = FlujoEjecucion::where('flujo_id', $flujoId)
            ->where('estado', 'in_progress')
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $ejecucionActiva) {
            Log::warning("No hay ejecución activa para flujo {$flujoId}, los prospectos quedan asignados pero no en ejecución", [
                'flujo_id' => $flujoId,
                'asignados' => $asignados,
            ]);

            return ['asignados' => $asignados, 'agregados_a_ejecucion' => 0];
        }

        // 3. Agregar prospectos a la ejecución existente
        $agregados = $this->agregarAEjecucionExistente($ejecucionActiva, $prospectoIds);

        return ['asignados' => $asignados, 'agregados_a_ejecucion' => $agregados];
    }

    /**
     * Agrega prospectos a una ejecución existente.
     *
     * Los nuevos prospectos se agregan a:
     * 1. prospectos_ids de la FlujoEjecucion
     * 2. prospectos_ids de la primera etapa pendiente (para que se envíen en el próximo nodo)
     */
    private function agregarAEjecucionExistente(FlujoEjecucion $ejecucion, array $nuevosProspectoIds): int
    {
        $cantidadNuevos = count($nuevosProspectoIds);

        // Obtener prospectos actuales de la ejecución
        $prospectosActuales = $ejecucion->prospectos_ids ?? [];

        // Merge evitando duplicados
        $prospectosActualizados = array_values(array_unique(
            array_merge($prospectosActuales, $nuevosProspectoIds)
        ));

        // Actualizar la ejecución
        $ejecucion->update([
            'prospectos_ids' => $prospectosActualizados,
            'config' => array_merge($ejecucion->config ?? [], [
                'last_auto_assign' => now()->toISOString(),
                'last_auto_assign_count' => $cantidadNuevos,
                'total_prospectos' => count($prospectosActualizados),
            ]),
        ]);

        Log::info('Prospectos agregados a ejecución existente', [
            'ejecucion_id' => $ejecucion->id,
            'flujo_id' => $ejecucion->flujo_id,
            'prospectos_anteriores' => count($prospectosActuales),
            'prospectos_nuevos' => $cantidadNuevos,
            'prospectos_total' => count($prospectosActualizados),
        ]);

        // Buscar la próxima etapa pendiente para agregar los prospectos
        $proximaEtapaPendiente = $this->buscarProximaEtapaPendiente($ejecucion);

        if ($proximaEtapaPendiente) {
            $this->agregarProspectosAEtapa($proximaEtapaPendiente, $nuevosProspectoIds);
        } else {
            Log::warning('No hay etapa pendiente para agregar nuevos prospectos', [
                'ejecucion_id' => $ejecucion->id,
            ]);
        }

        return $cantidadNuevos;
    }

    /**
     * Busca la próxima etapa pendiente de una ejecución.
     *
     * Prioridad:
     * 1. Etapa con node_id = proximo_nodo de la ejecución
     * 2. Primera etapa con estado = 'pending'
     */
    private function buscarProximaEtapaPendiente(FlujoEjecucion $ejecucion): ?FlujoEjecucionEtapa
    {
        // Primero buscar por proximo_nodo
        if ($ejecucion->proximo_nodo) {
            $etapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
                ->where('node_id', $ejecucion->proximo_nodo)
                ->where('estado', 'pending')
                ->first();

            if ($etapa) {
                return $etapa;
            }
        }

        // Fallback: primera etapa pendiente por fecha
        return FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('estado', 'pending')
            ->orderBy('fecha_programada', 'asc')
            ->first();
    }

    /**
     * Agrega prospectos a una etapa existente.
     */
    private function agregarProspectosAEtapa(FlujoEjecucionEtapa $etapa, array $nuevosProspectoIds): void
    {
        $prospectosActuales = $etapa->prospectos_ids ?? [];

        // Merge evitando duplicados
        $prospectosActualizados = array_values(array_unique(
            array_merge($prospectosActuales, $nuevosProspectoIds)
        ));

        $etapa->update([
            'prospectos_ids' => $prospectosActualizados,
        ]);

        Log::info('Prospectos agregados a etapa pendiente', [
            'etapa_id' => $etapa->id,
            'node_id' => $etapa->node_id,
            'prospectos_anteriores' => count($prospectosActuales),
            'prospectos_nuevos' => count($nuevosProspectoIds),
            'prospectos_total' => count($prospectosActualizados),
        ]);
    }

    /**
     * Asigna los prospectos al flujo en prospecto_en_flujo.
     *
     * @param  array<int, Prospecto>  $prospectos
     */
    private function asignarProspectos(Flujo $flujo, array $prospectos, string $canalAsignado): int
    {
        $asignados = 0;
        $now = now();

        // Procesar en batches para evitar memory issues
        $chunks = array_chunk($prospectos, self::BATCH_SIZE);

        foreach ($chunks as $batch) {
            $inserts = [];

            foreach ($batch as $prospecto) {
                $inserts[] = [
                    'flujo_id' => $flujo->id,
                    'prospecto_id' => $prospecto->id,
                    'canal_asignado' => $canalAsignado,
                    'estado' => 'pendiente',
                    'etapa_actual_id' => null,
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
                        Log::debug('Prospecto ya existe en flujo o error', [
                            'prospecto_id' => $insert['prospecto_id'],
                            'error' => $individualError->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $asignados;
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
