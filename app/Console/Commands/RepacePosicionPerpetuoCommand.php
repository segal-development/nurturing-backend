<?php

namespace App\Console\Commands;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\ProspectoEnFlujo;
use App\Services\GuardedTransition;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Re-pacea cohortes perpetuas reparadas para que al reanudar continúen a CADENCIA NORMAL
 * sin avalancha de etapas vencidas.
 *
 * PROBLEMA:
 *   Una cohorte perpetua parada hace meses está "due" para TODAS las etapas vencidas
 *   (gate = now() >= fecha_inicio + offset). Al reanudar, todas se disparan de golpe.
 *
 * SOLUCIÓN — re-anclar fecha_inicio:
 *   fecha_inicio_nueva = now() - currentOffset(ultima_etapa)
 *   → La etapa ACTUAL queda "hoy", la SIGUIENTE sale en su intervalo normal (N días futuro).
 *
 * CONDICIÓN DE RE-PACING (idempotencia):
 *   Si now() >= fecha_inicio + nextOffset → la próxima ya está vencida → RE-ANCLAR.
 *   Si now() <  fecha_inicio + nextOffset → la próxima ya está en el futuro → SKIP.
 *   Tras el re-anclado, en una re-corrida la condición es falsa → idempotente.
 *
 * PRECONDICIÓN DURA: la(s) ejecución(es) del flujo DEBEN estar 'paused'.
 *   El comando abortará si no se cumple, igual que RepairPosicionPerpetuoCommand.
 *
 * INVARIANTE: NUNCA se modifica fecha_ingreso (usado por métricas/embudo).
 *   Solo se re-ancla fecha_inicio (usado por el gate temporal).
 *
 * Uso:
 *   php artisan nurturing:repacear-posicion --flujo=39 --dry-run       (ver qué haría)
 *   php artisan nurturing:repacear-posicion --flujo=39 --limit=500     (lote de validación)
 *   php artisan nurturing:repacear-posicion --flujo=39                 (todo el flujo)
 *   php artisan nurturing:repacear-posicion --flujo=39 --flujo=40 --flujo=41  (múltiples)
 */
class RepacePosicionPerpetuoCommand extends Command
{
    protected $signature = 'nurturing:repacear-posicion
        {--flujo=* : ID(s) del flujo a re-pacear (requerido; puede repetirse)}
        {--dry-run : Solo mostrar qué cambiaría, sin escribir}
        {--limit=0 : Máximo de prospectos a re-pacear por flujo (0 = sin límite)}';

