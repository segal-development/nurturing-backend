<?php

namespace Tests\Unit\Services;

use App\Services\EmailValidationService;
use Tests\TestCase;

class EmailValidationServiceTest extends TestCase
{
    private EmailValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmailValidationService;
    }

    /**
     * @test
     * @dataProvider validEmails
     */
    public function it_accepts_valid_emails(string $email): void
    {
        $result = $this->service->validar($email);
        $this->assertTrue($result['valid'], "Expected '$email' to be valid, got: ".json_encode($result));
    }

    public static function validEmails(): array
    {
        return [
            'gmail' => ['user@gmail.com'],
            'hotmail' => ['user@hotmail.com'],
            'yahoo' => ['user@yahoo.com'],
            'outlook' => ['user@outlook.com'],
            'chile tld' => ['user@empresa.cl'],
            'argentina tld' => ['user@empresa.com.ar'],
            'mexico tld' => ['user@empresa.com.mx'],
            'corporate' => ['mtoro6@segal.cl'],
            'uppercase normalized' => ['User@Gmail.COM'],
            'subdomain' => ['user@mail.empresa.com'],
        ];
    }

    /**
     * @test
     * @dataProvider invalidFormatEmails
     */
    public function it_rejects_invalid_format(string $email): void
    {
        $result = $this->service->validar($email);
        $this->assertFalse($result['valid']);
        $this->assertEquals('formato_invalido', $result['motivo']);
    }

    public static function invalidFormatEmails(): array
    {
        return [
            'sin @' => ['useratgmail.com'],
            'sin dominio' => ['user@'],
            'sin usuario' => ['@gmail.com'],
            'string vacío' => [''],
            'solo espacios' => ['   '],
        ];
    }

    /**
     * @test
     * @dataProvider typoDomains
     */
    public function it_detects_known_typos(string $email, string $expectedSuggestion): void
    {
        $result = $this->service->validar($email);
        $this->assertFalse($result['valid']);
        $this->assertStringStartsWith('dominio_typo:', $result['motivo']);
        $this->assertEquals($expectedSuggestion, $result['sugerencia']);
    }

    public static function typoDomains(): array
    {
        return [
            'gmai.com → gmail.com' => ['user@gmai.com', 'user@gmail.com'],
            'gmail.con → gmail.com' => ['user@gmail.con', 'user@gmail.com'],
            'gmeil.com → gmail.com' => ['user@gmeil.com', 'user@gmail.com'],
            'hotmal.com → hotmail.com' => ['user@hotmal.com', 'user@hotmail.com'],
            'yaho.com → yahoo.com' => ['user@yaho.com', 'user@yahoo.com'],
        ];
    }

    /** @test */
    public function it_rejects_invalid_extension_dot_con(): void
    {
        $result = $this->service->validar('user@empresa.con');
        $this->assertFalse($result['valid']);
        $this->assertContains($result['motivo'], ['extension_invalida', 'tld_invalido:con']);
    }

    /**
     * @test
     * @dataProvider corruptedTlds
     */
    public function it_rejects_emails_with_invalid_tld(string $email, string $expectedTld): void
    {
        $result = $this->service->validar($email);
        $this->assertFalse($result['valid'], "Expected '$email' to be invalid");
        $this->assertEquals("tld_invalido:$expectedTld", $result['motivo']);
    }

    public static function corruptedTlds(): array
    {
        return [
            'gmail.comsi0' => ['user@gmail.comsi0', 'comsi0'],
            'gmail.commm' => ['user@gmail.commm', 'commm'],
            'random.xyzqr' => ['user@random.xyzqr', 'xyzqr'],
            'fake.aaa' => ['user@fake.aaa', 'aaa'],
        ];
    }

    /** @test */
    public function it_analyzes_smtp_permanent_errors(): void
    {
        $result = $this->service->analizarErrorEnvio('550 user unknown');
        $this->assertTrue($result['es_invalido']);
        $this->assertStringContainsString('user unknown', $result['motivo']);
    }

    /** @test */
    public function it_does_not_flag_transient_errors_as_invalid(): void
    {
        $result = $this->service->analizarErrorEnvio('Connection timeout');
        $this->assertFalse($result['es_invalido']);
    }
}
