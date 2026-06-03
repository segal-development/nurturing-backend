<?php

namespace Tests\Unit\Jobs\Middleware;

use App\Jobs\EnviarEmailEtapaProspectoJob;
use App\Jobs\EnviarSmsEtapaProspectoJob;
use App\Jobs\Middleware\SendingWindowMiddleware;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SendingWindowMiddlewareTest extends TestCase
{
    // ============================================
    // SETUP / TEARDOWN
    // ============================================

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // reset time mock
        parent::tearDown();
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Job sin release (passthrough simple).
     */
    private function jobPassthrough(): object
    {
        return new class
        {
            public int $attempts = 0;

            public function release(int $delay = 0): void
            {
                throw new \LogicException('release() no debería ser llamado en este test');
            }
        };
    }

    /**
     * Job que captura el delay pasado a release().
     */
    private function jobWithDelay(?int &$delay): object
    {
        return new class($delay)
        {
            public int $attempts = 0;

            private ?int $captured;

            public function __construct(?int &$delay)
            {
                $this->captured = &$delay;
            }

            public function release(int $delay = 0): void
            {
                $this->captured = $delay;
            }
        };
    }

    /**
     * Job que captura si release() fue llamado.
     */
    private function jobWithRelease(bool &$called): object
    {
        return new class($called)
        {
            public int $attempts = 0;

            private bool $called;

            public function __construct(bool &$called)
            {
                $this->called = &$called;
            }

            public function release(int $delay = 0): void
            {
                $this->called = true;
            }
        };
    }

    // ============================================
    // TESTS: Instanciación básica
    // ============================================

    /** @test */
    public function puede_ser_instanciado_con_canal_email(): void
    {
        $middleware = new SendingWindowMiddleware('email');
        $this->assertInstanceOf(SendingWindowMiddleware::class, $middleware);
    }

    /** @test */
    public function puede_ser_instanciado_con_canal_sms(): void
    {
        $middleware = new SendingWindowMiddleware('sms');
        $this->assertInstanceOf(SendingWindowMiddleware::class, $middleware);
    }

    // ============================================
    // TESTS: Dentro de ventana — pasa
    // ============================================

    /** @test */
    public function pasa_dentro_de_ventana_miercoles_10_00(): void
    {
        // miércoles 2026-06-03 10:00 Santiago
        Carbon::setTestNow(Carbon::create(2026, 6, 3, 10, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'   => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        $nextCalled = false;
        $job = $this->jobPassthrough();

        (new SendingWindowMiddleware('email'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertTrue($nextCalled, '$next debería haber sido invocado dentro de ventana');
    }

    /** @test */
    public function pasa_en_edge_viernes_20_59_59(): void
    {
        // viernes 2026-06-05 20:59:59 Santiago — último segundo antes de cierre
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 20, 59, 59, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'   => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        $nextCalled = false;
        $job = $this->jobPassthrough();

        (new SendingWindowMiddleware('email'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertTrue($nextCalled, 'Edge viernes 20:59:59 debería dejar pasar');
    }

    // ============================================
    // TESTS: Fuera de ventana — difiere
    // ============================================

    /** @test */
    public function difiere_en_madrugada_lunes_03_00_burst(): void
    {
        // lunes 2026-06-02 03:00:00 Santiago — 5h antes de apertura
        Carbon::setTestNow(Carbon::create(2026, 6, 2, 3, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'   => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        $delay = null;
        $job = $this->jobWithDelay($delay);
        $nextCalled = false;

        (new SendingWindowMiddleware('email'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertFalse($nextCalled, '$next NO debe ser invocado fuera de ventana');
        $this->assertSame(5 * 3600, $delay, 'Delay debe ser 18000s (5h hasta las 08:00)');
    }

    /** @test */
    public function difiere_pasada_hora_cierre_lunes_21_00_burst(): void
    {
        // lunes 2026-06-01 21:00:00 Santiago — 11h hasta mañana 08:00
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 21, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'   => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        $delay = null;
        $job = $this->jobWithDelay($delay);
        $nextCalled = false;

        (new SendingWindowMiddleware('email'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertFalse($nextCalled, '$next NO debe ser invocado');
        $this->assertSame(11 * 3600, $delay, 'Delay debe ser 39600s (11h hasta martes 08:00)');
    }

    /** @test */
    public function difiere_edge_viernes_21_00_hasta_lunes_08_00_burst(): void
    {
        // viernes 2026-06-05 21:00:00 Santiago — debe diferir a lunes 08:00
        // Nota: 2026-06-05 = viernes (dayOfWeekIso=5)
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 21, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'   => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        // Calcular delay esperado dinámicamente
        // viernes 21:00 → sábado 08:00 (no hábil) → domingo 08:00 (no hábil) → lunes 08:00
        $now = Carbon::create(2026, 6, 5, 21, 0, 0, 'America/Santiago');
        $lunes = Carbon::create(2026, 6, 8, 8, 0, 0, 'America/Santiago'); // lunes 2026-06-08
        $expectedDelay = (int) $now->diffInSeconds($lunes); // now->future = positive

        $delay = null;
        $job = $this->jobWithDelay($delay);
        $nextCalled = false;

        (new SendingWindowMiddleware('email'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertFalse($nextCalled, '$next NO debe ser invocado');
        $this->assertGreaterThanOrEqual(208800, $delay, 'Delay debe ser >= 58h (hasta lunes 08:00)');
        $this->assertSame($expectedDelay, $delay, 'Delay viernes 21:00 debe coincidir con el calculado dinámicamente');
    }

    /** @test */
    public function difiere_sabado_12_00_hasta_lunes_08_00_burst(): void
    {
        // sábado 2026-06-06 12:00:00 Santiago (2026-06-06 = sábado, dayOfWeekIso=6)
        Carbon::setTestNow(Carbon::create(2026, 6, 6, 12, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'   => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        // Calcular dinámicamente: sábado 12:00 → domingo 08:00 (no hábil) → lunes 08:00
        $now = Carbon::create(2026, 6, 6, 12, 0, 0, 'America/Santiago');
        $lunes = Carbon::create(2026, 6, 8, 8, 0, 0, 'America/Santiago');
        $expectedDelay = (int) $now->diffInSeconds($lunes); // now->future = positive

        $delay = null;
        $job = $this->jobWithDelay($delay);
        $nextCalled = false;

        (new SendingWindowMiddleware('email'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertFalse($nextCalled, '$next NO debe ser invocado (sábado)');
        $this->assertSame($expectedDelay, $delay, 'Delay sábado debe apuntar a lunes 08:00');
    }

    /** @test */
    public function difiere_domingo_23_00_hasta_lunes_08_00_burst(): void
    {
        // domingo 2026-06-07 23:00:00 Santiago (2026-06-07 = domingo, dayOfWeekIso=7)
        Carbon::setTestNow(Carbon::create(2026, 6, 7, 23, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'   => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        // Calcular dinámicamente: domingo 23:00 → lunes 08:00 (próxima apertura)
        $now = Carbon::create(2026, 6, 7, 23, 0, 0, 'America/Santiago');
        $lunes = Carbon::create(2026, 6, 8, 8, 0, 0, 'America/Santiago');
        $expectedDelay = (int) $now->diffInSeconds($lunes); // now->future = positive

        $delay = null;
        $job = $this->jobWithDelay($delay);
        $nextCalled = false;

        (new SendingWindowMiddleware('email'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertFalse($nextCalled, '$next NO debe ser invocado (domingo)');
        $this->assertSame($expectedDelay, $delay, 'Delay domingo debe apuntar a lunes 08-jun 08:00');
    }

    // ============================================
    // TESTS: Desactivación global / por canal
    // ============================================

    /** @test */
    public function passthrough_cuando_enabled_false_aunque_fuera_de_ventana(): void
    {
        // domingo 02:00 — fuera de ventana, pero enabled=false
        // 2026-06-07 = domingo (dayOfWeekIso=7)
        Carbon::setTestNow(Carbon::create(2026, 6, 7, 2, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'       => false,
            'envios.sending_window.channels.email' => true,
        ]);

        $nextCalled = false;
        $releaseCalled = false;
        $job = $this->jobWithRelease($releaseCalled);

        (new SendingWindowMiddleware('email'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertTrue($nextCalled, 'Con enabled=false debe hacer passthrough');
        $this->assertFalse($releaseCalled, 'release() NO debe ser llamado cuando enabled=false');
    }

    /** @test */
    public function passthrough_canal_sms_cuando_channels_sms_false_fuera_de_ventana(): void
    {
        // domingo 02:00 — fuera de ventana, pero channels.sms=false
        // 2026-06-07 = domingo (dayOfWeekIso=7)
        Carbon::setTestNow(Carbon::create(2026, 6, 7, 2, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'       => true,
            'envios.sending_window.start_hour'     => 8,
            'envios.sending_window.end_hour'       => 21,
            'envios.sending_window.weekdays'       => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.sms'   => false,
        ]);

        $nextCalled = false;
        $releaseCalled = false;
        $job = $this->jobWithRelease($releaseCalled);

        (new SendingWindowMiddleware('sms'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertTrue($nextCalled, 'Con channels.sms=false debe hacer passthrough');
        $this->assertFalse($releaseCalled, 'release() NO debe ser llamado con canal desactivado');
    }

    /** @test */
    public function difiere_canal_sms_cuando_channels_sms_true_fuera_de_ventana(): void
    {
        // domingo 02:00 — fuera de ventana y channels.sms=true
        // 2026-06-07 = domingo (dayOfWeekIso=7)
        Carbon::setTestNow(Carbon::create(2026, 6, 7, 2, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.sms'     => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        $releaseCalled = false;
        $nextCalled = false;
        $job = $this->jobWithRelease($releaseCalled);

        (new SendingWindowMiddleware('sms'))->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertTrue($releaseCalled, 'Con channels.sms=true fuera de ventana debe hacer release');
        $this->assertFalse($nextCalled, '$next NO debe ser invocado');
    }

    // ============================================
    // TESTS: Jitter / Burst
    // ============================================

    /** @test */
    public function jitter_strategy_delay_en_rango_correcto(): void
    {
        // lunes 2026-06-02 03:00:00 — base 18000s, jitter_max=300
        config([
            'envios.sending_window.enabled'           => true,
            'envios.sending_window.start_hour'         => 8,
            'envios.sending_window.end_hour'           => 21,
            'envios.sending_window.weekdays'           => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'     => true,
            'envios.sending_window.backlog.strategy'   => 'jitter',
            'envios.sending_window.backlog.jitter_max' => 300,
        ]);

        $base = 5 * 3600; // 18000

        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow(Carbon::create(2026, 6, 2, 3, 0, 0, 'America/Santiago'));

            $delay = null;
            $job = $this->jobWithDelay($delay);

            (new SendingWindowMiddleware('email'))->handle($job, function () {
                // no importa
            });

            $this->assertGreaterThanOrEqual($base, $delay, "Iteración $i: delay debe ser >= base ($base)");
            $this->assertLessThanOrEqual($base + 300, $delay, "Iteración $i: delay debe ser <= base+300 (".($base + 300).')');
        }
    }

    /** @test */
    public function burst_strategy_delay_exactamente_base(): void
    {
        // lunes 2026-06-02 03:00:00 — base 18000s exacto
        Carbon::setTestNow(Carbon::create(2026, 6, 2, 3, 0, 0, 'America/Santiago'));

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'   => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        $delay = null;
        $job = $this->jobWithDelay($delay);

        (new SendingWindowMiddleware('email'))->handle($job, function () {});

        $this->assertSame(5 * 3600, $delay, 'Burst debe ser exactamente 18000s sin jitter');
    }

    // ============================================
    // TESTS: Composición — release NO encadena $next
    // ============================================

    /** @test */
    public function release_no_encadena_next(): void
    {
        // fuera de ventana — asegurar que $next NUNCA es llamado al hacer release
        // 2026-06-07 = domingo (dayOfWeekIso=7)
        Carbon::setTestNow(Carbon::create(2026, 6, 7, 2, 0, 0, 'America/Santiago')); // domingo 02:00

        config([
            'envios.sending_window.enabled'         => true,
            'envios.sending_window.start_hour'       => 8,
            'envios.sending_window.end_hour'         => 21,
            'envios.sending_window.weekdays'         => [1, 2, 3, 4, 5],
            'envios.sending_window.channels.email'   => true,
            'envios.sending_window.backlog.strategy' => 'burst',
        ]);

        $delay = null;
        $job = $this->jobWithDelay($delay);

        // $next lanza excepción si se llama — el test pasa solo si NO se llama
        (new SendingWindowMiddleware('email'))->handle($job, function () {
            throw new \LogicException('$next NO debe ser invocado cuando el middleware hace release');
        });

        $this->assertNotNull($delay, 'release() sí fue llamado');
    }

    // ============================================
    // TESTS: Composición en hojas de envío
    // ============================================

    /** @test */
    public function email_job_tiene_sending_window_middleware_primero(): void
    {
        $job = new EnviarEmailEtapaProspectoJob(
            prospectoEnFlujoId: 1,
            contenido: 'test',
            asunto: 'test'
        );

        $middlewares = $job->middleware();

        $this->assertInstanceOf(
            SendingWindowMiddleware::class,
            $middlewares[0],
            'El primer middleware de EnviarEmailEtapaProspectoJob debe ser SendingWindowMiddleware'
        );
        $this->assertInstanceOf(
            \App\Jobs\Middleware\RateLimitedMiddleware::class,
            $middlewares[1],
            'El segundo middleware debe ser RateLimitedMiddleware'
        );
    }

    /** @test */
    public function sms_job_tiene_sending_window_middleware_primero(): void
    {
        $job = new EnviarSmsEtapaProspectoJob(
            prospectoEnFlujoId: 1,
            contenido: 'test'
        );

        $middlewares = $job->middleware();

        $this->assertInstanceOf(
            SendingWindowMiddleware::class,
            $middlewares[0],
            'El primer middleware de EnviarSmsEtapaProspectoJob debe ser SendingWindowMiddleware'
        );
        $this->assertInstanceOf(
            \App\Jobs\Middleware\RateLimitedMiddleware::class,
            $middlewares[1],
            'El segundo middleware debe ser RateLimitedMiddleware'
        );
    }

    /** @test */
    public function sending_window_middleware_tiene_constructor_con_canal(): void
    {
        // Smoke test: previene regresión si alguien quita el argumento $channel
        $reflection = new \ReflectionClass(SendingWindowMiddleware::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'SendingWindowMiddleware debe tener constructor');
        $params = $constructor->getParameters();
        $this->assertNotEmpty($params, 'Constructor debe tener al menos un parámetro ($channel)');
        $this->assertSame('channel', $params[0]->getName(), 'Primer parámetro debe llamarse $channel');
    }
}
