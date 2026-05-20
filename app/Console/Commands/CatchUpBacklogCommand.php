<?php

namespace App\Console\Commands;

use App\Jobs\EnviarEmailEtapaProspectoJob;
use App\Jobs\EnviarSmsEtapaProspectoJob;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Plantilla;
use App\Models\ProspectoEnFlujo;
use App\Services\StageOrderResolver;
use Illuminate\Console\Command;

/**
 * Nutre de forma CONTROLADA el backlog de prospectos rezagados (ultima_etapa=NULL)
 * de un flujo, enviándoles la PRIMERA etapa de a tandas chicas.
 *
 * Por qué este comando y NO CatchUpProspectosJob / EnviarEtapaJob:
 * - CatchUpProspectosJob procesa TODA la población de una (processBehindProspects)
 *   → floodea la cola (incidente 2026-05-20).
 * - EnviarEtapaJob dispara BatchCompletedCallback, que avanza el `proximo_nodo` de
 *   la EJECUCIÓN — puntero compartido con la cohorte principal → la arrastra a
 *   adelantar etapas.
 *
 * Este comando despacha los jobs POR-PROSPECTO directo (EnviarSms/EmailEtapaProspectoJob):
 * envía la etapa 1, el job setea `ultima_etapa_node_id` al enviar (sale del backlog),
 * y NUNCA toca el puntero de la ejecución.
 *
 * Idempotente: el dedup canal-aware de EnvioService + el índice único parcial evitan
 * duplicados. Re-correrlo solo agarra los que siguen en NULL.
 *
 * Seguridad: respaldado por el rate-limiter-fix (tries=0 + maxExceptions) y el
 * circuit breaker, así que ni floodea ni revienta en MaxAttempts.
 */
class CatchUpBacklogCommand extends Command
{
    protected $signature = 'nurturing:catchup-backlog
        {flujo : ID del flujo a procesar}
        {--limit=50 : Máximo de prospectos a procesar en esta tanda}
        {--dry-run : Muestra qué se enviaría sin despachar nada}';

    protected $description = 'Nutre controladamente el backlog (ultima_etapa=NULL) de un flujo enviando la primera etapa, sin arrastrar la cohorte';

