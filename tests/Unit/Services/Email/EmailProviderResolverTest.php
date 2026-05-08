<?php

namespace Tests\Unit\Services\Email;

use App\Models\Importacion;
use App\Models\Lote;
use App\Models\Prospecto;
use App\Services\Email\CertificadaEmailService;
use App\Services\Email\EmailProviderResolver;
use App\Services\Email\SmtpEmailService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EmailProviderResolverTest extends TestCase
{
    private SmtpEmailService $smtpService;

    private CertificadaEmailService $certificadaService;

    private EmailProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smtpService = Mockery::mock(SmtpEmailService::class);
        $this->certificadaService = Mockery::mock(CertificadaEmailService::class);

        $this->resolver = new EmailProviderResolver(
            $this->smtpService,
            $this->certificadaService
        );

        // Clear health caches before each test
        Cache::forget('athena_smtp_health_status');
        Cache::forget('certificada_health_status');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================
    // TESTS: IC Prospects (always Certificada)
    // ============================================

    /** @test */
    public function test_ic_prospect_always_uses_certificada_when_healthy(): void
    {
        config(['services.email.primary_provider' => 'athena']); // Even with athena as primary

        $prospecto = $this->createProspectoWithLote('IC_Lote_2024');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->certificadaService, $result);
    }

    /** @test */
    public function test_ic_prospect_falls_back_to_athena_when_certificada_unhealthy(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspectoWithLote('IC_Lote_Fallback');

        // Certificada is unavailable
        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        // Athena is healthy (default)
        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_ic_prospect_uses_certificada_anyway_when_both_unhealthy(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspectoWithLote('IC_Both_Unhealthy');

        // Mark both as unhealthy
        EmailProviderResolver::markAthenaUnhealthy('Athena down');
        EmailProviderResolver::markCertificadaUnhealthy('Certificada down');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->twice() // Once for health check, once for fallback check
            ->andReturn(true);

        $result = $this->resolver->resolve($prospecto);

        // IC MUST try Certificada even when unhealthy
        $this->assertSame($this->certificadaService, $result);
    }

    /** @test */
    public function test_ic_lowercase_prefix_does_not_trigger_certificada(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        // ic_ lowercase should NOT be treated as IC
        $prospecto = $this->createProspectoWithLote('ic_lowercase_lote');

        // Should use Athena (primary), not Certificada
        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    // ============================================
    // TESTS: Non-IC with Athena as primary
    // ============================================

    /** @test */
    public function test_non_ic_uses_athena_when_athena_is_primary_and_healthy(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspecto();

        // Athena is healthy by default (no cache entry)
        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_non_ic_falls_back_to_certificada_when_athena_unhealthy(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspecto();

        // Mark Athena as unhealthy
        EmailProviderResolver::markAthenaUnhealthy('Test failure');

        // Certificada is available and healthy
        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->certificadaService, $result);
    }

    /** @test */
    public function test_non_ic_uses_athena_anyway_when_both_unhealthy_with_athena_primary(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspecto();

        // Mark both as unhealthy
        EmailProviderResolver::markAthenaUnhealthy('Athena down');
        EmailProviderResolver::markCertificadaUnhealthy('Certificada down');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true); // Available but unhealthy via cache

        $result = $this->resolver->resolve($prospecto);

        // Should try Athena anyway as last resort
        $this->assertSame($this->smtpService, $result);
    }

    // ============================================
    // TESTS: Non-IC with Certificada as primary
    // ============================================

    /** @test */
    public function test_non_ic_uses_certificada_when_certificada_is_primary_and_healthy(): void
    {
        config(['services.email.primary_provider' => 'certificada']);

        $prospecto = $this->createProspecto();

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->certificadaService, $result);
    }

    /** @test */
    public function test_non_ic_falls_back_to_athena_when_certificada_is_primary_but_unhealthy(): void
    {
        config(['services.email.primary_provider' => 'certificada']);

        $prospecto = $this->createProspecto();

        // Certificada is unavailable
        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        // Athena is healthy (default)
        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    // ============================================
    // TESTS: getProviderName()
    // ============================================

    /** @test */
    public function test_get_provider_name_returns_certificada_for_ic_prospect(): void
    {
        config(['services.email.primary_provider' => 'athena']); // Even with athena as primary

        $prospecto = $this->createProspectoWithLote('IC_Named_Lote');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('certificada', $name);
    }

    /** @test */
    public function test_get_provider_name_returns_athena_for_non_ic_when_athena_primary(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspecto();

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('athena', $name);
    }

    /** @test */
    public function test_get_provider_name_returns_athena_for_ic_when_certificada_unhealthy(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspectoWithLote('IC_Unhealthy_Test');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('athena', $name);
    }

    // ============================================
    // TESTS: Health status methods
    // ============================================

    /** @test */
    public function test_mark_athena_unhealthy_sets_cache(): void
    {
        EmailProviderResolver::markAthenaUnhealthy('Connection refused', 120);

        $status = Cache::get('athena_smtp_health_status');

        $this->assertFalse($status['healthy']);
        $this->assertEquals('Connection refused', $status['reason']);
        $this->assertArrayHasKey('marked_at', $status);
    }

    /** @test */
    public function test_mark_athena_healthy_sets_cache(): void
    {
        // First mark unhealthy
        EmailProviderResolver::markAthenaUnhealthy('Test');

        // Then mark healthy
        EmailProviderResolver::markAthenaHealthy();

        $status = Cache::get('athena_smtp_health_status');

        $this->assertTrue($status['healthy']);
        $this->assertArrayHasKey('checked_at', $status);
    }

    /** @test */
    public function test_mark_certificada_unhealthy_sets_cache(): void
    {
        EmailProviderResolver::markCertificadaUnhealthy('API timeout', 180);

        $status = Cache::get('certificada_health_status');

        $this->assertFalse($status['healthy']);
        $this->assertEquals('API timeout', $status['reason']);
    }

    /** @test */
    public function test_mark_certificada_healthy_sets_cache(): void
    {
        EmailProviderResolver::markCertificadaUnhealthy('Test');
        EmailProviderResolver::markCertificadaHealthy();

        $status = Cache::get('certificada_health_status');

        $this->assertTrue($status['healthy']);
    }

    // ============================================
    // TESTS: Invalid config handling
    // ============================================

    /** @test */
    public function test_defaults_to_athena_for_invalid_primary_provider_config(): void
    {
        config(['services.email.primary_provider' => 'invalid_provider']);

        $prospecto = $this->createProspecto();

        $result = $this->resolver->resolve($prospecto);

        // Should default to Athena (SMTP)
        $this->assertSame($this->smtpService, $result);
    }

    // ============================================
    // Helper methods
    // ============================================

    private function createProspecto(): Prospecto
    {
        $prospecto = new Prospecto;
        $prospecto->id = 1;
        $prospecto->email = 'test@example.com';
        $prospecto->metadata = [];
        $prospecto->setRelation('importacion', null);

        return $prospecto;
    }

    private function createProspectoWithLote(string $loteName): Prospecto
    {
        $lote = new Lote;
        $lote->id = 1;
        $lote->nombre = $loteName;

        $importacion = new Importacion;
        $importacion->id = 1;
        $importacion->setRelation('lote', $lote);

        $prospecto = new Prospecto;
        $prospecto->id = 1;
        $prospecto->email = 'test@example.com';
        $prospecto->metadata = [];
        $prospecto->setRelation('importacion', $importacion);

        return $prospecto;
    }
}
