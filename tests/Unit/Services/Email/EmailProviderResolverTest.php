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
    // TESTS: Primary provider = Athena (default)
    // ============================================

    /** @test */
    public function test_resolves_to_smtp_when_athena_is_primary_and_healthy(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspecto();

        // Athena is healthy by default (no cache entry)
        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_falls_back_to_certificada_when_athena_is_unhealthy(): void
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
    public function test_uses_smtp_anyway_when_both_providers_unhealthy_with_athena_primary(): void
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
    // TESTS: Primary provider = Certificada
    // ============================================

    /** @test */
    public function test_resolves_to_certificada_when_certificada_is_primary_and_healthy(): void
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
    public function test_falls_back_to_smtp_when_certificada_is_primary_but_unhealthy(): void
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

    /** @test */
    public function test_falls_back_to_smtp_when_certificada_marked_unhealthy_via_cache(): void
    {
        config(['services.email.primary_provider' => 'certificada']);

        $prospecto = $this->createProspecto();

        // Certificada is available but marked unhealthy
        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        EmailProviderResolver::markCertificadaUnhealthy('API timeout');

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_uses_certificada_anyway_when_both_providers_unhealthy_with_certificada_primary(): void
    {
        config(['services.email.primary_provider' => 'certificada']);

        $prospecto = $this->createProspecto();

        // Mark both as unhealthy
        EmailProviderResolver::markAthenaUnhealthy('Athena down');
        EmailProviderResolver::markCertificadaUnhealthy('Certificada down');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->twice() // Called once for primary check, once for fallback check
            ->andReturn(true);

        $result = $this->resolver->resolve($prospecto);

        // Should try Certificada anyway as last resort (it's the primary)
        $this->assertSame($this->certificadaService, $result);
    }

    // ============================================
    // TESTS: getProviderName()
    // ============================================

    /** @test */
    public function test_get_provider_name_returns_athena_when_athena_primary_and_healthy(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspecto();

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('athena', $name);
    }

    /** @test */
    public function test_get_provider_name_returns_certificada_when_athena_unhealthy(): void
    {
        config(['services.email.primary_provider' => 'athena']);

        $prospecto = $this->createProspecto();

        EmailProviderResolver::markAthenaUnhealthy('Test');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('certificada', $name);
    }

    /** @test */
    public function test_get_provider_name_returns_certificada_when_certificada_primary_and_healthy(): void
    {
        config(['services.email.primary_provider' => 'certificada']);

        $prospecto = $this->createProspecto();

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('certificada', $name);
    }

    /** @test */
    public function test_get_provider_name_returns_athena_when_certificada_primary_but_unhealthy(): void
    {
        config(['services.email.primary_provider' => 'certificada']);

        $prospecto = $this->createProspecto();

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

    /** @test */
    public function test_defaults_to_athena_when_config_is_null(): void
    {
        config(['services.email.primary_provider' => null]);

        $prospecto = $this->createProspecto();

        $result = $this->resolver->resolve($prospecto);

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

    private function createGrupoDeudaProspecto(): Prospecto
    {
        $prospecto = new Prospecto;
        $prospecto->id = 1;
        $prospecto->email = 'test@example.com';
        $prospecto->metadata = ['source' => 'grupo_deuda', 'endpoint' => 'contratos'];
        $prospecto->setRelation('importacion', null);

        return $prospecto;
    }
}
