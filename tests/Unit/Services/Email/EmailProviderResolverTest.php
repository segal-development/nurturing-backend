<?php

namespace Tests\Unit\Services\Email;

use App\Models\Importacion;
use App\Models\Lote;
use App\Models\Prospecto;
use App\Services\Email\CertificadaEmailService;
use App\Services\Email\EmailProviderResolver;
use App\Services\Email\SmtpEmailService;
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
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================
    // TESTS: resolve() - Provider selection
    // ============================================

    /** @test */
    public function test_resolves_to_certificada_for_ic_prospect(): void
    {
        $prospecto = $this->createProspectoWithLote('IC_Lote_2024');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->certificadaService, $result);
    }

    /** @test */
    public function test_resolves_to_smtp_for_non_ic_prospect(): void
    {
        $prospecto = $this->createProspectoWithLote('SYSGAL_Lote_2024');

        // Certificada should not even be checked for non-IC prospects
        $this->certificadaService->shouldNotReceive('isAvailable');

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_resolves_to_smtp_for_prospect_without_lote(): void
    {
        $prospecto = $this->createProspectoWithoutLote();

        $this->certificadaService->shouldNotReceive('isAvailable');

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_resolves_to_smtp_for_prospect_without_importacion(): void
    {
        $prospecto = new Prospecto;
        $prospecto->id = 1;
        // Explicitly set null relation to simulate prospect without importacion
        $prospecto->setRelation('importacion', null);

        $this->certificadaService->shouldNotReceive('isAvailable');

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_falls_back_to_smtp_when_certificada_unavailable(): void
    {
        $prospecto = $this->createProspectoWithLote('IC_Lote_Fallback');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_resolves_to_smtp_for_lote_starting_with_ic_lowercase(): void
    {
        // IC_ detection is case-sensitive - lowercase ic_ should NOT trigger Certificada
        $prospecto = $this->createProspectoWithLote('ic_lowercase_lote');

        $this->certificadaService->shouldNotReceive('isAvailable');

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_resolves_to_smtp_for_lote_containing_ic_but_not_prefix(): void
    {
        // IC must be a prefix, not just contained in the name
        $prospecto = $this->createProspectoWithLote('SYSGAL_IC_Mixed');

        $this->certificadaService->shouldNotReceive('isAvailable');

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    // ============================================
    // TESTS: getProviderName()
    // ============================================

    /** @test */
    public function test_get_provider_name_returns_certificada_for_ic(): void
    {
        $prospecto = $this->createProspectoWithLote('IC_Named_Lote');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('certificada', $name);
    }

    /** @test */
    public function test_get_provider_name_returns_smtp_for_non_ic(): void
    {
        $prospecto = $this->createProspectoWithLote('SYSGAL_Named_Lote');

        $this->certificadaService->shouldNotReceive('isAvailable');

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('smtp', $name);
    }

    /** @test */
    public function test_get_provider_name_returns_smtp_when_certificada_unavailable(): void
    {
        $prospecto = $this->createProspectoWithLote('IC_Unavailable_Lote');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('smtp', $name);
    }

    /** @test */
    public function test_get_provider_name_returns_smtp_for_null_lote(): void
    {
        $prospecto = $this->createProspectoWithoutLote();

        $name = $this->resolver->getProviderName($prospecto);

        $this->assertEquals('smtp', $name);
    }

    // ============================================
    // TESTS: Edge cases
    // ============================================

    /** @test */
    public function test_resolves_correctly_for_empty_lote_name(): void
    {
        $prospecto = $this->createProspectoWithLote('');

        $this->certificadaService->shouldNotReceive('isAvailable');

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->smtpService, $result);
    }

    /** @test */
    public function test_resolves_correctly_for_ic_only_lote_name(): void
    {
        // "IC_" exactly is valid IC prefix
        $prospecto = $this->createProspectoWithLote('IC_');

        $this->certificadaService
            ->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $result = $this->resolver->resolve($prospecto);

        $this->assertSame($this->certificadaService, $result);
    }

    // ============================================
    // Helper methods
    // ============================================

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
        $prospecto->setRelation('importacion', $importacion);

        return $prospecto;
    }

    private function createProspectoWithoutLote(): Prospecto
    {
        $importacion = new Importacion;
        $importacion->id = 1;
        $importacion->setRelation('lote', null);

        $prospecto = new Prospecto;
        $prospecto->id = 1;
        $prospecto->email = 'test@example.com';
        $prospecto->setRelation('importacion', $importacion);

        return $prospecto;
    }
}
