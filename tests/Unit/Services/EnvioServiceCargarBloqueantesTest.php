<?php

namespace Tests\Unit\Services;

use App\Models\Envio;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\AthenaCampaignService;
use App\Services\DesuscripcionService;
use App\Services\Email\CertificadaEmailService;
use App\Services\Email\EmailProviderResolver;
use App\Services\Email\SmtpEmailService;
use App\Services\EmailValidationService;
use App\Services\EnvioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for EnvioService::cargarEnviosBloqueantes
 *
 * TDD RED phase — tests written before implementation.
 * Verifies that the helper correctly builds a Set of blocking (prospecto_id, canal) pairs.
 */
class EnvioServiceCargarBloqueantesTest extends TestCase
{
    use RefreshDatabase;

    private EnvioService $envioService;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

    protected function setUp(): void
    {
        parent::setUp();

        $athenaService = Mockery::mock(AthenaCampaignService::class);
        $desuscripcionService = $this->app->make(DesuscripcionService::class);
        $emailValidationService = $this->app->make(EmailValidationService::class);
        $emailProviderResolver = new EmailProviderResolver(
            new SmtpEmailService,
            new CertificadaEmailService,
        );

        $this->envioService = new EnvioService(
            $athenaService,
            $desuscripcionService,
            $emailValidationService,
            $emailProviderResolver,
            $this->app->make(\App\Services\GuardedTransition::class),
        );

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
    // TESTS: Estados bloqueantes incluidos en Set
    // ============================================

    /** @test */
    public function set_incluye_par_enviado(): void
    {
        $etapaEjecucion = $this->createEtapaEjecucion();
        $prospecto = Prospecto::factory()->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);

        Envio::factory()->enviado()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $etapaEjecucion->id,
            'canal' => 'email',
        ]);

        $set = $this->envioService->cargarEnviosBloqueantes($etapaEjecucion->id);

        $this->assertArrayHasKey("{$prospecto->id}:email", $set);
    }

    /** @test */
    public function set_incluye_par_abierto(): void
    {
        $etapaEjecucion = $this->createEtapaEjecucion();
        $prospecto = Prospecto::factory()->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);

        Envio::factory()->abierto()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $etapaEjecucion->id,
            'canal' => 'email',
        ]);

        $set = $this->envioService->cargarEnviosBloqueantes($etapaEjecucion->id);

        $this->assertArrayHasKey("{$prospecto->id}:email", $set);
    }

    /** @test */
    public function set_incluye_par_clickeado(): void
    {
        $etapaEjecucion = $this->createEtapaEjecucion();
        $prospecto = Prospecto::factory()->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);

        Envio::factory()->clickeado()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $etapaEjecucion->id,
            'canal' => 'email',
        ]);

        $set = $this->envioService->cargarEnviosBloqueantes($etapaEjecucion->id);

        $this->assertArrayHasKey("{$prospecto->id}:email", $set);
    }

    /** @test */
    public function set_incluye_par_pendiente(): void
    {
        $etapaEjecucion = $this->createEtapaEjecucion();
        $prospecto = Prospecto::factory()->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);

        // Factory default state is 'pendiente'
        Envio::factory()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $etapaEjecucion->id,
            'canal' => 'email',
            'estado' => 'pendiente',
        ]);

        $set = $this->envioService->cargarEnviosBloqueantes($etapaEjecucion->id);

        $this->assertArrayHasKey("{$prospecto->id}:email", $set);
    }

    /** @test */
    public function set_excluye_par_fallido(): void
    {
        $etapaEjecucion = $this->createEtapaEjecucion();
        $prospecto = Prospecto::factory()->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);

        Envio::factory()->fallido()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $etapaEjecucion->id,
            'canal' => 'email',
        ]);

        $set = $this->envioService->cargarEnviosBloqueantes($etapaEjecucion->id);

        $this->assertArrayNotHasKey("{$prospecto->id}:email", $set);
    }

    // ============================================
    // TESTS: Aislamiento por etapa
    // ============================================

    /** @test */
    public function set_solo_acota_a_etapa(): void
    {
        $etapaEjecucion = $this->createEtapaEjecucion();
        $otraEtapaEjecucion = $this->createEtapaEjecucion();
        $prospecto = Prospecto::factory()->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);

        // Envío en OTRA etapa
        Envio::factory()->enviado()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $otraEtapaEjecucion->id,
            'canal' => 'email',
        ]);

        $set = $this->envioService->cargarEnviosBloqueantes($etapaEjecucion->id);

        $this->assertArrayNotHasKey("{$prospecto->id}:email", $set);
        $this->assertEmpty($set);
    }

    /** @test */
    public function set_vacio_si_sin_envios(): void
    {
        $etapaEjecucion = $this->createEtapaEjecucion();

        $set = $this->envioService->cargarEnviosBloqueantes($etapaEjecucion->id);

        $this->assertIsArray($set);
        $this->assertEmpty($set);
    }

    // ============================================
    // TESTS: Acotamiento por prospectoIds (chunk)
    // ============================================

    /** @test */
    public function set_con_prospectoIds_acota_a_solo_esos_ids(): void
    {
        $etapaEjecucion = $this->createEtapaEjecucion();
        $prospectoA = Prospecto::factory()->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);
        $prospectoB = Prospecto::factory()->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);

        Envio::factory()->enviado()->create([
            'prospecto_id' => $prospectoA->id,
            'flujo_ejecucion_etapa_id' => $etapaEjecucion->id,
            'canal' => 'email',
        ]);

        Envio::factory()->enviado()->create([
            'prospecto_id' => $prospectoB->id,
            'flujo_ejecucion_etapa_id' => $etapaEjecucion->id,
            'canal' => 'email',
        ]);

        // Only ask for prospectoA
        $set = $this->envioService->cargarEnviosBloqueantes($etapaEjecucion->id, [$prospectoA->id]);

        $this->assertArrayHasKey("{$prospectoA->id}:email", $set);
        $this->assertArrayNotHasKey("{$prospectoB->id}:email", $set);
    }

    // ============================================
    // TESTS: Manejo de canales independientes
    // ============================================

    /** @test */
    public function set_maneja_ambos_canales_independiente(): void
    {
        $etapaEjecucion = $this->createEtapaEjecucion();
        $prospecto = Prospecto::factory()->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);

        // email = pendiente (bloqueante)
        Envio::factory()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $etapaEjecucion->id,
            'canal' => 'email',
            'estado' => 'pendiente',
        ]);

        // sms = fallido (NO bloqueante)
        Envio::factory()->fallido()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_ejecucion_etapa_id' => $etapaEjecucion->id,
            'canal' => 'sms',
        ]);

        $set = $this->envioService->cargarEnviosBloqueantes($etapaEjecucion->id);

        $this->assertArrayHasKey("{$prospecto->id}:email", $set);
        $this->assertArrayNotHasKey("{$prospecto->id}:sms", $set);
    }

    // ============================================
    // TESTS: Constante ESTADOS_BLOQUEANTES
    // ============================================

    /** @test */
    public function estados_bloqueantes_constante_incluye_cuatro_estados(): void
    {
        $estados = EnvioService::ESTADOS_BLOQUEANTES;

        $this->assertContains('enviado', $estados);
        $this->assertContains('abierto', $estados);
        $this->assertContains('clickeado', $estados);
        $this->assertContains('pendiente', $estados);
        $this->assertNotContains('fallido', $estados);
        $this->assertCount(4, $estados);
    }

    // ============================================
    // Helper methods
    // ============================================

    /**
     * Crea una FlujoEjecucionEtapa sin prospecto_ids hardcodeados en la ejecución,
     * evitando FK violations en la tabla ejecucion_prospecto.
     */
    private function createEtapaEjecucion(): FlujoEjecucionEtapa
    {
        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospectos_ids' => [],
        ]);

        return FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
        ]);
    }
}
