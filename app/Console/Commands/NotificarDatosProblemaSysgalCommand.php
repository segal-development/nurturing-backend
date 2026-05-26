<?php

namespace App\Console\Commands;

use App\Mail\DatosProblemaSysgalMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por email (a SYSGAL) los prospectos que entraron a un flujo pero tienen el dato de
 * email malo (sin email / email inválido), para que lo corrijan en el origen.
 *
 * Modo "solo nuevos": solo incluye prospectos que NO fueron avisados antes (tabla
 * sysgal_dato_notificaciones). No re-avisa al mismo. Si no hay nuevos, no manda nada.
 *
 * No consulta SYSGAL (lee datos locales), así que NO requiere whitelist: corre donde corra
 * el scheduler.
 */
class NotificarDatosProblemaSysgalCommand extends Command
{
    protected $signature = 'sysgal:notificar-datos-problema {--dry-run : Muestra qué se enviaría, sin enviar ni registrar}';

    protected $description = 'Avisa por email los clientes con email malo (nuevos) para corregir en SYSGAL';

    public function handle(): int
    {
        $items = $this->buscarNuevos();

        if ($items->isEmpty()) {
            $this->info('No hay clientes nuevos con problema de email. Nada que avisar.');

            return self::SUCCESS;
        }

        $to = config('envios.alerts.sysgal_data_to', 'dchavez@segal.cl');
        $cc = config('envios.alerts.sysgal_data_cc', 'csalinas@segal.cl');

        if ($this->option('dry-run')) {
            $this->warn("DRY-RUN: se enviaría a {$to} (CC {$cc}) con {$items->count()} cliente(s):");
            foreach ($items as $it) {
                $detalle = $it['detalle'] ? " ({$it['detalle']})" : '';
                $this->line("  - {$it['nombre']} | RUT {$it['rut']} | {$it['motivo']}{$detalle}");
            }
            $this->warn('DRY-RUN: no se envió correo ni se registró nada.');

            return self::SUCCESS;
        }

        Mail::to($to)->cc($cc)->send(new DatosProblemaSysgalMail($items->all(), $items->count()));

        $now = now();
        DB::table('sysgal_dato_notificaciones')->insertOrIgnore(
            $items->map(fn ($it) => [
                'prospecto_id' => $it['prospecto_id'],
                'motivo' => $it['motivo_key'],
                'email_malo' => $it['email_malo'],
                'notificado_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        $this->info("Aviso enviado a {$to} (CC {$cc}) con {$items->count()} cliente(s).");

        return self::SUCCESS;
    }

    /**
     * Prospectos que entraron a un flujo (no cancelado), con email malo, que aún no fueron avisados.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buscarNuevos(): \Illuminate\Support\Collection
    {
        $rows = DB::table('prospecto_en_flujo as pf')
            ->join('prospectos as p', 'p.id', '=', 'pf.prospecto_id')
            ->where('pf.cancelado', false)
            ->where(function ($q) {
                $q->whereNull('p.email')
                    ->orWhere('p.email', '')
                    ->orWhere('p.email_invalido', true);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sysgal_dato_notificaciones as n')
                    ->whereColumn('n.prospecto_id', 'p.id');
            })
            ->distinct()
            ->get(['p.id', 'p.nombre', 'p.rut', 'p.email', 'p.email_invalido', 'p.email_invalido_motivo']);

        return $rows
            ->unique('id') // un prospecto puede estar en varios flujos → un solo aviso
            ->map(function ($p) {
                $sinEmail = empty($p->email);

                return [
                    'prospecto_id' => $p->id,
                    'nombre' => $p->nombre,
                    'rut' => $p->rut,
                    'motivo' => $sinEmail ? 'Sin email' : 'Email inválido',
                    'motivo_key' => $sinEmail ? 'sin_email' : 'email_invalido',
                    'detalle' => $sinEmail ? null : $p->email_invalido_motivo,
                    'email_malo' => $sinEmail ? null : $p->email,
                ];
            })
            ->sortBy('nombre')
            ->values();
    }
}
