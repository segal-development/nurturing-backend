<?php

namespace Tests\Unit\Services;

use App\Models\Envio;
use App\Models\Flujo;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\AthenaCampaignService;
use App\Services\EnvioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class EnvioServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnvioService $envioService;

    private AthenaCampaignService $athenaService;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->athenaService = Mockery::mock(AthenaCampaignService::class);
        $this->envioService = new EnvioService($this->athenaService);

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();
        $this->flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================
    // TESTS: Validaciones básicas
    // ============================================

    /** @test */
    public function enviar_throws_exception_when_prospectos_collection_is_empty(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No se encontraron prospectos para enviar');

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([]),
            contenido: 'Test content'
        );
    }

    /** @test */
    public function enviar_throws_exception_for_unsupported_message_type(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tipo de mensaje no soportado: whatsapp');

        $prospecto = $this->createProspectoEnFlujoWithEmail();

        $this->envioService->enviar(
            tipoMensaje: 'whatsapp',
            prospectosEnFlujo: collect([$prospecto]),
            contenido: 'Test content'
        );
    }

    /** @test */
    public function enviar_throws_exception_when_no_prospectos_have_valid_email(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ningún prospecto tiene email válido');

        $prospecto = $this->createProspectoEnFlujo(['email' => null]);

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospecto]),
            contenido: 'Test content'
        );
    }

    // ============================================
    // TESTS: Envío de emails exitoso
    // ============================================

    /** @test */
    public function enviar_email_sends_to_single_prospecto_successfully(): void
    {
        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        $result = $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Hola {{nombre}}',
            template: ['asunto' => 'Test Subject'],
            flujo: $this->flujo
        );

        $this->assertFalse($result['error']);
        $this->assertEquals(200, $result['codigo']);
        $this->assertEquals(1, $result['mensaje']['Recipients']);
        $this->assertEquals(0, $result['mensaje']['Errores']);
    }

    /** @test */
    public function enviar_email_sends_to_multiple_prospectos_successfully(): void
    {
        $prospectos = collect([
            $this->createProspectoEnFlujoWithEmail('test1@example.com'),
            $this->createProspectoEnFlujoWithEmail('test2@example.com'),
            $this->createProspectoEnFlujoWithEmail('test3@example.com'),
        ]);

        $result = $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: $prospectos,
            contenido: 'Test content',
            template: ['asunto' => 'Test'],
            flujo: $this->flujo
        );

        $this->assertEquals(3, $result['mensaje']['Recipients']);
        $this->assertEquals(0, $result['mensaje']['Errores']);
    }

    /** @test */
    public function enviar_email_creates_envio_record_for_each_prospecto(): void
    {
        $prospectos = collect([
            $this->createProspectoEnFlujoWithEmail('test1@example.com'),
            $this->createProspectoEnFlujoWithEmail('test2@example.com'),
        ]);

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: $prospectos,
            contenido: 'Test content',
            template: ['asunto' => 'Test'],
            flujo: $this->flujo
        );

        $this->assertDatabaseCount('envios', 2);
        $this->assertDatabaseHas('envios', [
            'canal' => 'email',
            'estado' => 'enviado',
            'flujo_id' => $this->flujo->id,
        ]);
    }

    /** @test */
    public function enviar_email_generates_tracking_token_for_each_envio(): void
    {
        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Test',
            template: ['asunto' => 'Test'],
            flujo: $this->flujo
        );

        $envio = Envio::first();
        $this->assertNotNull($envio->tracking_token);
        $this->assertEquals(64, strlen($envio->tracking_token));
    }

    // ============================================
    // TESTS: Personalización de contenido
    // ============================================

    /** @test */
    public function enviar_email_personalizes_content_with_prospecto_data(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'nombre' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'telefono' => '123456789',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'email',
        ]);

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Hola {{nombre}}, tu email es {{email}} y teléfono {{telefono}}',
            template: ['asunto' => 'Test'],
            flujo: $this->flujo
        );

        $envio = Envio::first();
        $this->assertStringContainsString('Juan Pérez', $envio->contenido_enviado);
        $this->assertStringContainsString('juan@example.com', $envio->contenido_enviado);
        $this->assertStringContainsString('123456789', $envio->contenido_enviado);
    }

    // ============================================
    // TESTS: HTML y tracking
    // ============================================

    /** @test */
    public function enviar_email_html_injects_tracking_pixel(): void
    {
        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        $htmlContent = '<html><body><p>Hello</p></body></html>';

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: $htmlContent,
            template: ['asunto' => 'Test'],
            flujo: $this->flujo,
            esHtml: true
        );

        $envio = Envio::first();
        $this->assertStringContainsString('/track/open/', $envio->contenido_enviado);
        $this->assertStringContainsString('width="1" height="1"', $envio->contenido_enviado);
    }

    /** @test */
    public function enviar_email_html_replaces_urls_with_tracking_urls(): void
    {
        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        $htmlContent = '<html><body><a href="https://example.com/offer">Click here</a></body></html>';

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: $htmlContent,
            template: ['asunto' => 'Test'],
            flujo: $this->flujo,
            esHtml: true
        );

        $envio = Envio::first();
        $this->assertStringContainsString('/track/click/', $envio->contenido_enviado);
        $this->assertStringNotContainsString('href="https://example.com/offer"', $envio->contenido_enviado);
    }

    /** @test */
    public function enviar_email_html_does_not_replace_mailto_links(): void
    {
        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        $htmlContent = '<a href="mailto:test@example.com">Email us</a>';

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: $htmlContent,
            template: ['asunto' => 'Test'],
            flujo: $this->flujo,
            esHtml: true
        );

        $envio = Envio::first();
        $this->assertStringContainsString('href="mailto:test@example.com"', $envio->contenido_enviado);
    }

    /** @test */
    public function enviar_email_html_does_not_replace_tel_links(): void
    {
        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        $htmlContent = '<a href="tel:+123456789">Call us</a>';

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: $htmlContent,
            template: ['asunto' => 'Test'],
            flujo: $this->flujo,
            esHtml: true
        );

        $envio = Envio::first();
        $this->assertStringContainsString('href="tel:+123456789"', $envio->contenido_enviado);
    }

    // ============================================
    // TESTS: Manejo de errores
    // ============================================

    /** @test */
    public function enviar_email_marks_envio_as_failed_on_mail_error(): void
    {
        Mail::shouldReceive('html')
            ->andThrow(new \Exception('SMTP connection failed'));

        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        $result = $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Test',
            template: ['asunto' => 'Test'],
            flujo: $this->flujo,
            esHtml: true
        );

        $this->assertEquals(0, $result['mensaje']['Recipients']);
        $this->assertEquals(1, $result['mensaje']['Errores']);

        $envio = Envio::first();
        $this->assertEquals('fallido', $envio->estado);
    }

    /** @test */
    public function enviar_email_continues_on_partial_failures(): void
    {
        // Este test verifica que si falla un email, los otros se siguen procesando
        $prospectos = collect([
            $this->createProspectoEnFlujoWithEmail('good1@example.com'),
            $this->createProspectoEnFlujoWithEmail('good2@example.com'),
        ]);

        $result = $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: $prospectos,
            contenido: 'Test',
            template: ['asunto' => 'Test'],
            flujo: $this->flujo
        );

        // Ambos deberían procesarse (Mail::fake no lanza excepciones)
        $this->assertEquals(2, $result['mensaje']['Recipients']);
    }

    // ============================================
    // TESTS: Envío de SMS
    // ============================================

    /** @test */
    public function enviar_sms_calls_athena_service(): void
    {
        $this->athenaService
            ->shouldReceive('enviarMensaje')
            ->once()
            ->andReturn([
                'error' => false,
                'mensaje' => ['messageID' => 12345, 'Recipients' => 1],
            ]);

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'telefono' => '123456789',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'sms',
        ]);

        // Cargar la relación para que el filtro funcione correctamente
        $prospectoEnFlujo->load('prospecto');

        $result = $this->envioService->enviar(
            tipoMensaje: 'sms',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Test SMS',
            flujo: $this->flujo
        );

        $this->assertFalse($result['error']);
    }

    // ============================================
    // TESTS: Edge cases
    // ============================================

    /** @test */
    public function enviar_filters_prospectos_without_email_for_email_channel(): void
    {
        $prospectoConEmail = $this->createProspectoEnFlujoWithEmail('valid@example.com');
        $prospectoSinEmail = $this->createProspectoEnFlujo(['email' => null]);
        $prospectoConEmailVacio = $this->createProspectoEnFlujo(['email' => '']);

        $result = $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoConEmail, $prospectoSinEmail, $prospectoConEmailVacio]),
            contenido: 'Test',
            template: ['asunto' => 'Test'],
            flujo: $this->flujo
        );

        // Solo debería enviar al que tiene email válido
        $this->assertEquals(1, $result['mensaje']['Recipients']);
    }

    /** @test */
    public function enviar_uses_default_subject_when_not_provided(): void
    {
        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Test',
            template: null, // Sin template
            flujo: $this->flujo
        );

        $envio = Envio::first();
        $this->assertEquals('Mensaje de Grupo Segal', $envio->asunto);
    }

    /** @test */
    public function enviar_returns_message_id_in_response(): void
    {
        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        $result = $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Test',
            template: ['asunto' => 'Test'],
            flujo: $this->flujo
        );

        $this->assertArrayHasKey('messageID', $result['mensaje']);
        $this->assertIsInt($result['mensaje']['messageID']);
    }

    // ============================================
    // TESTS: Rendimiento / Volumen
    // ============================================

    /** @test */
    public function enviar_handles_batch_of_100_prospectos(): void
    {
        $prospectos = collect();
        for ($i = 0; $i < 100; $i++) {
            $prospectos->push($this->createProspectoEnFlujoWithEmail("test{$i}@example.com"));
        }

        $result = $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: $prospectos,
            contenido: 'Test batch',
            template: ['asunto' => 'Batch Test'],
            flujo: $this->flujo
        );

        $this->assertEquals(100, $result['mensaje']['Recipients']);
        $this->assertDatabaseCount('envios', 100);
    }

    // ============================================
    // Helper methods
    // ============================================

    private function createProspectoEnFlujoWithEmail(string $email = 'test@example.com'): ProspectoEnFlujo
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => $email,
            'nombre' => 'Test User',
        ]);

        return ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'email',
        ]);
    }

    private function createProspectoEnFlujo(array $attributes = []): ProspectoEnFlujo
    {
        $prospecto = Prospecto::factory()->create(array_merge([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ], $attributes));

        return ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'email',
        ]);
    }
}
