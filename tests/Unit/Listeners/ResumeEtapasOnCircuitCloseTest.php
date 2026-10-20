<?php

namespace Tests\Unit\Listeners;

use App\Events\CircuitBreakerClosed;
use App\Listeners\ResumeEtapasOnCircuitClose;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\AthenaCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ResumeEtapasOnCircuitCloseTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $athenaService;

    private ResumeEtapasOnCircuitClose $listener;

    private FlujoEjecucion $ejecucion;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'envios.alerts.enabled.info' => true,
            'envios.alerts.sms_numbers' => '+5491100000001, +5491100000002',
        ]);

        $this->athenaService = Mockery::mock(AthenaCampaignService::class);
        $this->listener = new ResumeEtapasOnCircuitClose($this->athenaService);

        $flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => TipoProspecto::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);
        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujo->id,
            'prospectos_ids' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();
        parent::tearDown();
    }

    private function crearEtapaPausada(string $channel): FlujoEjecucionEtapa
    {
        $etapa = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'estado' => 'executing',
        ]);
        $etapa->pausarPorCircuitBreaker($channel, 10, 60);

        return $etapa;
    }

    /** @test */
    public function recovery_sms_is_sent_once_per_hour_but_etapas_resume_every_time(): void
    {
        $this->athenaService->shouldReceive('enviarSmsDirecto')->once()->with('+5491100000001', Mockery::type('string'));
        $this->athenaService->shouldReceive('enviarSmsDirecto')->once()->with('+5491100000002', Mockery::type('string'));

        $primera = $this->crearEtapaPausada('sms');
        $this->listener->handle(new CircuitBreakerClosed('sms'));
        $this->assertSame('pending', $primera->fresh()->estado);

        $segunda = $this->crearEtapaPausada('sms');
        $this->listener->handle(new CircuitBreakerClosed('sms'));
        $this->assertSame('pending', $segunda->fresh()->estado);
    }

    /** @test */
    public function recovery_sms_dedup_is_per_channel(): void
    {
        $this->athenaService->shouldReceive('enviarSmsDirecto')
            ->times(4)
            ->with(Mockery::type('string'), Mockery::type('string'));

        $this->crearEtapaPausada('sms');
        $this->listener->handle(new CircuitBreakerClosed('sms'));

        $this->crearEtapaPausada('email');
        $this->listener->handle(new CircuitBreakerClosed('email'));

        $this->assertTrue(Cache::has('cb-recovery-sms:sms'));
        $this->assertTrue(Cache::has('cb-recovery-sms:email'));
    }
}
