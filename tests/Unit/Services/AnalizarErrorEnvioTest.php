<?php

namespace Tests\Unit\Services;

use App\Services\EmailValidationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cubre el defecto 2026-06-01: una unique violation de DB (SQLSTATE 23505) se
 * malclasificaba como error SMTP permanente porque '552'/'550' aparecían como
 * substring en el mensaje → marcaba el email inválido en falso y disparaba el
 * circuit breaker. analizarErrorEnvio debe ignorar errores de DB y matchear los
 * códigos SMTP con límite de palabra.
 */
class AnalizarErrorEnvioTest extends TestCase
{
    private EmailValidationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new EmailValidationService;
    }

    #[Test]
    public function unique_violation_de_db_no_se_clasifica_como_error_smtp(): void
    {
        $msg = 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "envios_prospecto_etapa_canal_unique"';
        $r = $this->svc->analizarErrorEnvio($msg);
        $this->assertFalse($r['es_invalido'], 'Una unique violation de DB NO debe marcar el email inválido');
        $this->assertNull($r['motivo']);
    }

    #[Test]
    public function numero_grande_con_552_embebido_no_matchea_codigo_smtp(): void
    {
        // '552' está dentro de '355271' pero NO es un código SMTP 552 standalone.
        $r = $this->svc->analizarErrorEnvio('connection timeout for prospecto id 355271');
        $this->assertFalse($r['es_invalido']);
    }

    #[Test]
    public function codigo_smtp_550_standalone_si_marca_invalido(): void
    {
        // Mensaje sin patrones de texto, solo el código numérico → debe matchear por código.
        $r = $this->svc->analizarErrorEnvio('550 solicitud rechazada por el servidor');
        $this->assertTrue($r['es_invalido']);
        $this->assertSame('smtp_error:550', $r['motivo']);
    }

    #[Test]
    public function patron_de_texto_sigue_funcionando(): void
    {
        $r = $this->svc->analizarErrorEnvio('Recipient address rejected: User unknown');
        $this->assertTrue($r['es_invalido']);
    }

    #[Test]
    public function error_smtp_real_552_mailbox_full_marca_invalido(): void
    {
        $r = $this->svc->analizarErrorEnvio('552 Requested mail action aborted: mailbox full');
        $this->assertTrue($r['es_invalido']);
        $this->assertSame('smtp_error:552', $r['motivo']);
    }
}
