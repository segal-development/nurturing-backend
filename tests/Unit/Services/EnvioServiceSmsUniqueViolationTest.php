<?php

namespace Tests\Unit\Services;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * SMS path of EnvioService: a failed Envio is terminal for (prospecto, etapa, canal),
 * so the DB unique violation is an idempotent skip (SMS is at-most-once).
 */
class EnvioServiceSmsUniqueViolationTest extends TestCase
{
    use RefreshDatabase;

    private EnvioService $envioService;

    private MockInterface $athenaService;

    private Flujo $flujo;

    private FlujoEjecucionEtapa $etapa;

    private ProspectoEnFlujo $prospectoEnFlujo;

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

        $tipoProspecto = TipoProspecto::factory()->create();
        $this->flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => User::factory()->create()->id,
        ]);
        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'prospectos_ids' => [],
        ]);
        $this->etapa = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'executing',
        ]);

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'telefono' => '+5491100000000',
        ]);
        $this->prospectoEnFlujo = ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'sms',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function crearEnvioSms(string $estado): Envio
    {
        return Envio::factory()->create([
            'prospecto_id' => $this->prospectoEnFlujo->prospecto_id,
            'prospecto_en_flujo_id' => $this->prospectoEnFlujo->id,
            'flujo_id' => $this->flujo->id,
            'flujo_ejecucion_etapa_id' => $this->etapa->id,
            'canal' => 'sms',
            'estado' => $estado,
            'destinatario' => '+5491100000000',
        ]);
    }

    private function enviarSms(): array
    {
        return $this->envioService->enviarSmsAProspecto(
            $this->prospectoEnFlujo,
            'Hello',
            $this->flujo->id,
            $this->etapa->id
        );
    }

    /** @test */
    public function sms_with_existing_failed_envio_is_an_idempotent_skip(): void
    {
        $this->crearEnvioSms('fallido');
        $this->athenaService->shouldNotReceive('enviarMensaje');
        Log::spy();

        // RefreshDatabase wraps the test in a transaction and PostgreSQL aborts it after a unique
        // violation. A savepoint lets us roll back to a usable state to assert on the rows.
        DB::beginTransaction();
        $result = $this->enviarSms();
        DB::rollBack();

        $this->assertSame([
            'success' => true,
            'envio_id' => null,
            'error' => null,
            'skipped' => true,
        ], $result);
        $this->assertSame(1, Envio::count());
        $this->assertSame('fallido', Envio::first()->estado);
        Log::shouldHaveReceived('info')->once();
        Log::shouldNotHaveReceived('error');
    }

    /** @test */
    public function sms_non_unique_exception_still_fails_and_marks_envio_fallido(): void
    {
        $this->athenaService->shouldReceive('enviarMensaje')
            ->once()
            ->andThrow(new \RuntimeException('provider exploded'));

        $result = $this->enviarSms();

        $this->assertFalse($result['success']);
        $this->assertSame('provider exploded', $result['error']);
        $this->assertNotNull($result['envio_id']);
        $this->assertSame('fallido', Envio::find($result['envio_id'])->estado);
        $this->assertSame(1, Envio::count());
    }
}
