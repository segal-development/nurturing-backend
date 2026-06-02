<?php

namespace App\Console\Commands;

use App\Jobs\EnviarEmailEtapaProspectoJob;
use App\Models\Flujo;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Plantilla;
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

        // Base: huérfanos email (pendiente, sin provider y sin message_id) del flujo.
        $base = DB::table('envios')
            ->where('flujo_id', $flujoId)
            ->where('canal', 'email')
            ->where('estado', 'pendiente')
            ->where(fn ($q) => $q->whereNull('external_message_id')->orWhere('external_message_id', ''))
            ->where(fn ($q) => $q->whereNull('email_provider')->orWhere('email_provider', ''));

        // Sin FEE no se pueden re-disparar vía EnviarEtapaJob (no hay etapa) → los reportamos y skip.
        $sinFee = (clone $base)->whereNull('flujo_ejecucion_etapa_id')->count();
        if ($sinFee > 0) {
            $this->warn("  ⚠ {$sinFee} huérfanos SIN etapa asociada — no reenviables por este comando (revisar aparte).");
        }

        // Huérfanos CON etapa, agrupados por FEE.
        $huerfanos = (clone $base)
            ->whereNotNull('flujo_ejecucion_etapa_id')
            ->whereNotNull('prospecto_en_flujo_id')
            ->get(['id', 'prospecto_id', 'prospecto_en_flujo_id', 'flujo_ejecucion_etapa_id'])
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

            // Resolver el contenido del email desde la plantilla del stage (modo componentes).
            // Leaf-direct: despachamos EnviarEmailEtapaProspectoJob por prospecto, salteando el
            // gate del orquestador (que filtra por ultima_etapa_node_id = "ya pasó la etapa").
            // En stages 'ambos', plantilla_id es la SMS y la de email está en plantilla_id_email.
            // En stages 'email', plantilla_id es la de email (no hay plantilla_id_email). Preferimos
            // plantilla_id_email si existe — mismo criterio que FlujoEtapa::obtenerContenidoParaEnvio.
            $plantillaId = $stage['plantilla_id_email'] ?? $stage['plantilla_id'] ?? null;
            $plantilla = $plantillaId ? Plantilla::find($plantillaId) : null;
            if (! $plantilla || ! $plantilla->esEmail()) {
                $this->warn("  FEE {$feeId} (\"".($stage['label'] ?? $fee->node_id)."\"): sin plantilla de email (plantilla_id={$plantillaId}), skip.");

                continue;
            }
            $contenido = (string) ($plantilla->generarPreview() ?? '');
            $asunto = (string) ($plantilla->asunto ?? '');
            if ($contenido === '') {
                $this->warn("  FEE {$feeId}: plantilla {$plantillaId} sin contenido renderizable, skip.");

                continue;
            }

            // Aplicar límite global repartido.
            $rowsSlice = $restante < $rows->count() ? $rows->take($restante) : $rows;
            $restante -= $rowsSlice->count();

            $label = $stage['label'] ?? $fee->node_id;
            $this->line(sprintf('  Etapa "%s" (FEE %d): %d huérfanos%s',
                $label, $feeId, $rowsSlice->count(), $dryRun ? '' : ' → borrando placeholder + despachando leaf'));

            if ($dryRun) {
                continue;
            }

            // 1) Borrar placeholders ANTES de despachar: libera el slot del unique constraint y
            //    quita el estado bloqueante para que la idempotencia de la hoja NO saltee el reenvío.
            DB::table('envios')->whereIn('id', $rowsSlice->pluck('id')->all())->delete();

            // 2) Despachar el leaf job por prospecto, directo a la cola 'emails'.
            foreach ($rowsSlice as $row) {
                EnviarEmailEtapaProspectoJob::dispatch(
                    prospectoEnFlujoId: $row->prospecto_en_flujo_id,
                    contenido: $contenido,
                    asunto: $asunto,
                    flujoId: $flujoId,
                    etapaEjecucionId: $fee->id,
                    esHtml: true,
                )->onQueue('emails');
            }

            $totalReenviados += $rowsSlice->count();
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
