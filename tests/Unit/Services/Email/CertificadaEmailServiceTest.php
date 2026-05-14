<?php

namespace Tests\Unit\Services\Email;

use App\Models\Prospecto;
use App\Services\Email\CertificadaEmailService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CertificadaEmailServiceTest extends TestCase
{
    private CertificadaEmailService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Set default config for tests
        config([
            'services.certificada.api_key' => 'test-api-key',
            'services.certificada.base_url' => 'https://test.certificada.cl/api',
            'services.certificada.sender_email' => 'test@example.com',
            'services.certificada.sender_name' => 'Test Sender',
            'services.certificada.enabled' => true,
            'services.certificada.timeout' => 30,
        ]);

        // Create a fresh instance after config is set
        $this->service = new CertificadaEmailService;
    }

    // ============================================
    // TESTS: Successful email sending
    // ============================================

    /** @test */
    public function test_send_email_successfully(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '1',
                'IdMensaje' => 'MSG-123456',
                'Mensaje' => 'Email enviado correctamente',
            ], 200),
        ]);

        $prospecto = $this->createMockProspecto();

        $result = $this->service->send(
            $prospecto,
            'Test Subject',
            '<p>Test Content</p>',
            true
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('MSG-123456', $result['message_id']);
        $this->assertNull($result['error']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://test.certificada.cl/api/transaccional/enviar_html'
                && $request['IdApi'] === 'test-api-key'
                && $request['From']['Email'] === 'test@example.com';
        });
    }

    // ============================================
    // TESTS: Error handling
    // ============================================

    /** @test */
    public function test_send_email_api_error(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '0',
                'Mensaje' => 'Invalid API key',
            ], 200),
        ]);

        $prospecto = $this->createMockProspecto();

        $result = $this->service->send(
            $prospecto,
            'Test Subject',
            '<p>Test Content</p>',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertEquals('Invalid API key', $result['error']);
    }

    /** @test */
    public function test_send_email_timeout(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $prospecto = $this->createMockProspecto();

        $result = $this->service->send(
            $prospecto,
            'Test Subject',
            '<p>Test Content</p>',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertEquals('Connection timeout to Certificada API', $result['error']);
    }

    /** @test */
    public function test_send_email_disabled(): void
    {
        // Recreate service with disabled config
        config(['services.certificada.enabled' => false]);
        $service = new CertificadaEmailService;

        $prospecto = $this->createMockProspecto();

        $result = $service->send(
            $prospecto,
            'Test Subject',
            '<p>Test Content</p>',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertEquals('Certificada service is disabled', $result['error']);

        // Verify no HTTP request was made
        Http::assertNothingSent();
    }

    /** @test */
    public function test_send_email_no_api_key(): void
    {
        // Recreate service with empty API key
        config(['services.certificada.api_key' => '']);
        $service = new CertificadaEmailService;

        $prospecto = $this->createMockProspecto();

        $result = $service->send(
            $prospecto,
            'Test Subject',
            '<p>Test Content</p>',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertEquals('Certificada API key not configured', $result['error']);

        // Verify no HTTP request was made
        Http::assertNothingSent();
    }

    // ============================================
    // TESTS: Payload building
    // ============================================

    /** @test */
    public function test_build_payload_encodes_html_base64(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '1',
                'IdMensaje' => 'MSG-123',
            ], 200),
        ]);

        $prospecto = $this->createMockProspecto();
        $htmlContent = '<html><body><p>Test Content with special chars: &aacute;&eacute;</p></body></html>';

        $this->service->send(
            $prospecto,
            'Test Subject',
            $htmlContent,
            true
        );

        Http::assertSent(function ($request) use ($htmlContent) {
            $payload = $request->data();

            // Verify HTML is base64 encoded
            $decodedHtml = base64_decode($payload['Despacho']['Html']);
            $this->assertEquals($htmlContent, $decodedHtml);

            // Verify plain text is strip_tags of HTML
            $this->assertEquals(strip_tags($htmlContent), $payload['Despacho']['Texto']);

            return true;
        });
    }

    /** @test */
    public function test_build_payload_handles_plain_text(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '1',
                'IdMensaje' => 'MSG-123',
            ], 200),
        ]);

        $prospecto = $this->createMockProspecto();
        $plainText = "Line 1\nLine 2\nLine 3";

        $this->service->send(
            $prospecto,
            'Test Subject',
            $plainText,
            false // Not HTML
        );

        Http::assertSent(function ($request) use ($plainText) {
            $payload = $request->data();

            // For plain text, HTML should be nl2br(e($plainText)) encoded in base64
            $decodedHtml = base64_decode($payload['Despacho']['Html']);
            $this->assertStringContainsString('<br />', $decodedHtml);

            // Plain text stays as-is
            $this->assertEquals($plainText, $payload['Despacho']['Texto']);

            return true;
        });
    }

    // ============================================
    // TESTS: isAvailable()
    // ============================================

    /** @test */
    public function test_is_available_returns_true_when_configured(): void
    {
        $this->assertTrue($this->service->isAvailable());
    }

    /** @test */
    public function test_is_available_returns_false_when_disabled(): void
    {
        config(['services.certificada.enabled' => false]);
        $service = new CertificadaEmailService;

        $this->assertFalse($service->isAvailable());
    }

    /** @test */
    public function test_is_available_returns_false_when_no_api_key(): void
    {
        config(['services.certificada.api_key' => '']);
        $service = new CertificadaEmailService;

        $this->assertFalse($service->isAvailable());
    }

    /** @test */
    public function test_is_available_returns_false_when_null_api_key(): void
    {
        config(['services.certificada.api_key' => null]);
        $service = new CertificadaEmailService;

        $this->assertFalse($service->isAvailable());
    }

    // ============================================
    // TESTS: Recipient data
    // ============================================

    /** @test */
    public function test_send_uses_prospecto_name_when_available(): void
    {
        Http::fake([
            '*' => Http::response(['Estado' => '1', 'IdMensaje' => 'MSG-1'], 200),
        ]);

        $prospecto = $this->createMockProspecto('test@example.com', 'Juan Perez');

        $this->service->send($prospecto, 'Subject', 'Content', false);

        Http::assertSent(function ($request) {
            return $request['To']['Nombre'] === 'Juan Perez';
        });
    }

    /** @test */
    public function test_send_uses_email_as_name_when_name_is_null(): void
    {
        Http::fake([
            '*' => Http::response(['Estado' => '1', 'IdMensaje' => 'MSG-1'], 200),
        ]);

        $prospecto = $this->createMockProspecto('fallback@example.com', null);

        $this->service->send($prospecto, 'Subject', 'Content', false);

        Http::assertSent(function ($request) {
            return $request['To']['Nombre'] === 'fallback@example.com';
        });
    }

    // ============================================
    // TESTS: Custom sender parameters (Phase 3)
    // ============================================

    /** @test */
    public function test_send_uses_custom_sender_when_provided(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '1',
                'IdMensaje' => 'MSG-CUSTOM',
            ], 200),
        ]);

        $prospecto = $this->createMockProspecto();

        $result = $this->service->send(
            $prospecto,
            'Test Subject',
            '<p>Test Content</p>',
            true,
            'custom@defensoria.cl',
            'Defensoría Legal'
        );

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return $request['From']['Email'] === 'custom@defensoria.cl'
                && $request['From']['Nombre'] === 'Defensoría Legal';
        });
    }

    /** @test */
    public function test_send_uses_config_default_when_custom_sender_is_null(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '1',
                'IdMensaje' => 'MSG-DEFAULT',
            ], 200),
        ]);

        $prospecto = $this->createMockProspecto();

        $result = $this->service->send(
            $prospecto,
            'Test Subject',
            '<p>Test Content</p>',
            true,
            null, // No custom sender email
            null  // No custom sender name
        );

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            // Should fall back to config values set in setUp()
            return $request['From']['Email'] === 'test@example.com'
                && $request['From']['Nombre'] === 'Test Sender';
        });
    }

    /** @test */
    public function test_send_uses_partial_custom_sender_with_default_name(): void
    {
        Http::fake([
            'https://test.certificada.cl/api/transaccional/enviar_html' => Http::response([
                'Estado' => '1',
                'IdMensaje' => 'MSG-PARTIAL',
            ], 200),
        ]);

        $prospecto = $this->createMockProspecto();

        // Only custom email, name should fall back to config
        $result = $this->service->send(
            $prospecto,
            'Test Subject',
            '<p>Test Content</p>',
            true,
            'custom-email-only@test.cl',
            null // Use default name
        );

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return $request['From']['Email'] === 'custom-email-only@test.cl'
                && $request['From']['Nombre'] === 'Test Sender'; // Falls back to config
        });
    }

    // ============================================
    // Helper methods
    // ============================================

    private function createMockProspecto(string $email = 'prospecto@example.com', ?string $nombre = 'Test Prospecto'): Prospecto
    {
        $prospecto = new Prospecto;
        $prospecto->id = 1;
        $prospecto->email = $email;
        $prospecto->nombre = $nombre;

        return $prospecto;
    }
}
