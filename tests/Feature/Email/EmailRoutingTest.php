<?php

namespace Tests\Feature\Email;

use App\Models\Flujo;
use App\Models\Importacion;
use App\Models\Lote;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\Email\CertificadaEmailService;
use App\Services\Email\EmailProviderResolver;
use App\Services\Email\SmtpEmailService;
use App\Services\EnvioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailRoutingTest extends TestCase
{
    use RefreshDatabase;

    private EnvioService $envioService;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure Certificada
        config([
            'services.certificada.api_key' => 'test-api-key',
            'services.certificada.base_url' => 'https://test.certificada.cl/api',
            'services.certificada.sender_email' => 'test@certificada.cl',
            'services.certificada.sender_name' => 'Test Sender',
            'services.certificada.enabled' => true,
            'services.certificada.timeout' => 30,
        ]);

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();
        $this->flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Get the EnvioService from the container
        $this->envioService = $this->app->make(EnvioService::class);
    }

    // ============================================
    // TESTS: IC prospects use Certificada
    // ============================================

    /** @test */
    public function test_ic_prospect_email_uses_certificada(): void
    {
        // Fake HTTP for Certificada API
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '1',
                'IdMensaje' => 'CERT-MSG-12345',
                'Mensaje' => 'Email enviado correctamente',
            ], 200),
        ]);

        // Fake SMTP to ensure it's NOT used
        Mail::fake();

        // Create IC lote and prospect
        $prospectoEnFlujo = $this->createICProspectoEnFlujo('ic_test@example.com');

        $result = $this->envioService->enviarEmailAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: '<p>Test IC Email</p>',
            asunto: 'Test IC Subject',
            flujoId: $this->flujo->id,
            esHtml: true
        );

        // Assert success
        $this->assertTrue($result['success']);
        $this->assertNotNull($result['envio_id']);

        // Assert Certificada API was called
        Http::assertSent(function ($request) {
            return $request->url() === 'https://test.certificada.cl/api/transaccional/enviar_html'
                && $request['IdApi'] === 'test-api-key'
                && $request['To']['Email'] === 'ic_test@example.com';
        });

        // Assert SMTP was NOT used
        Mail::assertNothingSent();
    }

    // ============================================
    // TESTS: Non-IC prospects use SMTP
    // ============================================

    /** @test */
    public function test_sysgal_prospect_email_uses_smtp(): void
    {
        // Fake SMTP
        Mail::fake();

        // Create SYSGAL lote and prospect
        $prospectoEnFlujo = $this->createSysgalProspectoEnFlujo('sysgal_test@example.com');

        $result = $this->envioService->enviarEmailAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: '<p>Test Sysgal Email</p>',
            asunto: 'Test Sysgal Subject',
            flujoId: $this->flujo->id,
            esHtml: true
        );

        // Assert success
        $this->assertTrue($result['success']);
        $this->assertNotNull($result['envio_id']);

        // Assert SMTP was used
        Mail::assertSent(function () {
            return true; // Just check that Mail::html was called
        });

        // Assert Certificada was NOT called (no HTTP requests should be made)
        Http::assertNothingSent();
    }

    /** @test */
    public function test_prospect_without_lote_uses_smtp(): void
    {
        Mail::fake();

        // Create prospect without lote (importacion with null lote_id)
        $prospectoEnFlujo = $this->createProspectoWithoutLote('no_lote@example.com');

        $result = $this->envioService->enviarEmailAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: '<p>Test No Lote Email</p>',
            asunto: 'Test No Lote Subject',
            flujoId: $this->flujo->id,
            esHtml: true
        );

        $this->assertTrue($result['success']);

        // SMTP should be used
        Mail::assertSent(function () {
            return true;
        });

        Http::assertNothingSent();
    }

    // ============================================
    // TESTS: Envio stores provider info
    // ============================================

    /** @test */
    public function test_envio_stores_provider_and_message_id_for_certificada(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '1',
                'IdMensaje' => 'CERT-TRACKING-789',
            ], 200),
        ]);

        $prospectoEnFlujo = $this->createICProspectoEnFlujo('ic_stored@example.com');

        $result = $this->envioService->enviarEmailAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: '<p>Test Storage</p>',
            asunto: 'Test Storage Subject',
            flujoId: $this->flujo->id,
            esHtml: true
        );

        $this->assertTrue($result['success']);

        // Verify Envio record has correct provider info
        $this->assertDatabaseHas('envios', [
            'id' => $result['envio_id'],
            'email_provider' => 'certificada',
            'external_message_id' => 'CERT-TRACKING-789',
        ]);
    }

    /** @test */
    public function test_envio_stores_provider_for_smtp(): void
    {
        Mail::fake();

        $prospectoEnFlujo = $this->createSysgalProspectoEnFlujo('sysgal_stored@example.com');

        $result = $this->envioService->enviarEmailAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: '<p>Test SMTP Storage</p>',
            asunto: 'Test SMTP Subject',
            flujoId: $this->flujo->id,
            esHtml: true
        );

        $this->assertTrue($result['success']);

        // Verify Envio record has smtp provider
        $this->assertDatabaseHas('envios', [
            'id' => $result['envio_id'],
            'email_provider' => 'smtp',
            'external_message_id' => null, // SMTP doesn't return message ID
        ]);
    }

    // ============================================
    // TESTS: Fallback behavior
    // ============================================

    /** @test */
    public function test_ic_prospect_falls_back_to_smtp_when_certificada_disabled(): void
    {
        // Disable Certificada
        config(['services.certificada.enabled' => false]);

        // Need to rebind the service after config change
        $this->app->singleton(CertificadaEmailService::class, function () {
            return new CertificadaEmailService;
        });
        $this->app->singleton(SmtpEmailService::class, function () {
            return new SmtpEmailService;
        });
        $this->app->singleton(EmailProviderResolver::class, function ($app) {
            return new EmailProviderResolver(
                $app->make(SmtpEmailService::class),
                $app->make(CertificadaEmailService::class)
            );
        });
        $this->envioService = $this->app->make(EnvioService::class);

        Mail::fake();

        $prospectoEnFlujo = $this->createICProspectoEnFlujo('ic_fallback@example.com');

        $result = $this->envioService->enviarEmailAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: '<p>Test Fallback</p>',
            asunto: 'Test Fallback Subject',
            flujoId: $this->flujo->id,
            esHtml: true
        );

        $this->assertTrue($result['success']);

        // SMTP should be used as fallback
        Mail::assertSent(function () {
            return true;
        });

        // Certificada should NOT be called
        Http::assertNothingSent();

        // Provider should be recorded as smtp
        $this->assertDatabaseHas('envios', [
            'id' => $result['envio_id'],
            'email_provider' => 'smtp',
        ]);
    }

    /** @test */
    public function test_ic_prospect_falls_back_to_smtp_when_certificada_api_key_missing(): void
    {
        // Remove API key
        config(['services.certificada.api_key' => '']);

        // Rebind services
        $this->app->singleton(CertificadaEmailService::class, function () {
            return new CertificadaEmailService;
        });
        $this->app->singleton(SmtpEmailService::class, function () {
            return new SmtpEmailService;
        });
        $this->app->singleton(EmailProviderResolver::class, function ($app) {
            return new EmailProviderResolver(
                $app->make(SmtpEmailService::class),
                $app->make(CertificadaEmailService::class)
            );
        });
        $this->envioService = $this->app->make(EnvioService::class);

        Mail::fake();

        $prospectoEnFlujo = $this->createICProspectoEnFlujo('ic_no_key@example.com');

        $result = $this->envioService->enviarEmailAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: '<p>Test No API Key</p>',
            asunto: 'Test Subject',
            flujoId: $this->flujo->id,
            esHtml: true
        );

        $this->assertTrue($result['success']);

        // SMTP should be used
        Mail::assertSent(function () {
            return true;
        });

        $this->assertDatabaseHas('envios', [
            'id' => $result['envio_id'],
            'email_provider' => 'smtp',
        ]);
    }

    // ============================================
    // TESTS: Error handling
    // ============================================

    /** @test */
    public function test_envio_marked_as_failed_when_certificada_returns_error(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '0',
                'Mensaje' => 'Invalid recipient email',
            ], 200),
        ]);

        $prospectoEnFlujo = $this->createICProspectoEnFlujo('ic_error@example.com');

        $result = $this->envioService->enviarEmailAProspecto(
            prospectoEnFlujo: $prospectoEnFlujo,
            contenido: '<p>Test Error</p>',
            asunto: 'Test Error Subject',
            flujoId: $this->flujo->id,
            esHtml: true
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid recipient email', $result['error']);

        // Envio should be marked as failed
        $this->assertDatabaseHas('envios', [
            'id' => $result['envio_id'],
            'estado' => 'fallido',
            'email_provider' => 'certificada',
        ]);
    }

    // ============================================
    // Helper methods
    // ============================================

    private function createICProspectoEnFlujo(string $email): ProspectoEnFlujo
    {
        $lote = Lote::factory()->create([
            'nombre' => 'IC_Test_Lote_'.uniqid(),
            'user_id' => $this->user->id,
        ]);

        $importacion = Importacion::factory()->create([
            'lote_id' => $lote->id,
            'user_id' => $this->user->id,
        ]);

        $prospecto = Prospecto::factory()->create([
            'importacion_id' => $importacion->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => $email,
            'nombre' => 'IC Test User',
        ]);

        return ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'email',
        ]);
    }

    private function createSysgalProspectoEnFlujo(string $email): ProspectoEnFlujo
    {
        $lote = Lote::factory()->create([
            'nombre' => 'SYSGAL_Test_Lote_'.uniqid(),
            'user_id' => $this->user->id,
        ]);

        $importacion = Importacion::factory()->create([
            'lote_id' => $lote->id,
            'user_id' => $this->user->id,
        ]);

        $prospecto = Prospecto::factory()->create([
            'importacion_id' => $importacion->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => $email,
            'nombre' => 'Sysgal Test User',
        ]);

        return ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'email',
        ]);
    }

    private function createProspectoWithoutLote(string $email): ProspectoEnFlujo
    {
        // Create importacion without lote
        $importacion = Importacion::factory()->create([
            'lote_id' => null,
            'user_id' => $this->user->id,
        ]);

        $prospecto = Prospecto::factory()->create([
            'importacion_id' => $importacion->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => $email,
            'nombre' => 'No Lote User',
        ]);

        return ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'email',
        ]);
    }
}