    protected $description = 'Re-pacea cohortes perpetuas reparadas: re-ancla fecha_inicio para que al reanudar continúen a cadencia normal sin avalancha de etapas vencidas.';

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
            $status = $this->repacearFlujo($flujoId, $dryRun, $limit);
            if ($status !== Command::SUCCESS) {
                $overallStatus = $status;
            }
        }

        return $overallStatus;
    }

    // =========================================================
    // Private: re-pacea un flujo individual
    // =========================================================

    private function repacearFlujo(int $flujoId, bool $dryRun, int $limit): int
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

        // Construir la cadena lineal de stages con sus offsets
        $cadena = $this->buildCadenaConOffsets($flujo);

        if (empty($cadena)) {
            $this->warn("  Flujo {$flujoId}: no se encontraron etapas ejecutables en config_structure.");

            return Command::SUCCESS;
        }

        $this->line('  Cadena de stages (node_id → offset acumulado):');
        foreach ($cadena as $item) {
            $this->line("    {$item['node_id']} → {$item['offset_dias']}d");
        }

        // Construir índice node_id → posición en cadena para encontrar la "siguiente"
        $indexMap = [];
        foreach ($cadena as $idx => $item) {
            $indexMap[$item['node_id']] = $idx;
        }

        // Query base: prospectos activos con ultima_etapa_node_id no-null
        $query = ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereNotNull('ultima_etapa_node_id')
            ->whereNotNull('fecha_inicio')
            ->where('completado', false)
            ->where('cancelado', false);

        $total    = $query->count();
        $restante = $limit > 0 ? $limit : PHP_INT_MAX;

        $stats = [
            'reancledados'       => 0,
            'ya_paceados'        => 0,
            'skipped_offset_neg' => 0, // offset acumulado = -1 (cadena condicional)
            'skipped_sin_next'   => 0, // última etapa del flujo, no hay siguiente
            'distribuciones'     => [], // "currentOffset → nextOffset": count
        ];

        $this->line("  Procesando {$total} prospectos activos con ultima_etapa (limit: ".($limit ?: 'sin límite').')');
        $this->newLine();

        // Acumular IDs a actualizar: [pef_id => nueva_fecha_inicio_timestamp]
        $actualizaciones = [];

        $query->orderBy('id')->chunk(1000, function (Collection $pefs) use (
            $cadena, $indexMap, &$stats, &$actualizaciones, &$restante, $dryRun
        ) {
            foreach ($pefs as $pef) {
                if ($restante <= 0) {
                    return false; // Detener chunk
                }

                $ultimaNodeId = $pef->ultima_etapa_node_id;

                // Obtener el currentOffset (offset acumulado de la etapa actual)
                $currentIdx = $indexMap[$ultimaNodeId] ?? null;

                // Si el node_id no está en la cadena lineal → offset negativo → skip
                if ($currentIdx === null) {
                    // También verificar vía offsetAcumulado por consistencia
                    $stats['skipped_offset_neg']++;
                    continue;
                }

                $currentOffset = $cadena[$currentIdx]['offset_dias'];

                // Determinar la siguiente etapa
                $nextItem = $cadena[$currentIdx + 1] ?? null;

                if ($nextItem === null) {
                    // Es la última etapa del flujo → no hay avalancha posible → skip
                    $stats['skipped_sin_next']++;
                    continue;
                }

                $nextOffset = $nextItem['offset_dias'];

                // Condición de avalancha: ¿la próxima etapa ya está vencida?
                $fechaProximaHabilitacion = $pef->fecha_inicio->copy()->addDays($nextOffset);
                $proximaEsVencida = now()->greaterThanOrEqualTo($fechaProximaHabilitacion);

                if (! $proximaEsVencida) {
                    // La próxima ya está en el futuro → ya paceado → skip
                    $stats['ya_paceados']++;
                    continue;
                }

                // Re-anclar: fecha_inicio_nueva = now() - currentOffset
                $nuevaFechaInicio = now()->subDays($currentOffset);

                $key = "{$currentOffset}d → {$nextOffset}d";
                $stats['distribuciones'][$key] = ($stats['distribuciones'][$key] ?? 0) + 1;
                $stats['reancledados']++;
                $restante--;

                if (! $dryRun) {
                    $actualizaciones[$pef->id] = $nuevaFechaInicio;
                }
            }
        });

        // Aplicar los updates (SOLO fecha_inicio, NUNCA fecha_ingreso)
        if (! $dryRun && ! empty($actualizaciones)) {
            foreach ($actualizaciones as $pefId => $nuevaFechaInicio) {
                ProspectoEnFlujo::withoutEvents(function () use ($pefId, $nuevaFechaInicio) {
                    ProspectoEnFlujo::where('id', $pefId)
                        ->update(['fecha_inicio' => $nuevaFechaInicio]);
                });
            }
        }

        // Reporte de métricas
        $this->reportar($flujoId, $stats, $dryRun);

        return Command::SUCCESS;
    }

    // =========================================================
    // Construcción de la cadena de stages con offsets
    // =========================================================

    /**
     * Construye la lista de etapas ejecutables del flujo con sus offsets acumulados,
     * en orden ascendente de offset (misma lógica que offsetAcumulado).
     *
     * Reutiliza la misma lógica de traversal que RepairPosicionPerpetuoCommand
     * para garantizar coherencia con el gate temporal.
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

        $executableTypes = ['email', 'sms', 'stage', 'ambos'];
        $stagesPorId     = collect($stages)->keyBy('id');
        $nextOf          = collect($branches)->keyBy('source_node_id');

        $initialNodeConfig = $cfg['initial_node'] ?? null;
        $initialNodeId     = is_array($initialNodeConfig)
            ? ($initialNodeConfig['id'] ?? null)
            : $initialNodeConfig;

        // Encontrar el primer stage ejecutable (después del nodo start / initial_node)
        $firstStageId = null;
        if ($initialNodeId) {
            $conn         = collect($branches)->firstWhere('source_node_id', $initialNodeId);
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

        // Recorrer la cadena calculando offsets (mismo algoritmo que GuardedTransition::offsetAcumulado)
        $esClientesIngreso = $flujo->origen === 'Grupo Deudas - Clientes Ingreso';
        $offsetDias        = $esClientesIngreso ? 3 : 0;
        $currentId         = $firstStageId;
        $visitados         = [];
        $cadena            = [];

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
    // Reporte de métricas
    // =========================================================

    private function reportar(int $flujoId, array $stats, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Flujo {$flujoId} — Resultado:");
        $this->line("  Re-anclados:             {$stats['reancledados']}");
        $this->line("  Ya paceados (sin cambio): {$stats['ya_paceados']}");
        $this->line("  Skipped (última etapa):  {$stats['skipped_sin_next']}");
        $this->line("  Skipped (offset=-1):     {$stats['skipped_offset_neg']}");

        if (! empty($stats['distribuciones'])) {
            $this->newLine();
            $this->line('  Distribución re-anclados (currentOffset → nextOffset):');
            foreach ($stats['distribuciones'] as $dist => $count) {
                $this->line("    {$dist}: {$count} prospectos");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY-RUN] No se escribió ningún cambio. Quitá --dry-run para ejecutar.');
        }

        $this->newLine();
    }
}
