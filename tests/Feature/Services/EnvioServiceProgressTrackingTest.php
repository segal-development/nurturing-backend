<?php

namespace Tests\Feature\Services;

use App\Models\Envio;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
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
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Integration tests for progress tracking in EnvioService.
 *
 * Verifies that ultima_etapa_node_id is correctly updated after
 * successful sends and NOT updated after failed sends.
 */
class EnvioServiceProgressTrackingTest extends TestCase
{
    use RefreshDatabase;

    private EnvioService $envioService;

    private AthenaCampaignService $athenaService;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

    private FlujoEjecucion $ejecucion;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->athenaService = Mockery::mock(AthenaCampaignService::class);
        $desuscripcionService = $this->app->make(DesuscripcionService::class);
        $emailValidationService = $this->app->make(EmailValidationService::class);
        $emailProviderResolver = new EmailProviderResolver(
            new SmtpEmailService,
            new CertificadaEmailService,
        );
        $this->envioService = new EnvioService(
            $this->athenaService,
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

        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================
    // TESTS: Successful email updates ultima_etapa_node_id
    // ============================================

    /** @test */
    public function successful_email_updates_ultima_etapa_node_id(): void
    {
        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'executing',
        ]);

        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        // Verify initially NULL
        $this->assertNull($prospectoEnFlujo->ultima_etapa_node_id);

