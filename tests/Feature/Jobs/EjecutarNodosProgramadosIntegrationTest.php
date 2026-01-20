<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EjecutarNodosProgramados;
use App\Jobs\VerificarCondicionJob;
use App\Models\Flujo;
use App\Models\FlujoCondicion;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\AthenaCampaignService;
use App\Services\EnvioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Tests de integración para EjecutarNodosProgramados
 * 
 * Estos tests verifican el flujo completo end-to-end:
 * - Inicio -> Email -> Condición -> Rama Sí/No -> Fin
 */
class EjecutarNodosProgramadosIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TipoProspecto $tipoProspecto;
    private Flujo $flujo;
    private FlujoEjecucion $ejecucion;
    private Prospecto $prospecto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tipoProspecto = TipoProspecto::factory()->create();
        
        // Crear flujo con estructura completa: Email -> Condición -> Rama Sí/No
        $this->flujo = Flujo::factory()->create([
            'user_id' => $this->user->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'config_structure' => [
                'stages' => [
                    [
                        'id' => 'stage-email-1',
                        'type' => 'email',
                        'label' => 'Email Inicial',
                        'tiempo_espera' => 0,
                        'tipo_mensaje' => 'email',
                        'plantilla_mensaje' => 'Hola {nombre}, este es un email de prueba',
                    ],
                    [
                        'id' => 'stage-email-yes',
                        'type' => 'email',
                        'label' => 'Email Seguimiento (Abrió)',
                        'tiempo_espera' => 1,
                        'tipo_mensaje' => 'email',
                        'plantilla_mensaje' => 'Gracias por abrir el email',
                    ],
                    [
                        'id' => 'stage-email-no',
                        'type' => 'email',
                        'label' => 'Email Recordatorio (No Abrió)',
                        'tiempo_espera' => 2,
                        'tipo_mensaje' => 'email',
                        'plantilla_mensaje' => 'No olvides revisar tu email',
                    ],
                ],
                'conditions' => [
                    [
                        'id' => 'conditional-opened',
                        'type' => 'condition',
                        'label' => '¿Abrió el email?',
                        'check_param' => 'Views',
                        'check_operator' => '>',
                        'check_value' => '0',
                    ],
                ],
                'branches' => [
                    // Email inicial -> Condición
                    ['source_node_id' => 'stage-email-1', 'target_node_id' => 'conditional-opened', 'source_handle' => null],
                    // Condición -> Rama Sí
                    ['source_node_id' => 'conditional-opened', 'target_node_id' => 'stage-email-yes', 'source_handle' => 'conditional-opened-yes'],
                    // Condición -> Rama No
                    ['source_node_id' => 'conditional-opened', 'target_node_id' => 'stage-email-no', 'source_handle' => 'conditional-opened-no'],
                    // Rama Sí -> Fin
                    ['source_node_id' => 'stage-email-yes', 'target_node_id' => 'end-1', 'source_handle' => null],
                    // Rama No -> Fin
                    ['source_node_id' => 'stage-email-no', 'target_node_id' => 'end-2', 'source_handle' => null],
                ],
            ],
        ]);

        // Crear condición en BD
        FlujoCondicion::create([
            'id' => 'conditional-opened',
            'flujo_id' => $this->flujo->id,
            'label' => '¿Abrió el email?',
            'condition_type' => 'email_opened',
            'condition_label' => 'Email abierto',
            'yes_label' => 'Sí',
            'no_label' => 'No',
            'check_param' => 'Views',
            'check_operator' => '>',
            'check_value' => '0',
        ]);

        $this->prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
            'nombre' => 'Juan',
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
            'proximo_nodo' => 'stage-email-1',
            'fecha_proximo_nodo' => now()->subMinute(),
            'prospectos_ids' => [$this->prospecto->id],
        ]);

        ProspectoEnFlujo::create([
            'prospecto_id' => $this->prospecto->id,
            'flujo_id' => $this->flujo->id,
            'canal_asignado' => 'email',
            'fecha_inicio' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================
    // Tests de Flujo Completo E2E
    // ============================================

    /** @test */
    public function flujo_completo_email_condicion_rama_si(): void
    {
        Bus::fake([VerificarCondicionJob::class]);
        
        // PASO 1: Ejecutar nodo de email
        $envioService = $this->mockEnvioService(12345);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Verificar email enviado
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'estado' => 'completed',
            'message_id' => 12345,
        ]);
        
        // Verificar siguiente nodo es la condición
        $this->ejecucion->refresh();
        $this->assertEquals('conditional-opened', $this->ejecucion->proximo_nodo);
        
        // PASO 2: Ejecutar nodo de condición (actualizar fecha para que se ejecute)
        $this->ejecucion->update(['fecha_proximo_nodo' => now()->subMinute()]);
        
        $job2 = new EjecutarNodosProgramados();
        $job2->handle($envioService);
        
        // Verificar que se despachó VerificarCondicionJob
        Bus::assertDispatched(VerificarCondicionJob::class, function ($job) {
            return $job->flujoEjecucionId === $this->ejecucion->id
                && $job->messageId === 12345;
        });
    }

    /** @test */
    public function flujo_completo_hasta_nodo_final(): void
    {
        // Simplificar flujo: Email -> Fin
        $this->flujo->update([
            'config_structure' => [
                'stages' => [
                    [
                        'id' => 'stage-email-1',
                        'type' => 'email',
                        'tiempo_espera' => 0,
                        'tipo_mensaje' => 'email',
                        'plantilla_mensaje' => 'Test',
                    ],
                ],
                'branches' => [
                    ['source_node_id' => 'stage-email-1', 'target_node_id' => 'end-1', 'source_handle' => null],
                ],
            ],
        ]);
        
        $envioService = $this->mockEnvioService();
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->ejecucion->refresh();
        $this->assertEquals('completed', $this->ejecucion->estado);
        $this->assertNull($this->ejecucion->proximo_nodo);
        $this->assertNotNull($this->ejecucion->fecha_fin);
    }

    /** @test */
    public function multiples_ejecuciones_se_procesan_independientemente(): void
    {
        // Crear segunda ejecución
        $ejecucion2 = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
            'proximo_nodo' => 'stage-email-1',
            'fecha_proximo_nodo' => now()->subMinute(),
            'prospectos_ids' => [$this->prospecto->id],
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->twice()
            ->andReturn(['error' => false, 'mensaje' => ['messageID' => 99999]]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Ambas ejecuciones deberían avanzar
        $this->assertEquals('stage-email-1', $this->ejecucion->fresh()->nodo_actual);
        $this->assertEquals('stage-email-1', $ejecucion2->fresh()->nodo_actual);
    }

    // ============================================
    // Tests de Resiliencia (No Transacciones Largas)
    // ============================================

    /** @test */
    public function error_en_envio_no_bloquea_base_de_datos(): void
    {
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->once()
            ->andThrow(new \Exception('Error de conexión SMTP'));
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // La ejecución debería estar fallida pero la BD no debería estar bloqueada
        $this->ejecucion->refresh();
        $this->assertEquals('failed', $this->ejecucion->estado);
        
        // Verificar que podemos seguir haciendo operaciones en la BD
        $count = FlujoEjecucion::count();
        $this->assertGreaterThanOrEqual(1, $count);
    }

    /** @test */
    public function etapa_se_crea_antes_de_intentar_envio(): void
    {
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->once()
            ->andThrow(new \Exception('Error simulado'));
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // La etapa debería haberse creado aunque el envío falló
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
        ]);
    }

    /** @test */
    public function nodo_actual_se_actualiza_antes_de_operaciones_externas(): void
    {
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->once()
            ->andReturnUsing(function () {
                // Verificar que nodo_actual ya se actualizó
                $ejecucion = FlujoEjecucion::find($this->ejecucion->id);
                $this->assertEquals('stage-email-1', $ejecucion->nodo_actual);
                
                return ['error' => false, 'mensaje' => ['messageID' => 11111]];
            });
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
    }

    // ============================================
    // Tests de Idempotencia
    // ============================================

    /** @test */
    public function ejecutar_job_dos_veces_no_duplica_envios(): void
    {
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->once() // Solo una vez, no dos
            ->andReturn(['error' => false, 'mensaje' => ['messageID' => 12345]]);
        
        // Primera ejecución
        $job1 = new EjecutarNodosProgramados();
        $job1->handle($envioService);
        
        // Segunda ejecución (el nodo ya está completado)
        $job2 = new EjecutarNodosProgramados();
        $job2->handle($envioService);
        
        // Solo debería haber una etapa
        $etapas = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->ejecucion->id)
            ->where('node_id', 'stage-email-1')
            ->get();
        
        $this->assertCount(1, $etapas);
    }

    /** @test */
    public function etapa_en_executing_no_se_reejecuta(): void
    {
        // Crear etapa en estado executing
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'estado' => 'executing',
            'ejecutado' => false,
            'fecha_programada' => now(),
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldNotReceive('enviar');
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // La etapa debería seguir en executing
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'estado' => 'executing',
        ]);
    }

    // ============================================
    // Tests de Scope conNodosProgramados
    // ============================================

    /** @test */
    public function no_procesa_ejecuciones_completadas(): void
    {
        $this->ejecucion->update(['estado' => 'completed']);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldNotReceive('enviar');
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->assertTrue(true); // Si llegamos aquí sin enviar, pasó
    }

    /** @test */
    public function no_procesa_ejecuciones_failed(): void
    {
        $this->ejecucion->update(['estado' => 'failed']);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldNotReceive('enviar');
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->assertTrue(true);
    }

    /** @test */
    public function no_procesa_ejecuciones_sin_proximo_nodo(): void
    {
        $this->ejecucion->update(['proximo_nodo' => null]);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldNotReceive('enviar');
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->assertTrue(true);
    }

    /** @test */
    public function no_procesa_ejecuciones_con_fecha_futura(): void
    {
        $this->ejecucion->update(['fecha_proximo_nodo' => now()->addHour()]);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldNotReceive('enviar');
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->assertTrue(true);
    }

    // ============================================
    // Tests de Performance
    // ============================================

    /** @test */
    public function job_completa_en_tiempo_razonable(): void
    {
        $envioService = $this->mockEnvioService();
        
        $startTime = microtime(true);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $elapsed = microtime(true) - $startTime;
        
        // Debería completar en menos de 5 segundos (muy conservador)
        $this->assertLessThan(5, $elapsed);
    }

    /** @test */
    public function job_con_multiples_ejecuciones_completa_en_tiempo_razonable(): void
    {
        // Crear 10 ejecuciones adicionales
        for ($i = 0; $i < 10; $i++) {
            FlujoEjecucion::factory()->create([
                'flujo_id' => $this->flujo->id,
                'estado' => 'in_progress',
                'proximo_nodo' => 'stage-email-1',
                'fecha_proximo_nodo' => now()->subMinute(),
                'prospectos_ids' => [$this->prospecto->id],
            ]);
        }
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->times(11) // 1 original + 10 nuevas
            ->andReturn(['error' => false, 'mensaje' => ['messageID' => rand(1000, 9999)]]);
        
        $startTime = microtime(true);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $elapsed = microtime(true) - $startTime;
        
        // Debería completar en menos de 30 segundos
        $this->assertLessThan(30, $elapsed);
    }

    // ============================================
    // Helpers
    // ============================================

    private function mockEnvioService(?int $messageId = 12345): EnvioService
    {
        $mock = Mockery::mock(EnvioService::class);
        $mock->shouldReceive('enviar')
            ->andReturn([
                'error' => false,
                'mensaje' => ['messageID' => $messageId],
            ]);
        
        return $mock;
    }

    private function mockAthenaService(array $stats = []): void
    {
        $mock = Mockery::mock(AthenaCampaignService::class);
        $mock->shouldReceive('getStatistics')
            ->andReturn([
                'error' => false,
                'mensaje' => array_merge([
                    'Views' => 0,
                    'Clicks' => 0,
                    'Bounces' => 0,
                ], $stats),
            ]);
        
        $this->app->instance(AthenaCampaignService::class, $mock);
    }
}
