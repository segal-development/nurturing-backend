<?php

namespace App\Jobs\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Ventana Horaria de Envíos — Job Middleware
 *
 * Restringe el handoff al proveedor a horario hábil (Chile).
 * Fuera de ventana, difiere el job con release() hasta la próxima apertura.
 * Resolución por-canal: email ON por defecto, sms opt-in.
 *
 * ORDEN EN middleware(): Este middleware DEBE ser el PRIMERO, antes de
 * RateLimitedMiddleware. Esto garantiza que:
 *   1. Jobs fuera de horario no tocan los contadores de rate-limit.
 *   2. El circuit breaker no se activa por jobs de madrugada.
 *
 * NUNCA usa SQL now() (UTC). Usa Carbon::now() que respeta app.timezone (Chile).
 *
 * @todo TODO_CANAL Toda nueva hoja de envío DEBE componer
 *       SendingWindowMiddleware('canal') ANTES de RateLimitedMiddleware('canal')
 *       en su método middleware(). Sin esto, los envíos de ese canal no tienen
 *       restricción horaria.
 */
class SendingWindowMiddleware
{
    private string $channel;

    private array $config;

    public function __construct(string $channel = 'email')
    {
        $this->channel = $channel;
        $this->config = config('envios.sending_window', []);
    }

    /**
     * Procesa el job a través del middleware de ventana horaria.
     *
     * Tres ramas:
     *   1. Canal desactivado (enabled=false o channels.{canal}=false) → passthrough.
     *   2. Dentro de ventana → passthrough al siguiente middleware.
     *   3. Fuera de ventana → release con delay, NO llama $next.
     */
    public function handle(object $job, Closure $next): void
    {
        // 1. Passthrough si feature off o canal no aplica
        if (! $this->isEnabledForChannel()) {
            $next($job);

            return;
        }

        $now = Carbon::now(); // hora Chile via app.timezone, NUNCA SQL now()

        // 2. Dentro de ventana → pasa al rate-limiter
        if ($this->isWithinWindow($now)) {
            $next($job);

            return;
        }

        // 3. Fuera de ventana → diferir. RETORNA antes de $next (sin doble release)
        $delay = $this->secondsUntilNextOpening($now);

        if (config('envios.monitoring.log_rate_limits', true)) {
            Log::info('SendingWindowMiddleware: Job diferido fuera de ventana', [
                'channel'         => $this->channel,
                'job_class'       => get_class($job),
                'delay_seconds'   => $delay,
                'next_opening'    => $this->nextOpeningAt($now)->toDateTimeString(),
                'now'             => $now->toDateTimeString(),
            ]);
        }

        $job->release($delay);
        // NO se llama $next → el rate-limiter NO se ejecuta, no incrementa contadores
    }

    /**
     * Verifica si el middleware está activo para el canal actual.
     *
     * Retorna false si:
     * - enabled global es false
     * - channels.{canal} es false
     */
    private function isEnabledForChannel(): bool
    {
        if (! ($this->config['enabled'] ?? false)) {
            return false;
        }

        return (bool) ($this->config['channels'][$this->channel] ?? false);
    }

    /**
     * Verifica si el momento dado cae dentro de la ventana horaria.
     *
     * Condiciones para estar "dentro":
     * - dayOfWeekIso está en weekdays (1=lunes..7=domingo)
     * - hour >= start_hour AND hour < end_hour (end exclusivo)
     */
    public function isWithinWindow(Carbon $now): bool
    {
        $weekdays  = $this->config['weekdays']   ?? [1, 2, 3, 4, 5];
        $startHour = (int) ($this->config['start_hour'] ?? 8);
        $endHour   = (int) ($this->config['end_hour']   ?? 21);

        if (! in_array($now->dayOfWeekIso, $weekdays, true)) {
            return false;
        }

        return $now->hour >= $startHour && $now->hour < $endHour;
    }

    /**
     * Calcula el próximo instante de apertura de ventana.
     *
     * Lógica:
     * - Si hour < start_hour: hoy mismo a start_hour (ej. madrugada 03:00 → hoy 08:00)
     * - Si hour >= end_hour o fuera de horario: mañana a start_hour
     * - Luego salta días no hábiles (fin de semana, weekdays no configurados)
     */
    public function nextOpeningAt(Carbon $now): Carbon
    {
        $startHour = (int) ($this->config['start_hour'] ?? 8);
        $weekdays  = $this->config['weekdays'] ?? [1, 2, 3, 4, 5];

        $candidate = $now->copy();

        if ($candidate->hour < $startHour) {
            // Madrugada del mismo día hábil → hoy a start_hour
            $candidate->setTime($startHour, 0, 0);
        } else {
            // Hora >= end_hour (o dentro de horario pero día no hábil) → mañana
            $candidate->addDay()->setTime($startHour, 0, 0);
        }

        // Saltar fin de semana / días no hábiles configurados
        while (! in_array($candidate->dayOfWeekIso, $weekdays, true)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * Calcula los segundos hasta la próxima apertura, con jitter opcional.
     *
     * strategy=jitter → base + random_int(0, jitter_max)
     * strategy=burst  → exactamente base (sin variación)
     */
    public function secondsUntilNextOpening(Carbon $now): int
    {
        $base = (int) $now->diffInSeconds($this->nextOpeningAt($now));

        $strategy = $this->config['backlog']['strategy'] ?? 'jitter';
        if ($strategy === 'jitter') {
            $jitterMax = (int) ($this->config['backlog']['jitter_max'] ?? 300);
            $base += random_int(0, max(0, $jitterMax));
        }
        // 'burst' → sin jitter; el rate-limiter pacea el pico del lunes

        return $base;
    }
}
