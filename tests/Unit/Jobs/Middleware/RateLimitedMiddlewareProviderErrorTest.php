<?php

namespace Tests\Unit\Jobs\Middleware;

use App\Jobs\Middleware\RateLimitedMiddleware;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Only provider (SMTP/SMS API) failures may feed the circuit breaker.
 * Local database errors must never count, whatever their message contains.
 */
class RateLimitedMiddlewareProviderErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['envios.circuit_breaker.failure_threshold' => 1]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function runFailingJob(\Throwable $exception): void
    {
        $middleware = new RateLimitedMiddleware('sms');

        try {
            $middleware->handle(new class {}, function () use ($exception) {
                throw $exception;
            });
        } catch (\Throwable $e) {
            // Expected: the middleware always rethrows
        }
    }

    private function uniqueViolation(): QueryException
    {
        return new QueryException(
            'pgsql',
            'insert into "envios" ("prospecto_id") values (?)',
            [45012],
            new \PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "envios_prospecto_etapa_canal_unique" DETAIL: Key (prospecto_id)=(45012) already exists.', 23505)
        );
    }

    /** @test */
    public function query_exception_does_not_count_as_provider_failure(): void
    {
        $this->runFailingJob($this->uniqueViolation());

        $this->assertNull(Cache::get('envio-circuit:sms'));
        $this->assertNull(Cache::get('envio-failures:sms'));
    }

    /** @test */
    public function pdo_exception_does_not_count_as_provider_failure(): void
    {
        $this->runFailingJob(new \PDOException('SQLSTATE[08006]: connection timeout, id 500'));

        $this->assertNull(Cache::get('envio-circuit:sms'));
        $this->assertNull(Cache::get('envio-failures:sms'));
    }

    /** @test */
    public function http_503_still_counts_as_provider_failure(): void
    {
        $this->runFailingJob(new \RuntimeException('HTTP 503 Service Unavailable'));

        $this->assertSame('open', Cache::get('envio-circuit:sms'));
    }

    /** @test */
    public function connection_timeout_still_counts_as_provider_failure(): void
    {
        $this->runFailingJob(new \RuntimeException('connection timeout'));

        $this->assertSame('open', Cache::get('envio-circuit:sms'));
    }
}
