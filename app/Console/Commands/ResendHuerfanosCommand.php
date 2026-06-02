<?php

namespace App\Console\Commands;

use App\Jobs\EnviarEtapaJob;
use App\Models\Flujo;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reenvía los emails HUÉRFANOS de un flujo: envíos en estado 'pendiente' sin proveedor
 * ni message_id (= el envío nunca se intentó realmente; residuo de la caída del proveedor,
 * cuando el worker creó el row pero murió antes de mandar).
 *
 * Mecánica: borra el placeholder huérfano (libera el slot del unique constraint) y despacha
 * EnviarEtapaJob para esos prospectos. Con la idempotencia del orquestador + de la hoja, solo
 * se envía a los que realmente no recibieron; cero duplicados.
 *
 * Uso:
 *   php artisan nurturing:resend-huerfanos --flujo=39 --dry-run     (ver qué haría)
 *   php artisan nurturing:resend-huerfanos --flujo=39 --limit=300   (lote de validación)
 *   php artisan nurturing:resend-huerfanos --flujo=39               (todo el flujo)
 */
class ResendHuerfanosCommand extends Command
{
    protected $signature = 'nurturing:resend-huerfanos
        {--flujo= : ID del flujo (requerido)}
        {--limit=0 : Máximo de prospectos a reenviar (0 = sin límite)}
        {--dry-run : Solo mostrar qué se haría, sin borrar ni despachar}';

    protected $description = 'Reenvía emails huérfanos (pendiente sin envío real) de un flujo, de forma controlada.';

    public function handle(): int
    {
        $flujoId = (int) $this->option('flujo');
        if (! $flujoId) {
            $this->error('--flujo es requerido.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $flujo = Flujo::find($flujoId);
        if (! $flujo) {
            $this->error("Flujo {$flujoId} no encontrado.");

            return Command::FAILURE;
        }

        $cfg = $flujo->config_structure ?? [];
        $stagesPorId = collect($cfg['stages'] ?? [])->keyBy('id');
        $branches = $cfg['branches'] ?? [];

        // Huérfanos: pendiente, sin provider y sin message_id, agrupados por etapa (FEE).
        $huerfanos = DB::table('envios')
            ->where('flujo_id', $flujoId)
            ->where('canal', 'email')
            ->where('estado', 'pendiente')
            ->where(fn ($q) => $q->whereNull('external_message_id')->orWhere('external_message_id', ''))
            ->where(fn ($q) => $q->whereNull('email_provider')->orWhere('email_provider', ''))
            ->get(['id', 'prospecto_id', 'flujo_ejecucion_etapa_id'])
            ->groupBy('flujo_ejecucion_etapa_id');

        if ($huerfanos->isEmpty()) {
            $this->info("Flujo {$flujoId}: no hay huérfanos para reenviar.");

            return Command::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '')."Flujo {$flujoId} ({$flujo->nombre}) — huérfanos por etapa:");
        $totalReenviados = 0;
        $restante = $limit > 0 ? $limit : PHP_INT_MAX;

        foreach ($huerfanos as $feeId => $rows) {
            if ($restante <= 0) {
                break;
            }

            $fee = FlujoEjecucionEtapa::find($feeId);
            if (! $fee) {
                $this->warn("  FEE {$feeId}: no existe, skip.");

                continue;
            }

            $stage = $stagesPorId[$fee->node_id] ?? null;
            if (! $stage) {
                $this->warn("  FEE {$feeId} (node {$fee->node_id}): sin stage en config, skip.");

                continue;
            }

            // Aplicar límite global repartido.
            $rowsSlice = $restante < $rows->count() ? $rows->take($restante) : $rows;
            $envioIds = $rowsSlice->pluck('id')->all();
            $prospectoIds = $rowsSlice->pluck('prospecto_id')->unique()->values()->all();
            $restante -= count($prospectoIds);

            $label = $stage['label'] ?? $fee->node_id;
            $this->line(sprintf('  Etapa "%s" (FEE %d): %d huérfanos%s',
                $label, $feeId, count($prospectoIds), $dryRun ? '' : ' → borrando placeholder + despachando'));

            if ($dryRun) {
                continue;
            }

            // Borrar placeholders (libera el slot del unique constraint para que el reenvío inserte fresco).
            DB::table('envios')->whereIn('id', $envioIds)->delete();

            // Despachar el reenvío (idempotencia filtra a los que ya recibieron; estos no recibieron).
            EnviarEtapaJob::dispatch(
                flujoEjecucionId: $fee->flujo_ejecucion_id,
                etapaEjecucionId: $fee->id,
                stage: (array) $stage,
                prospectoIds: $prospectoIds,
                branches: $branches,
            );

            $totalReenviados += count($prospectoIds);
        }

        $this->newLine();
        if ($dryRun) {
            $this->info('[DRY-RUN] No se borró ni despachó nada. Quitá --dry-run para ejecutar.');
        } else {
            $this->info("Reenvío despachado para {$totalReenviados} prospectos. Monitoreá la cola/envíos.");
        }

        return Command::SUCCESS;
    }
}
