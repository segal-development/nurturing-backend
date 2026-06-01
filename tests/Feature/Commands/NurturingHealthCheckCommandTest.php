<?php

namespace Tests\Feature\Commands;

use App\Console\Commands\NurturingHealthCheckCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature tests for queue depth alerting in NurturingHealthCheckCommand.
 *
 * Verifies AC-9 from spec:
 * - Warning at > 50k jobs in 'emails' queue (key: queue-emails, severity: warning)
 * - Critical at > 200k jobs in 'emails' queue (key: queue-emails-critical, severity: error)
 * - Dedup per level with independent 1h TTL (Cache key 'health-alert:{key}')
 *
 * Uses a test-double subclass that overrides getQueueDepth() to return
 * configurable values without DB inserts. Mail is faked so no actual email
 * is sent (but the dedup/cache logic still runs fully).
 */
class NurturingHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

    // ============================================
    // Scenario: Cola en 60k — dispara warning, no critical
    // ============================================

    /** @test */
    public function alerta_warning_cuando_cola_emails_supera_50k(): void
    {
        $this->runWithDepths(emailsDepth: 60_000);

        $this->assertTrue(
            Cache::has('health-alert:queue-emails'),
            'Cache key queue-emails debe estar activa después del warning'
        );
        $this->assertFalse(
            Cache::has('health-alert:queue-emails-critical'),
            'Cache key queue-emails-critical no debe estar activa para 60k'
        );
    }

    /** @test */
    public function no_alerta_cuando_cola_emails_esta_bajo_50k(): void
    {
        $this->runWithDepths(emailsDepth: 49_999);

        $this->assertFalse(
            Cache::has('health-alert:queue-emails'),
            'Cache key queue-emails no debe estar activa para 49999 jobs'
        );
    }

    // ============================================
    // Scenario: Cola en 210k — dispara warning Y critical
    // ============================================

    /** @test */
    public function alerta_critical_cuando_cola_emails_supera_200k(): void
    {
        $this->runWithDepths(emailsDepth: 210_000);

        $this->assertTrue(
            Cache::has('health-alert:queue-emails'),
            'Cache key queue-emails debe estar activa para 210k'
        );
        $this->assertTrue(
            Cache::has('health-alert:queue-emails-critical'),
            'Cache key queue-emails-critical debe estar activa para 210k'
        );
    }

    // ============================================
    // Scenario: Dedup — no se re-emite dentro de 1h
    // ============================================

    /** @test */
    public function no_duplica_alerta_warning_dentro_de_1h(): void
    {
        // Primera corrida — emite alerta
        $this->runWithDepths(emailsDepth: 60_000);
        $this->assertTrue(Cache::has('health-alert:queue-emails'));

        // Segunda corrida — la clave ya existe en caché
        $output = $this->runWithDepths(emailsDepth: 60_000, captureOutput: true);
        $this->assertStringContainsString(
            'Issues deduped (already alerted within last hour).',
            $output
        );
    }

    /** @test */
    public function no_duplica_alerta_critical_dentro_de_1h(): void
    {
        // Primera corrida — emite warning + critical
        $this->runWithDepths(emailsDepth: 210_000);
        $this->assertTrue(Cache::has('health-alert:queue-emails'));
        $this->assertTrue(Cache::has('health-alert:queue-emails-critical'));

        // Segunda corrida — ambas claves ya en caché
        $output = $this->runWithDepths(emailsDepth: 210_000, captureOutput: true);
        $this->assertStringContainsString(
            'Issues deduped (already alerted within last hour).',
            $output
        );
    }

    /** @test */
    public function re_emite_warning_cuando_ttl_expira(): void
    {
        // Primera corrida
        $this->runWithDepths(emailsDepth: 60_000);
        $this->assertTrue(Cache::has('health-alert:queue-emails'));

        // Simular TTL vencido
        Cache::forget('health-alert:queue-emails');

        // Segunda corrida — debe re-emitir
        $this->runWithDepths(emailsDepth: 60_000);

        $this->assertTrue(
            Cache::has('health-alert:queue-emails'),
            'Debe re-emitir después de que el TTL venció'
        );
    }

    // ============================================
    // Scenario: Boundary checks
    // ============================================

    /** @test */
    public function exactamente_50001_genera_solo_warning(): void
    {
        $this->runWithDepths(emailsDepth: 50_001);

        $this->assertTrue(Cache::has('health-alert:queue-emails'));
        $this->assertFalse(Cache::has('health-alert:queue-emails-critical'));
    }

    /** @test */
    public function exactamente_200001_genera_warning_y_critical(): void
    {
        $this->runWithDepths(emailsDepth: 200_001);

        $this->assertTrue(Cache::has('health-alert:queue-emails'));
        $this->assertTrue(Cache::has('health-alert:queue-emails-critical'));
    }

    /** @test */
    public function cola_en_cero_no_genera_alertas_de_profundidad(): void
    {
        $this->runWithDepths(emailsDepth: 0);

        $this->assertFalse(Cache::has('health-alert:queue-emails'));
        $this->assertFalse(Cache::has('health-alert:queue-emails-critical'));
    }

    // ============================================
    // Helper: run command with overridden queue depths
    // ============================================

    /**
     * Create and run a NurturingHealthCheckCommand subclass that returns
     * fixed queue depths without inserting DB rows.
     * Mail is faked in setUp so no real email is sent.
     */
    private function runWithDepths(
        int $emailsDepth = 0,
        int $enviosDepth = 0,
        bool $captureOutput = false,
    ): string {
        $command = new class ($emailsDepth, $enviosDepth) extends NurturingHealthCheckCommand {
            public function __construct(
                private readonly int $emailsDepth,
                private readonly int $enviosDepth,
            ) {
                parent::__construct();
            }

            protected function getQueueDepth(string $queueName): int
            {
                return match ($queueName) {
                    'emails' => $this->emailsDepth,
                    'envios' => $this->enviosDepth,
                    default  => 0,
                };
            }
        };

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $input  = new \Symfony\Component\Console\Input\ArrayInput([]);

        $command->setLaravel($this->app);
        $command->run($input, $output);

        return $captureOutput ? $output->fetch() : '';
    }
}
