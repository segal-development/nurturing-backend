<?php

namespace App\Console\Commands;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\ProspectoEnFlujo;
use App\Services\GuardedTransition;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Repara la posición errónea de prospectos en flujos perpetuos.
 *
 * PRECONDICIÓN DURA: la ejecución del flujo DEBE estar en estado 'paused'.
 * El comando abortará si no se cumple esta condición, para evitar race conditions
 * con el scheduler.
 *
 * LÓGICA DE RECÁLCULO (Decision 5 del design):
 *   stageCorrecto = max{ stageN : fecha_inicio + offsetAcumulado(stageN) <= now() }
 *
 * Se usa GuardedTransition::offsetAcumulado para garantizar coherencia con el gate
 * temporal del scheduler. NO se deriva la posición del historial de envíos (ese fue
 * parte de cómo se corrompió la posición con BackfillUltimaEtapaCommand).
 *
 * Operaciones:
 *   1. Verificar ejecución 'paused'
 *   2. Por cada prospecto: recalcular stageCorrecto via offsetAcumulado
 *   3. Corregir prospecto_en_flujo.ultima_etapa_node_id al stage correcto
 *   4. Reconstruir pivote etapa_prospecto: sync cada prospecto solo a la FEE
 *      de su PRÓXIMA etapa elegible (la siguiente después del stageCorrecto)
 *   5. Recalcular flujo_ejecucion_etapas.prospectos_ids + prospectos_count
 *
 * Idempotente: recalcula a valor absoluto desde fecha_inicio, no incrementa.
 *
 * Uso:
 *   php artisan nurturing:repair-posicion --flujo=39 --dry-run        (ver qué haría)
 *   php artisan nurturing:repair-posicion --flujo=39 --limit=500      (lote de validación)
 *   php artisan nurturing:repair-posicion --flujo=39                  (todo el flujo)
 *   php artisan nurturing:repair-posicion --flujo=39 --flujo=40 --flujo=41  (múltiples)
 */
class RepairPosicionPerpetuoCommand extends Command
{
    protected $signature = 'nurturing:repair-posicion
        {--flujo=* : ID(s) del flujo a reparar (requerido; puede repetirse)}
        {--dry-run : Solo mostrar qué cambiaría, sin escribir}
        {--limit=0 : Máximo de prospectos a reparar por flujo (0 = sin límite)}';

    protected $description = 'Repara la posición (ultima_etapa_node_id) y el pivote etapa_prospecto de prospectos en flujos perpetuos, usando offsetAcumulado como fuente de verdad.';

    public function __construct(private readonly GuardedTransition $guardedTransition)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $flujoIds = array_filter(array_map('intval', (array) $this->option('flujo')));

        if (empty($flujoIds)) {
            $this->error('--flujo es requerido. Ej: --flujo=39');

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int) $this->option('limit');

        if ($dryRun) {
            $this->warn('[DRY-RUN] No se escribirá ningún cambio.');
        }

        $overallStatus = Command::SUCCESS;

        foreach ($flujoIds as $flujoId) {
            $status = $this->repararFlujo($flujoId, $dryRun, $limit);
            if ($status !== Command::SUCCESS) {
                $overallStatus = $status;
            }
        }