        // Send email
        $result = $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Test content',
            template: ['asunto' => 'Test Subject'],
            flujo: $this->flujo,
            etapaEjecucionId: $etapaEjecucion->id
        );

        $this->assertFalse($result['error']);

        // Verify ultima_etapa_node_id was updated
        $prospectoEnFlujo->refresh();
        $this->assertEquals('stage-1', $prospectoEnFlujo->ultima_etapa_node_id);
    }

    /** @test */
    public function successful_email_updates_to_correct_stage_node_id(): void
    {
        // Create two stages
        $etapa1 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'completed',
        ]);

        $etapa2 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-2',
            'estado' => 'executing',
        ]);

        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();
        $prospectoEnFlujo->update(['ultima_etapa_node_id' => 'stage-1']); // Already completed stage 1

        // Send stage 2 email
        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Stage 2 content',
            template: ['asunto' => 'Stage 2 Subject'],
            flujo: $this->flujo,
            etapaEjecucionId: $etapa2->id
        );

        // Verify ultima_etapa_node_id updated to stage-2
        $prospectoEnFlujo->refresh();
        $this->assertEquals('stage-2', $prospectoEnFlujo->ultima_etapa_node_id);
    }

    // ============================================
    // TESTS: Failed email does NOT update ultima_etapa_node_id
    // ============================================

    /** @test */
    public function failed_email_does_not_update_ultima_etapa_node_id(): void
    {
        // Make Mail::html throw an exception
        Mail::shouldReceive('html')
            ->andThrow(new \Exception('SMTP connection failed'));

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'executing',
        ]);

        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        // Verify initially NULL
        $this->assertNull($prospectoEnFlujo->ultima_etapa_node_id);

        // Send email (will fail)
        $result = $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: '<html><body>Test</body></html>',
            template: ['asunto' => 'Test Subject'],
            flujo: $this->flujo,
            etapaEjecucionId: $etapaEjecucion->id,
            esHtml: true
        );

        // Should have errors
        $this->assertEquals(1, $result['mensaje']['Errores']);

        // Verify ultima_etapa_node_id was NOT updated
        $prospectoEnFlujo->refresh();
        $this->assertNull($prospectoEnFlujo->ultima_etapa_node_id);

        // Verify envio was marked as failed
        $envio = Envio::where('prospecto_id', $prospectoEnFlujo->prospecto_id)->first();
        $this->assertEquals('fallido', $envio->estado);
    }

    // ============================================
    // TESTS: SMS progress tracking
    // ============================================

    /** @test */
    public function successful_sms_updates_ultima_etapa_node_id(): void
    {
        $this->athenaService
            ->shouldReceive('enviarMensaje')
            ->once()
            ->andReturn([
                'error' => false,
                'mensaje' => ['messageID' => 12345, 'Recipients' => 1],
            ]);

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'sms-stage-1',
            'estado' => 'executing',
        ]);

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'telefono' => '+56912345678',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porSms()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => null,
        ]);

        // Load prospecto relationship for the filter to work
        $prospectoEnFlujo->load('prospecto');

        $result = $this->envioService->enviar(
            tipoMensaje: 'sms',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Test SMS content',
            flujo: $this->flujo,
            etapaEjecucionId: $etapaEjecucion->id
        );

        $this->assertFalse($result['error']);

        // Verify ultima_etapa_node_id was updated
        $prospectoEnFlujo->refresh();
        $this->assertEquals('sms-stage-1', $prospectoEnFlujo->ultima_etapa_node_id);
    }

    // ============================================
    // TESTS: Edge cases
    // ============================================

    /** @test */
    public function progress_not_updated_when_etapa_ejecucion_id_is_null(): void
    {
        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        // Send without etapaEjecucionId
        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Test content',
            template: ['asunto' => 'Test Subject'],
            flujo: $this->flujo,
            etapaEjecucionId: null // No etapa ID
        );

        // Verify ultima_etapa_node_id was NOT updated (no etapa to reference)
        $prospectoEnFlujo->refresh();
        $this->assertNull($prospectoEnFlujo->ultima_etapa_node_id);
    }

    // Note: Test for "etapa has no node_id" removed because the database
    // has a NOT NULL constraint on node_id column, making this scenario impossible.

    /** @test */
    public function updates_correct_prospecto_en_flujo_by_flujo_id(): void
    {
        // Create two different flows
        $flujo1 = $this->flujo;
        $flujo2 = Flujo::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $ejecucion2 = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujo2->id,
            'es_perpetuo' => true,
        ]);

        $etapa1 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'executing',
        ]);

        // Create same prospect in both flows
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
        ]);

        $pefFlujo1 = ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $flujo1->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => null,
        ]);

        $pefFlujo2 = ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $flujo2->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => null,
        ]);

        // Send email for flujo1 only
        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$pefFlujo1]),
            contenido: 'Test content',
            template: ['asunto' => 'Test Subject'],
            flujo: $flujo1,
            etapaEjecucionId: $etapa1->id
        );

        // Verify only flujo1's record was updated
        $pefFlujo1->refresh();
        $pefFlujo2->refresh();

        $this->assertEquals('stage-1', $pefFlujo1->ultima_etapa_node_id);
        $this->assertNull($pefFlujo2->ultima_etapa_node_id);
    }

    /** @test */
    public function multiple_sends_update_to_latest_stage(): void
    {
        $etapa1 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'completed',
        ]);

        $etapa2 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-2',
            'estado' => 'completed',
        ]);

        $etapa3 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-3',
            'estado' => 'executing',
        ]);

        $prospectoEnFlujo = $this->createProspectoEnFlujoWithEmail();

        // Send stage 1
        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Stage 1',
            template: ['asunto' => 'Stage 1'],
            flujo: $this->flujo,
            etapaEjecucionId: $etapa1->id
        );

        $prospectoEnFlujo->refresh();
        $this->assertEquals('stage-1', $prospectoEnFlujo->ultima_etapa_node_id);

        // Send stage 2
        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Stage 2',
            template: ['asunto' => 'Stage 2'],
            flujo: $this->flujo,
            etapaEjecucionId: $etapa2->id
        );

        $prospectoEnFlujo->refresh();
        $this->assertEquals('stage-2', $prospectoEnFlujo->ultima_etapa_node_id);

        // Send stage 3
        $this->envioService->enviar(
            tipoMensaje: 'email',
            prospectosEnFlujo: collect([$prospectoEnFlujo]),
            contenido: 'Stage 3',
            template: ['asunto' => 'Stage 3'],
            flujo: $this->flujo,
            etapaEjecucionId: $etapa3->id
        );

        $prospectoEnFlujo->refresh();
        $this->assertEquals('stage-3', $prospectoEnFlujo->ultima_etapa_node_id);
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    private function createProspectoEnFlujoWithEmail(string $email = 'test@example.com'): ProspectoEnFlujo
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => $email,
            'nombre' => 'Test User',
        ]);

        return ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => null,
        ]);
    }
}
