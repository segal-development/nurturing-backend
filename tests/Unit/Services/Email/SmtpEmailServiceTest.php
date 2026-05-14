<?php

namespace Tests\Unit\Services\Email;

use App\Models\Prospecto;
use App\Services\Email\SmtpEmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SmtpEmailServiceTest extends TestCase
{
    private SmtpEmailService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.from.address' => 'default@example.com',
            'mail.from.name' => 'Default Sender',
        ]);

        $this->service = new SmtpEmailService;
    }

    // ============================================
    // TESTS: Custom sender parameters (Phase 3)
    // ============================================

    /** @test */
    public function test_send_uses_custom_sender_when_provided(): void
    {
        Mail::fake();

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

        Mail::assertSent(function ($mail) {
            $from = $mail->from;

            return isset($from[0])
                && $from[0]['address'] === 'custom@defensoria.cl'
                && $from[0]['name'] === 'Defensoría Legal';
        });
    }

    /** @test */
    public function test_send_uses_config_default_when_custom_sender_is_null(): void
    {
        Mail::fake();

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

        Mail::assertSent(function ($mail) {
            $from = $mail->from;

            // Should fall back to config values
            return isset($from[0])
                && $from[0]['address'] === 'default@example.com'
                && $from[0]['name'] === 'Default Sender';
        });
    }

    /** @test */
    public function test_send_handles_html_content(): void
    {
        Mail::fake();

        $prospecto = $this->createMockProspecto();
        $htmlContent = '<html><body><h1>Hello</h1></body></html>';

        $result = $this->service->send(
            $prospecto,
            'HTML Email',
            $htmlContent,
            true
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
    }

    /** @test */
    public function test_send_handles_plain_text_content(): void
    {
        Mail::fake();

        $prospecto = $this->createMockProspecto();
        $plainText = 'This is a plain text email.';

        $result = $this->service->send(
            $prospecto,
            'Plain Text Email',
            $plainText,
            false
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
    }

    /** @test */
    public function test_send_returns_error_on_exception(): void
    {
        Mail::shouldReceive('html')
            ->andThrow(new \Exception('SMTP connection failed'));

        $prospecto = $this->createMockProspecto();

        $result = $this->service->send(
            $prospecto,
            'Test Subject',
            '<p>Content</p>',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertStringContainsString('SMTP connection failed', $result['error']);
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
