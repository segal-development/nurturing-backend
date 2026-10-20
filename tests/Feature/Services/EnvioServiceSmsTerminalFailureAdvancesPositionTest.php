<?php

namespace Tests\Feature\Services;

use App\Jobs\CatchUpProspectosJob;
use App\Jobs\EnviarEtapaJob;
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
use App\Services\GuardedTransition;
use App\Services\StageOrderResolver;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A failed SMS Envio is terminal (at-most-once). When the send is skipped because of the unique
 * violation, the prospect position must still advance once, otherwise catch-up re-selects it forever.
 *
 * Uses truncation instead of a wrapping transaction: PostgreSQL aborts the whole transaction after a
 * unique violation, which would break the follow-up queries the service runs in production.
 */
class EnvioServiceSmsTerminalFailureAdvancesPositionTest extends TestCase
{
    use DatabaseTruncation;

    private EnvioService $envioService;

    private MockInterface $athenaService;

    private TipoProspecto $tipoProspecto;

    private Flujo $flujo;

    private FlujoEjecucion $ejecucion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athenaService = Mockery::mock(AthenaCampaignService::class);
        $this->envioService = new EnvioService(
            $this->athenaService,
            $this->app->make(DesuscripcionService::class),
            $this->app->make(EmailValidationService::class),
            new EmailProviderResolver(new SmtpEmailService, new CertificadaEmailService),
            $this->app->make(GuardedTransition::class),
        );

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => User::factory()->create()->id,
            'es_perpetuo' => true,
            'config_structure' => [
                'stages' => [
                    ['id' => 'start-1', 'type' => 'start', 'label' => 'Start'],
                    ['id' => 'stage-1', 'type' => 'sms', 'label' => 'Stage 1'],
                    ['id' => 'stage-2', 'type' => 'sms', 'label' => 'Stage 2'],
                    ['id' => 'stage-3', 'type' => 'sms', 'label' => 'Stage 3'],
                    ['id' => 'end-1', 'type' => 'end', 'label' => 'End'],
                ],
                'branches' => [
                    ['source_node_id' => 'start-1', 'target_node_id' => 'stage-1'],
                    ['source_node_id' => 'stage-1', 'target_node_id' => 'stage-2'],
                    ['source_node_id' => 'stage-2', 'target_node_id' => 'stage-3'],
                    ['source_node_id' => 'stage-3', 'target_node_id' => 'end-1'],
                ],
                'initial_node' => 'start-1',
            ],
        ]);
        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'prospectos_ids' => [],
            'nodo_actual' => 'stage-2',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        // DatabaseTruncation only truncates BEFORE each test, so the last test's rows would leak into
        // suites that run afterwards (RefreshDatabase tests count rows). Leave the database clean.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function crearEtapa(string $nodeId): FlujoEjecucionEtapa
    {
        return FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => $nodeId,
            'estado' => 'executing',
        ]);
    }

    private function crearProspectoEnFlujo(?string $ultimaEtapaNodeId): ProspectoEnFlujo
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'telefono' => '+5491100000000',
        ]);

        return ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'sms',
            'ultima_etapa_node_id' => $ultimaEtapaNodeId,
            'fecha_inicio' => now()->subDays(60),
            'completado' => false,
            'cancelado' => false,
        ]);
    }

    private function crearEnvioSms(ProspectoEnFlujo $pef, FlujoEjecucionEtapa $etapa, string $estado): Envio
    {
        return Envio::factory()->create([
            'prospecto_id' => $pef->prospecto_id,
            'prospecto_en_flujo_id' => $pef->id,
            'flujo_id' => $this->flujo->id,
            'flujo_ejecucion_etapa_id' => $etapa->id,
            'canal' => 'sms',
            'estado' => $estado,
            'destinatario' => '+5491100000000',
        ]);
    }

    private function enviarSms(ProspectoEnFlujo $pef, FlujoEjecucionEtapa $etapa): array
    {
        return $this->envioService->enviarSmsAProspecto($pef, 'Hello', $this->flujo->id, $etapa->id);
    }

    private function assertSkipped(array $result): void
    {
        $this->assertSame([
            'success' => true,
            'envio_id' => null,
            'error' => null,
            'skipped' => true,
        ], $result);
    }

    /** @test */
    public function skip_on_terminal_failed_sms_advances_position_from_previous_node(): void
    {
        $etapa = $this->crearEtapa('stage-2');
        $pef = $this->crearProspectoEnFlujo('stage-1');
        $this->crearEnvioSms($pef, $etapa, 'fallido');
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $result = $this->enviarSms($pef, $etapa);

        $this->assertSkipped($result);
        $this->assertSame(1, Envio::count());
        $this->assertSame('stage-2', $pef->fresh()->ultima_etapa_node_id);
    }

    /** @test */
    public function skip_on_terminal_failed_sms_advances_first_stage_from_null(): void
    {
        $etapa = $this->crearEtapa('stage-1');
        $pef = $this->crearProspectoEnFlujo(null);
        $this->crearEnvioSms($pef, $etapa, 'fallido');
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $result = $this->enviarSms($pef, $etapa);

        $this->assertSkipped($result);
        $this->assertSame(1, Envio::count());
        $this->assertSame('stage-1', $pef->fresh()->ultima_etapa_node_id);
    }

    /** @test */
    public function second_skip_does_not_advance_position_again(): void
    {
        $etapa = $this->crearEtapa('stage-2');
        $pef = $this->crearProspectoEnFlujo('stage-1');
        $this->crearEnvioSms($pef, $etapa, 'fallido');
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $this->enviarSms($pef, $etapa);
        $result = $this->enviarSms($pef->fresh(), $etapa);

        $this->assertSkipped($result);
        $this->assertSame(1, Envio::count());
        $this->assertSame('stage-2', $pef->fresh()->ultima_etapa_node_id);
    }

    /** @test */
    public function non_unique_failure_still_fails_marks_fallido_and_does_not_advance(): void
    {
        $etapa = $this->crearEtapa('stage-2');
        $pef = $this->crearProspectoEnFlujo('stage-1');
        $this->athenaService->shouldReceive('enviarMensaje')
            ->once()
            ->andThrow(new \RuntimeException('provider exploded'));

        $result = $this->enviarSms($pef, $etapa);

        $this->assertFalse($result['success']);
        $this->assertSame('provider exploded', $result['error']);
        $this->assertSame('fallido', Envio::find($result['envio_id'])->estado);
        $this->assertSame('stage-1', $pef->fresh()->ultima_etapa_node_id);
    }

    /** @test */
    public function catch_up_no_longer_selects_prospect_after_terminal_failed_sms_skip(): void
    {
        $etapa = $this->crearEtapa('stage-2');
        $pef = $this->crearProspectoEnFlujo('stage-1');
        $this->crearEnvioSms($pef, $etapa, 'fallido');
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $this->enviarSms($pef, $etapa);

        Queue::fake();
        (new CatchUpProspectosJob)->handle($this->app->make(StageOrderResolver::class));

        Queue::assertNotPushed(EnviarEtapaJob::class);
    }
}
