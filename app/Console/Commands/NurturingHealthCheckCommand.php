<?php

namespace App\Console\Commands;

use App\Mail\HealthCheckAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Health check for the nurturing-backend queue system.
 *
 * Detects:
 * - Queue saturation (envios or emails)
 * - Failed job spikes
 * - Zero output (no email/sms sent in last hour)
 * - Circuit breaker open
 *
 * When any threshold is exceeded, logs WARNING/ERROR and sends an email alert.
 * Same alert is deduplicated for 1h to avoid spam.
 */
class NurturingHealthCheckCommand extends Command
{
    protected $signature = 'nurturing:health-check
                            {--force-mail : Send alert even if no issues}
                            {--no-mail : Only log, do not send mail}';

    protected $description = 'Check nurturing queue health and alert if thresholds exceeded';

    private const QUEUE_ENVIOS_THRESHOLD = 500_000;
    private const QUEUE_EMAILS_THRESHOLD = 100_000;
    private const FAILED_TOTAL_THRESHOLD = 5_000;
    private const FAILED_LAST_HOUR_THRESHOLD = 100;
    private const ZERO_OUTPUT_MINUTES = 60;

    public function handle(): int
    {
        $issues = [];

        $envios = DB::table('jobs')->where('queue', 'envios')->count();
        $emails = DB::table('jobs')->where('queue', 'emails')->count();
        $failedTotal = DB::table('failed_jobs')->count();
        $failedLastHour = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subHour())
            ->count();
        $emailLastHour = DB::table('envios')
            ->where('canal', 'email')
            ->where('created_at', '>=', now()->subMinutes(self::ZERO_OUTPUT_MINUTES))
            ->count();
        $smsLastHour = DB::table('envios')
            ->where('canal', 'sms')
            ->where('created_at', '>=', now()->subMinutes(self::ZERO_OUTPUT_MINUTES))
            ->count();
        $cbEmailOpen = Cache::get('envio-circuit:email') === 'open';
        $cbSmsOpen = Cache::get('envio-circuit:sms') === 'open';

        if ($envios > self::QUEUE_ENVIOS_THRESHOLD) {
            $issues[] = ['key' => 'queue-envios', 'severity' => 'warning', 'msg' => "Cola 'envios' saturada: ".number_format($envios)." jobs (umbral: ".number_format(self::QUEUE_ENVIOS_THRESHOLD).')'];
        }
        if ($emails > self::QUEUE_EMAILS_THRESHOLD) {
            $issues[] = ['key' => 'queue-emails', 'severity' => 'warning', 'msg' => "Cola 'emails' saturada: ".number_format($emails)." jobs (umbral: ".number_format(self::QUEUE_EMAILS_THRESHOLD).')'];
        }
        if ($failedTotal > self::FAILED_TOTAL_THRESHOLD) {
            $issues[] = ['key' => 'failed-total', 'severity' => 'warning', 'msg' => "Failed jobs acumulados: ".number_format($failedTotal)." (umbral: ".number_format(self::FAILED_TOTAL_THRESHOLD).')'];
        }
        if ($failedLastHour > self::FAILED_LAST_HOUR_THRESHOLD) {
            $issues[] = ['key' => 'failed-recent', 'severity' => 'error', 'msg' => "Failed jobs en la última hora: ".number_format($failedLastHour)." (umbral: ".number_format(self::FAILED_LAST_HOUR_THRESHOLD).')'];
        }
        if ($emailLastHour === 0 && $emails > 0) {
            $issues[] = ['key' => 'no-emails', 'severity' => 'error', 'msg' => "CERO envíos email en la última hora pero hay ".number_format($emails).' jobs en cola.'];
        }
        if ($smsLastHour === 0 && $envios > 0) {
            $issues[] = ['key' => 'no-sms', 'severity' => 'error', 'msg' => "CERO envíos SMS en la última hora pero hay ".number_format($envios).' jobs en cola.'];
        }
        if ($cbEmailOpen) {
            $issues[] = ['key' => 'cb-email', 'severity' => 'error', 'msg' => 'Circuit breaker EMAIL abierto.'];
        }
        if ($cbSmsOpen) {
            $issues[] = ['key' => 'cb-sms', 'severity' => 'error', 'msg' => 'Circuit breaker SMS abierto.'];
        }

        $snapshot = [
            'queue_envios' => $envios,
            'queue_emails' => $emails,
            'failed_total' => $failedTotal,
            'failed_last_hour' => $failedLastHour,
            'email_last_hour' => $emailLastHour,
            'sms_last_hour' => $smsLastHour,
            'cb_email_open' => $cbEmailOpen,
            'cb_sms_open' => $cbSmsOpen,
        ];

        if (empty($issues)) {
            $this->info('✅ Sistema sano: '.json_encode($snapshot));
            if ($this->option('force-mail')) {
                $this->sendMail([], $snapshot);
            }

            return Command::SUCCESS;
        }

        foreach ($issues as $i) {
            $method = $i['severity'] === 'error' ? 'error' : 'warn';
            $this->{$method}("[{$i['severity']}] {$i['msg']}");
            Log::{$i['severity']}('HealthCheck: '.$i['msg'], $snapshot);
        }

        if (! $this->option('no-mail')) {
            $newIssues = array_filter($issues, fn ($i) => ! Cache::has('health-alert:'.$i['key']));
            foreach ($newIssues as $i) {
                Cache::put('health-alert:'.$i['key'], true, now()->addHour());
            }
            if (! empty($newIssues)) {
                $this->sendMail($newIssues, $snapshot);
            } else {
                $this->line('Issues deduped (already alerted within last hour).');
            }
        }

        return Command::SUCCESS;
    }

    private function sendMail(array $issues, array $snapshot): void
    {
        $to = config('mail.health_check_email')
            ?? env('HEALTH_CHECK_EMAIL')
            ?? config('mail.from.address');

        if (! $to) {
            $this->error('No destinatario configurado. Definir HEALTH_CHECK_EMAIL en .env');

            return;
        }

        try {
            Mail::to($to)->send(new HealthCheckAlert($issues, $snapshot));
            $this->info("📧 Alerta enviada a $to");
        } catch (\Throwable $e) {
            Log::error('HealthCheck: no se pudo enviar mail', ['error' => $e->getMessage()]);
            $this->error('Falló envío de mail: '.$e->getMessage());
        }
    }
}
