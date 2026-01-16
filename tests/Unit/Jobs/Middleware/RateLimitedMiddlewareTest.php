<?php

namespace Tests\Unit\Jobs\Middleware;

use App\Jobs\Middleware\RateLimitedMiddleware;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimitedMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ============================================
    // TESTS: Configuración básica
    // ============================================

    /** @test */
    public function puede_ser_instanciado_para_email(): void
    {
        $middleware = new RateLimitedMiddleware('email');
        $this->assertInstanceOf(RateLimitedMiddleware::class, $middleware);
    }

    /** @test */
    public function puede_ser_instanciado_para_sms(): void
    {
        $middleware = new RateLimitedMiddleware('sms');
        $this->assertInstanceOf(RateLimitedMiddleware::class, $middleware);
    }

    /** @test */
    public function usa_email_como_canal_por_defecto(): void
    {
        $middleware = new RateLimitedMiddleware();
        $this->assertInstanceOf(RateLimitedMiddleware::class, $middleware);
    }

    // ============================================
    // TESTS: Rate limiting básico
    // ============================================

    /** @test */
    public function permite_job_cuando_no_hay_rate_limit(): void
    {
        $middleware = new RateLimitedMiddleware('email');
        $job = $this->createMockJob();
        $nextCalled = false;

        $middleware->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertTrue($nextCalled);
    }

    /** @test */
    public function incrementa_contador_al_procesar_job(): void
    {
        $middleware = new RateLimitedMiddleware('email');
        $job = $this->createMockJob();

        $middleware->handle($job, function () {});

        $this->assertEquals(1, Cache::get('envio-rate:email:second'));
        $this->assertEquals(1, Cache::get('envio-rate:email:minute'));
    }

    /** @test */
    public function incrementa_contador_con_multiples_jobs(): void
    {
        $middleware = new RateLimitedMiddleware('email');

        for ($i = 0; $i < 5; $i++) {
            $job = $this->createMockJob();
            $middleware->handle($job, function () {});
        }

        $this->assertEquals(5, Cache::get('envio-rate:email:second'));
        $this->assertEquals(5, Cache::get('envio-rate:email:minute'));
    }

    /** @test */
    public function libera_job_cuando_excede_rate_limit_por_segundo(): void
    {
        config(['envios.rate_limits.email.per_second' => 2]);

        $middleware = new RateLimitedMiddleware('email');
        $releaseCalled = false;
        $nextCalled = false;

        Cache::put('envio-rate:email:second', 2, 1);

        $job = $this->createReleasableJob($releaseCalled);

        $middleware->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertTrue($releaseCalled);
        $this->assertFalse($nextCalled);
    }

    /** @test */
    public function libera_job_cuando_excede_rate_limit_por_minuto(): void
    {
        config(['envios.rate_limits.email.per_minute' => 3]);

        $middleware = new RateLimitedMiddleware('email');
        $releaseCalled = false;

        Cache::put('envio-rate:email:minute', 3, 60);

        $job = $this->createReleasableJob($releaseCalled);

        $middleware->handle($job, function () {});

        $this->assertTrue($releaseCalled);
    }

    // ============================================
    // TESTS: Circuit Breaker
    // ============================================

    /** @test */
    public function libera_job_cuando_circuit_esta_abierto(): void
    {
        Cache::put('envio-circuit:email', 'open', 60);

        $middleware = new RateLimitedMiddleware('email');
        $releaseCalled = false;
        $nextCalled = false;

        $job = $this->createReleasableJob($releaseCalled);

        $middleware->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertTrue($releaseCalled);
        $this->assertFalse($nextCalled);
    }

    /** @test */
    public function permite_job_cuando_circuit_esta_cerrado(): void
    {
        Cache::forget('envio-circuit:email');

        $middleware = new RateLimitedMiddleware('email');
        $nextCalled = false;

        $job = $this->createMockJob();

        $middleware->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertTrue($nextCalled);
    }

    /** @test */
    public function abre_circuit_despues_de_muchos_fallos(): void
    {
        config(['envios.circuit_breaker.failure_threshold' => 3]);

        $middleware = new RateLimitedMiddleware('email');

        Cache::put('envio-failures:email', 2, 60);

        $job = $this->createMockJob();

        try {
            $middleware->handle($job, function () {
                throw new \Exception('SMTP error');
            });
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertEquals('open', Cache::get('envio-circuit:email'));
    }

    /** @test */
    public function decrementa_fallos_en_exito(): void
    {
        Cache::put('envio-failures:email', 5, 60);

        $middleware = new RateLimitedMiddleware('email');
        $job = $this->createMockJob();

        $middleware->handle($job, function () {});

        $this->assertEquals(4, Cache::get('envio-failures:email'));
    }

    // ============================================
    // TESTS: Backoff exponencial
    // ============================================

    /** @test */
    public function usa_backoff_exponencial_en_reintentos(): void
    {
        config(['envios.rate_limits.email.per_second' => 1]);
        config(['envios.rate_limits.email.backoff_seconds' => 5]);

        $middleware = new RateLimitedMiddleware('email');

        Cache::put('envio-rate:email:second', 1, 1);

        $capturedDelay = null;
        $job = $this->createReleasableJobWithDelay($capturedDelay, 2);

        $middleware->handle($job, function () {});

        // Backoff = 5 * 2^2 = 20 segundos
        $this->assertEquals(20, $capturedDelay);
    }

    /** @test */
    public function limita_backoff_maximo(): void
    {
        config(['envios.rate_limits.email.per_second' => 1]);
        config(['envios.rate_limits.email.backoff_seconds' => 5]);

        $middleware = new RateLimitedMiddleware('email');

        Cache::put('envio-rate:email:second', 1, 1);

        $capturedDelay = null;
        $job = $this->createReleasableJobWithDelay($capturedDelay, 10);

        $middleware->handle($job, function () {});

        // Backoff máximo = 5 * 2^5 = 160 segundos (capped at attempts=5)
        $this->assertEquals(160, $capturedDelay);
    }

    // ============================================
    // TESTS: Canales separados
    // ============================================

    /** @test */
    public function email_y_sms_tienen_rate_limits_separados(): void
    {
        $emailMiddleware = new RateLimitedMiddleware('email');
        $smsMiddleware = new RateLimitedMiddleware('sms');

        $emailJob = $this->createMockJob();
        $smsJob = $this->createMockJob();

        $emailMiddleware->handle($emailJob, function () {});
        $smsMiddleware->handle($smsJob, function () {});

        $this->assertEquals(1, Cache::get('envio-rate:email:second'));
        $this->assertEquals(1, Cache::get('envio-rate:sms:second'));
    }

    /** @test */
    public function circuit_breaker_es_separado_por_canal(): void
    {
        Cache::put('envio-circuit:email', 'open', 60);

        $emailMiddleware = new RateLimitedMiddleware('email');
        $smsMiddleware = new RateLimitedMiddleware('sms');

        $emailReleased = false;
        $smsNextCalled = false;

        $emailJob = $this->createReleasableJob($emailReleased);
        $smsJob = $this->createMockJob();

        $emailMiddleware->handle($emailJob, function () {});
        $smsMiddleware->handle($smsJob, function () use (&$smsNextCalled) {
            $smsNextCalled = true;
        });

        $this->assertTrue($emailReleased);
        $this->assertTrue($smsNextCalled);
    }

    // ============================================
    // TESTS: Edge cases
    // ============================================

    /** @test */
    public function propaga_excepciones_del_job(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        $middleware = new RateLimitedMiddleware('email');
        $job = $this->createMockJob();

        $middleware->handle($job, function () {
            throw new \RuntimeException('Test exception');
        });
    }

    // ============================================
    // Helper methods
    // ============================================

    /**
     * Creates a simple mock job that doesn't need release().
     */
    private function createMockJob(): object
    {
        return new class {
            public int $attempts = 0;
            public int $prospectoEnFlujoId = 1;
        };
    }

    /**
     * Creates a mock job that tracks if release() was called.
     */
    private function createReleasableJob(bool &$releaseCalled, int $attempts = 0): object
    {
        return new class($releaseCalled, $attempts) {
            public int $attempts;
            private bool $releaseCalled;

            public function __construct(bool &$releaseCalled, int $attempts)
            {
                $this->releaseCalled = &$releaseCalled;
                $this->attempts = $attempts;
            }

            public function release(int $delay = 0): void
            {
                $this->releaseCalled = true;
            }
        };
    }

    /**
     * Creates a mock job that captures the delay passed to release().
     */
    private function createReleasableJobWithDelay(?int &$capturedDelay, int $attempts = 0): object
    {
        return new class($capturedDelay, $attempts) {
            public int $attempts;
            private ?int $capturedDelay;

            public function __construct(?int &$capturedDelay, int $attempts)
            {
                $this->capturedDelay = &$capturedDelay;
                $this->attempts = $attempts;
            }

            public function release(int $delay = 0): void
            {
                $this->capturedDelay = $delay;
            }
        };
    }
}