    public function handle(StageOrderResolver $resolver): int
    {
        $flujoId = (int) $this->argument('flujo');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $flujo = Flujo::find($flujoId);
        if (! $flujo) {
            $this->error("Flujo {$flujoId} no encontrado.");

            return self::FAILURE;
        }

        $ejecucion = FlujoEjecucion::where('flujo_id', $flujoId)
            ->whereIn('estado', ['in_progress', 'waiting'])
            ->orderByDesc('id')
            ->first();
        if (! $ejecucion) {
            $this->error("Flujo {$flujoId} no tiene ejecución viva (in_progress/waiting).");

            return self::FAILURE;
        }

        $firstStageId = $resolver->getFirstStage($flujo);
        if (! $firstStageId) {
            $this->error("No se pudo resolver la primera etapa del flujo {$flujoId}.");

            return self::FAILURE;
        }

        $stage = collect($flujo->config_structure['stages'] ?? [])->firstWhere('id', $firstStageId);
        if (! $stage) {
            $this->error("Stage {$firstStageId} no encontrado en config_structure.");

            return self::FAILURE;
        }

        $tipoMensaje = $stage['tipo_mensaje'] ?? 'email';
        $usaEmail = in_array($tipoMensaje, ['email', 'ambos'], true);
        $usaSms = in_array($tipoMensaje, ['sms', 'ambos'], true);

        $etapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('node_id', $firstStageId)
            ->orderBy('id')
            ->first();
        if (! $etapa) {
            $this->error("No existe FlujoEjecucionEtapa para la primera etapa (node {$firstStageId}, ejec {$ejecucion->id}).");

            return self::FAILURE;
        }

        $contenidoEmail = $this->resolverContenidoEmail($stage);
        $contenidoSms = $this->resolverContenidoSms($stage);

        if ($usaEmail && empty($contenidoEmail['contenido'])) {
            $this->error('El stage usa email pero no se pudo resolver el contenido de email.');

            return self::FAILURE;
        }
        if ($usaSms && empty($contenidoSms['contenido'])) {
            $this->error('El stage usa SMS pero no se pudo resolver el contenido de SMS.');

            return self::FAILURE;
        }

        $baseQuery = fn () => ProspectoEnFlujo::where('flujo_id', $flujoId)
            ->whereNull('ultima_etapa_node_id')
            ->where('completado', false)
            ->where('cancelado', false);

        // Solo NURTURABLES: alcanzables por algún canal de la etapa (email válido y/o
        // teléfono). Sin esto, los sin-canal (ej. sin email en etapa email-only) bloquearían
        // el progreso — quedan como problema de DATO, no de flujo (ver métrica problemas_envio).
        $aplicarNurturable = function ($query) use ($usaEmail, $usaSms) {
            return $query->whereHas('prospecto', function ($p) use ($usaEmail, $usaSms) {
                $p->where(function ($c) use ($usaEmail, $usaSms) {
                    if ($usaEmail) {
                        $c->orWhere(function ($e) {
                            $e->whereNotNull('email')->where('email', '!=', '')
                                ->where(function ($ev) {
                                    $ev->where('email_invalido', false)->orWhereNull('email_invalido');
                                });
                        });
                    }
                    if ($usaSms) {
                        $c->orWhere(function ($s) {
                            $s->whereNotNull('telefono')->where('telefono', '!=', '');
                        });
                    }
                });
            });
        };

        $totalBacklog = $baseQuery()->count();
        $totalNurturable = $aplicarNurturable($baseQuery())->count();

        $backlog = $aplicarNurturable($baseQuery())
            ->with('prospecto')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info("Flujo {$flujoId}: {$flujo->nombre}");
        $this->line("  Ejecución {$ejecucion->id} ({$ejecucion->estado}) · Etapa 1: {$firstStageId} (etapa_ejec {$etapa->id}, estado {$etapa->estado}) · Canal: {$tipoMensaje}");
        $sinCanalDato = $totalBacklog - $totalNurturable;
        $this->line("  Backlog total NULL-ultima: {$totalBacklog} · Nurturables (canal válido): {$totalNurturable} · Sin canal (dato roto): {$sinCanalDato}");
        $this->line("  Esta tanda: {$backlog->count()} (limit {$limit})");
        if ($usaEmail) {
            $this->line('  Email · asunto: '.($contenidoEmail['asunto'] ?? '(sin asunto)').' · '.strlen((string) $contenidoEmail['contenido']).' chars · html='.($contenidoEmail['es_html'] ? 'sí' : 'no'));
        }
        if ($usaSms) {
            $this->line('  SMS · '.mb_substr((string) $contenidoSms['contenido'], 0, 90));
        }

        // Plan: por prospecto, qué canales recibe
        $plan = [];
        $emailCount = 0;
        $smsCount = 0;
        $sinCanal = 0;
        foreach ($backlog as $pf) {
            $p = $pf->prospecto;
            if (! $p) {
                $sinCanal++;

                continue;
            }
            $tieneEmail = $usaEmail && ! empty($p->email) && ! ($p->email_invalido ?? false);
            $tieneTel = $usaSms && ! empty($p->telefono);
            if (! $tieneEmail && ! $tieneTel) {
                $sinCanal++;

                continue;
            }
            if ($tieneEmail) {
                $emailCount++;
            }
            if ($tieneTel) {
                $smsCount++;
            }
            $plan[] = [$pf, $tieneEmail, $tieneTel];
        }

        $this->newLine();
        $this->info("Se despacharían: {$emailCount} emails + {$smsCount} SMS  (sin canal válido: {$sinCanal})");

        if ($dryRun) {
            $this->warn('DRY-RUN: no se despachó nada. Muestra de los primeros 5:');
            foreach (array_slice($plan, 0, 5) as [$pf, $tieneEmail, $tieneTel]) {
                $p = $pf->prospecto;
                $canales = trim(($tieneEmail ? 'email ' : '').($tieneTel ? 'sms' : ''));
                $this->line("  - {$p->nombre} ({$p->rut}) -> {$canales}");
            }

            return self::SUCCESS;
        }

        foreach ($plan as [$pf, $tieneEmail, $tieneTel]) {
            if ($tieneEmail) {
                EnviarEmailEtapaProspectoJob::dispatch(
                    prospectoEnFlujoId: $pf->id,
                    contenido: $contenidoEmail['contenido'],
                    asunto: $contenidoEmail['asunto'] ?? 'Mensaje',
                    flujoId: $flujoId,
                    etapaEjecucionId: $etapa->id,
                    esHtml: (bool) ($contenidoEmail['es_html'] ?? false),
                )->onQueue('envios');
            }
            if ($tieneTel) {
                EnviarSmsEtapaProspectoJob::dispatch(
                    prospectoEnFlujoId: $pf->id,
                    contenido: $contenidoSms['contenido'],
                    flujoId: $flujoId,
                    etapaEjecucionId: $etapa->id,
                )->onQueue('envios');
            }
        }

        $this->info("✓ Despachados {$emailCount} emails + {$smsCount} SMS para el flujo {$flujoId} (tanda de {$backlog->count()}).");
        $this->line('Re-corré el comando para la próxima tanda. Es idempotente: los ya enviados salen del backlog.');

        return self::SUCCESS;
    }

    /**
     * Resuelve el contenido de email igual que EnviarEtapaJob::obtenerContenidoMensaje.
     */
    private function resolverContenidoEmail(array $stage): array
    {
        $plantillaType = $stage['plantilla_type'] ?? 'inline';

        if ($plantillaType === 'reference') {
            $plantillaId = $stage['plantilla_id_email'] ?? $stage['plantilla_id'] ?? null;
            if ($plantillaId) {
                $plantilla = Plantilla::find($plantillaId);
                if ($plantilla && $plantilla->esEmail()) {
                    return [
                        'contenido' => $plantilla->generarPreview() ?? '',
                        'asunto' => $plantilla->asunto,
                        'es_html' => true,
                    ];
                }
            }
        }

        $contenido = $stage['plantilla_mensaje'] ?? $stage['data']['contenido'] ?? '';

        return [
            'contenido' => $contenido,
            'asunto' => $stage['template']['asunto'] ?? $stage['data']['template']['asunto'] ?? null,
            'es_html' => str_contains((string) $contenido, '<'),
        ];
    }

    /**
     * Resuelve el contenido de SMS igual que EnviarEtapaJob::obtenerContenidoSms.
     */
    private function resolverContenidoSms(array $stage): array
    {
        $plantillaType = $stage['plantilla_type'] ?? 'inline';

        if ($plantillaType === 'reference') {
            $plantillaId = $stage['plantilla_id'] ?? null;
            if ($plantillaId) {
                $plantilla = Plantilla::find($plantillaId);
                if ($plantilla && $plantilla->esSMS()) {
                    return ['contenido' => $plantilla->contenido ?? ''];
                }
            }
        }

        return ['contenido' => $stage['plantilla_mensaje_sms'] ?? $stage['data']['contenido_sms'] ?? ''];
    }
}