        return $overallStatus;
    }

    // =========================================================
    // Private: repara un flujo individual
    // =========================================================

    private function repararFlujo(int $flujoId, bool $dryRun, int $limit): int
    {
        $flujo = Flujo::find($flujoId);
        if (! $flujo) {
            $this->error("Flujo {$flujoId} no encontrado.");
            return Command::FAILURE;
        }

        // PRECONDICIÓN DURA: la ejecución debe estar 'paused'
        $ejecucion = FlujoEjecucion::where('flujo_id', $flujoId)
            ->orderByDesc('id')
            ->first();

        if (! $ejecucion) {
            $this->error("Flujo {$flujoId}: no tiene ejecución registrada.");
            return Command::FAILURE;
        }

        if ($ejecucion->estado !== 'paused') {
            $this->error(
                "Flujo {$flujoId}: la ejecución #{$ejecucion->id} está en estado '{$ejecucion->estado}', no 'paused'. ".
                'Pausá el flujo antes de correr este comando para evitar race conditions con el scheduler.'
            );
            return Command::FAILURE;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '')."Flujo {$flujoId} ({$flujo->nombre}) — ejecución #{$ejecucion->id} paused ✓");

        // Construir la cadena de etapas ejecutables con sus offsets
        $cadena = $this->buildCadenaConOffsets($flujo);

        if (empty($cadena)) {
            $this->warn("  Flujo {$flujoId}: no se encontraron etapas ejecutables en config_structure.");
            return Command::SUCCESS;
        }

        $this->line('  Cadena de stages (node_id → offset acumulado):');
        foreach ($cadena as $item) {
            $this->line("    {$item['node_id']} → {$item['offset_dias']}d");
        }

        // Cargar FEEs del flujo (para reconstruir el pivote)
        $fees = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->get()
            ->keyBy('node_id');

        // Query base de prospectos activos del flujo
        $query = ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereNotNull('fecha_inicio')
            ->where('completado', false)
            ->where('cancelado', false);

        $total  = $query->count();
        $restante = $limit > 0 ? $limit : PHP_INT_MAX;

        $stats = [
            'corregidos'  => 0,
            'ya_correctos' => 0,
            'sin_stage'   => 0,
            'movimientos' => [], // "desde → hacia" como clave de conteo
        ];

        $this->line("  Procesando {$total} prospectos activos (limit: ".($limit ?: 'sin límite').')');
        $this->newLine();

        // Batch updates para eficiencia
        $idsToUpdate = []; // node_id => [pef_id, ...]

        $query->orderBy('id')->chunk(1000, function (Collection $pefs) use (
            $cadena, &$stats, &$idsToUpdate, &$restante, $dryRun
        ) {
            foreach ($pefs as $pef) {
                if ($restante <= 0) {
                    return false; // Detener chunk
                }

                $stageCorrecto = $this->calcularStageCorrecto($pef, $cadena);

                if ($stageCorrecto === null) {
                    $stats['sin_stage']++;
                    continue;
                }

                $actual = $pef->ultima_etapa_node_id;

                if ($actual === $stageCorrecto) {
                    $stats['ya_correctos']++;
                    continue;
                }

                // Necesita corrección
                $key = ($actual ?? 'NULL') . ' → ' . $stageCorrecto;
                $stats['movimientos'][$key] = ($stats['movimientos'][$key] ?? 0) + 1;
                $stats['corregidos']++;
                $restante--;

                if (! $dryRun) {
                    // Acumular por stage destino para batch update
                    $idsToUpdate[$stageCorrecto][] = $pef->id;
                }
            }
        });

        // Aplicar los updates en lotes por stage correcto
        if (! $dryRun && ! empty($idsToUpdate)) {
            foreach ($idsToUpdate as $stageCorrecto => $pefIds) {
                ProspectoEnFlujo::withoutEvents(function () use ($pefIds, $stageCorrecto) {
                    ProspectoEnFlujo::whereIn('id', $pefIds)
                        ->update(['ultima_etapa_node_id' => $stageCorrecto]);
                });
            }

            // Reconstruir el pivote etapa_prospecto y los prospectos_ids de las FEEs
            $this->reconstruirPivote($ejecucion, $cadena, $fees, $dryRun);
        }

        // Reporte de métricas
        $this->reportar($flujoId, $stats, $dryRun);

        return Command::SUCCESS;
    }

    // =========================================================
    // Cálculo del stage correcto para un prospecto
    // =========================================================

    /**
     * Calcula el stage correcto para un prospecto en base a su fecha_inicio
     * y la cadena de stages con sus offsets.
     *
     * stageCorrecto = max{ stageN : fecha_inicio + offset(stageN) <= now() }
     *
     * Si ninguna etapa está disponible aún → null (sin stage correcto todavía)
     * Si fecha_inicio es null → no se puede calcular → null
     */
    private function calcularStageCorrecto(ProspectoEnFlujo $pef, array $cadena): ?string
    {
        if (! $pef->fecha_inicio) {
            return null;
        }

        $stageCorrecto = null;

        foreach ($cadena as $item) {
            $fechaHabilitacion = $pef->fecha_inicio->copy()->addDays($item['offset_dias']);
            if (now()->greaterThanOrEqualTo($fechaHabilitacion)) {
                $stageCorrecto = $item['node_id'];
            } else {
                break; // La cadena es ordenada ascendente por offset
            }
        }

        return $stageCorrecto;
    }

    // =========================================================
    // Construcción de la cadena de stages con offsets
    // =========================================================

    /**
     * Construye la lista de etapas ejecutables del flujo con sus offsets acumulados,
     * en orden ascendente de offset (el mismo orden que recorre offsetAcumulado).
     *
     * @return array<int, array{node_id: string, offset_dias: int}>
     */
    private function buildCadenaConOffsets(Flujo $flujo): array
    {
        $cfg      = $flujo->config_structure ?? [];
        $stages   = $cfg['stages'] ?? [];
        $branches = $cfg['branches'] ?? [];

        if (empty($stages)) {
            return [];
        }

        // Reutilizar offsetAcumulado para cada stage (coherencia con el gate temporal)
        // Construir la cadena siguiendo el orden de ejecución
        $cadena = [];

        $executableTypes = ['email', 'sms', 'stage', 'ambos'];
        $stagesPorId = collect($stages)->keyBy('id');
        $nextOf      = collect($branches)->keyBy('source_node_id');

        $initialNodeConfig = $cfg['initial_node'] ?? null;
        $initialNodeId     = is_array($initialNodeConfig)
            ? ($initialNodeConfig['id'] ?? null)
            : $initialNodeConfig;

        // Encontrar el primer stage ejecutable
        $firstStageId = null;
        if ($initialNodeId) {
            $conn = collect($branches)->firstWhere('source_node_id', $initialNodeId);
            $firstStageId = $conn['target_node_id'] ?? null;
        }

        if (! $firstStageId) {
            $startNode    = collect($stages)->firstWhere('type', 'start');
            $startId      = $startNode['id'] ?? null;
            if ($startId) {
                $conn         = collect($branches)->firstWhere('source_node_id', $startId);
                $firstStageId = $conn['target_node_id'] ?? null;
            }
        }

        if (! $firstStageId) {
            $firstStageId = collect($stages)
                ->filter(fn ($s) => in_array($s['type'] ?? '', $executableTypes))
                ->sortBy('orden')
                ->first()['id'] ?? null;
        }

        if (! $firstStageId) {
            return [];
        }

        // Recorrer la cadena calculando offsets (misma lógica que offsetAcumulado)
        $esClientesIngreso = $flujo->origen === 'Grupo Deudas - Clientes Ingreso';
        $offsetDias        = $esClientesIngreso ? 3 : 0;
        $currentId         = $firstStageId;
        $visitados         = [];

        while ($currentId && ! in_array($currentId, $visitados, true)) {
            $visitados[] = $currentId;
            $stage       = $stagesPorId[$currentId] ?? null;

            if (! $stage) {
                break;
            }

            $offsetDias += (int) ($stage['tiempo_espera'] ?? 0);
            $type        = $stage['type'] ?? null;

            if (in_array($type, $executableTypes)) {
                $cadena[] = [
                    'node_id'     => $currentId,
                    'offset_dias' => $offsetDias,
                ];
            }

            if ($type === 'end') {
                break;
            }

            $currentId = $nextOf[$currentId]['target_node_id'] ?? null;
        }

        return $cadena;
    }

    // =========================================================
    // Reconstruir pivote etapa_prospecto y FEE counts
    // =========================================================

    /**
     * Para cada prospecto activo del flujo:
     *   - Determina la PRÓXIMA etapa (la que sigue a su stageCorrecto actual en la cadena)
     *   - Sincroniza el pivote etapa_prospecto para que el prospecto quede SOLO en esa FEE
     *
     * Luego recalcula prospectos_ids + prospectos_count en cada FEE.
     */
    private function reconstruirPivote(
        FlujoEjecucion $ejecucion,
        array $cadena,
        \Illuminate\Database\Eloquent\Collection $fees,
        bool $dryRun
    ): void {
        if ($dryRun) {
            return;
        }

        // Construir mapeo node_id → índice en la cadena (para encontrar siguiente etapa)
        $indexMap = [];
        foreach ($cadena as $idx => $item) {
            $indexMap[$item['node_id']] = $idx;
        }

        // Mapear cada prospecto a la FEE de su PRÓXIMA etapa
        // fee_node_id → [prospecto_en_flujo_id, ...]
        $prospectosParaFee = [];

        ProspectoEnFlujo::where('flujo_id', $ejecucion->flujo_id)
            ->whereNotNull('fecha_inicio')
            ->where('completado', false)
            ->where('cancelado', false)
            ->orderBy('id')
            ->chunk(1000, function (Collection $pefs) use ($cadena, $indexMap, &$prospectosParaFee) {
                foreach ($pefs as $pef) {
                    $stageCorrecto = $pef->ultima_etapa_node_id;

                    // Determinar la próxima etapa
                    if ($stageCorrecto === null) {
                        // Nuevo prospecto: próxima = primera etapa
                        $proximaNodeId = $cadena[0]['node_id'] ?? null;
                    } else {
                        $idx           = $indexMap[$stageCorrecto] ?? null;
                        if ($idx === null) {
                            continue; // Stage no reconocida, skip
                        }
                        $proximaNodeId = $cadena[$idx + 1]['node_id'] ?? null;
                    }

                    if ($proximaNodeId === null) {
                        continue; // Completó todo el flujo
                    }

                    $prospectosParaFee[$proximaNodeId][] = $pef->id;
                }
            });

        // Limpiar todos los pivotes etapa_prospecto para las FEEs de esta ejecución
        $feeIds = $fees->pluck('id')->all();
        DB::table('etapa_prospecto')->whereIn('flujo_ejecucion_etapa_id', $feeIds)->delete();

        // Insertar los nuevos pivotes y actualizar prospectos_ids en cada FEE
        foreach ($fees as $nodeId => $fee) {
            $pefIds = $prospectosParaFee[$nodeId] ?? [];
            $prospectoIds = [];

            if (! empty($pefIds)) {
                // El pivote etapa_prospecto usa prospecto_id (no prospecto_en_flujo_id).
                $prospectoIds = ProspectoEnFlujo::whereIn('id', $pefIds)
                    ->pluck('prospecto_id')
                    ->all();

                $pivotRows = array_map(fn ($pid) => [
                    'flujo_ejecucion_etapa_id' => $fee->id,
                    'prospecto_id' => $pid,
                ], $prospectoIds);

                if (! empty($pivotRows)) {
                    DB::table('etapa_prospecto')->insertOrIgnore($pivotRows);
                }
            }

            // prospectos_ids DEBE guardar Prospecto IDs (no PEF IDs): el observer `saved` de
            // FlujoEjecucionEtapa hace prospectos()->sync(prospectos_ids) tratándolos como
            // Prospecto IDs. Guardar PEF IDs acá corrompía el pivote recién insertado y
            // re-disparaba el over-dispatch al reanudar (WARNING 1 del verify final).
            $fee->update([
                'prospectos_ids'   => $prospectoIds,
                'prospectos_count' => count($prospectoIds),
            ]);
        }
    }

    // =========================================================
    // Reporte de métricas
    // =========================================================

    private function reportar(int $flujoId, array $stats, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Flujo {$flujoId} — Resultado:");
        $this->line("  Corregidos:    {$stats['corregidos']}");
        $this->line("  Ya correctos:  {$stats['ya_correctos']}");
        $this->line("  Sin fecha_inicio / sin stage: {$stats['sin_stage']}");

        if (! empty($stats['movimientos'])) {
            $this->newLine();
            $this->line('  Movimientos (anterior → correcto):');
            foreach ($stats['movimientos'] as $movimiento => $count) {
                $this->line("    {$movimiento}: {$count} prospectos");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY-RUN] No se escribió ningún cambio. Quitá --dry-run para ejecutar.');
        }

        $this->newLine();
    }
}
