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
        Bus::fake([VerificarCondicionJob::class, \App\Jobs\EnviarEtapaJob::class]);
        
        // PASO 1: Ejecutar nodo de email (despacha EnviarEtapaJob)
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Verificar que se despachó EnviarEtapaJob para el email
        Bus::assertDispatched(\App\Jobs\EnviarEtapaJob::class, function ($job) {
            return $job->flujoEjecucionId === $this->ejecucion->id;
        });
        
        // Verificar etapa en executing (EnviarEtapaJob la marcará completed)
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'estado' => 'executing',
        ]);
        
        // Simular que EnviarEtapaJob completó y programó la condición
        $etapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->ejecucion->id)
            ->where('node_id', 'stage-email-1')
            ->first();
        $etapa->update(['estado' => 'completed', 'message_id' => 12345, 'ejecutado' => true]);
        
        $this->ejecucion->update([
            'proximo_nodo' => 'conditional-opened',
            'fecha_proximo_nodo' => now()->subMinute(),
        ]);
        
        // PASO 2: Ejecutar nodo de condición
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
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
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
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // EnviarEtapaJob debe recibir branches con nodo end
        // El job detectará el nodo end y completará la ejecución en su callback
        Bus::assertDispatched(\App\Jobs\EnviarEtapaJob::class, function ($job) {
            return collect($job->branches)->contains(fn($b) => $b['target_node_id'] === 'end-1');
        });
    }

    /** @test */
    public function multiples_ejecuciones_se_procesan_independientemente(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        // Crear segunda ejecución
        $ejecucion2 = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
            'proximo_nodo' => 'stage-email-1',
            'fecha_proximo_nodo' => now()->subMinute(),
            'prospectos_ids' => [$this->prospecto->id],
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Deberían haberse despachado 2 EnviarEtapaJob (uno por ejecución)
        Bus::assertDispatchedTimes(\App\Jobs\EnviarEtapaJob::class, 2);
        
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
        // Ahora el cron despacha EnviarEtapaJob, no llama a EnvioService directamente
        // El error se manejaría en EnviarEtapaJob, no en el cron
        // Este test ahora verifica que el job se despacha correctamente
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // El job se despachó, la etapa está en executing
        Bus::assertDispatched(\App\Jobs\EnviarEtapaJob::class);
        $this->ejecucion->refresh();
        $this->assertEquals('in_progress', $this->ejecucion->estado);
        
        // Verificar que podemos seguir haciendo operaciones en la BD
        $count = FlujoEjecucion::count();
        $this->assertGreaterThanOrEqual(1, $count);
    }

    /** @test */
    public function etapa_se_crea_antes_de_intentar_envio(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // La etapa debería haberse creado antes de despachar el job
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'estado' => 'executing',
        ]);
    }

    /** @test */
    public function nodo_actual_se_actualiza_antes_de_operaciones_externas(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Verificar que nodo_actual se actualizó
        $this->ejecucion->refresh();
        $this->assertEquals('stage-email-1', $this->ejecucion->nodo_actual);
    }

    // ============================================
    // Tests de Idempotencia
    // ============================================

    /** @test */
    public function ejecutar_job_dos_veces_no_duplica_envios(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        // Primera ejecución
        $job1 = new EjecutarNodosProgramados();
        $job1->handle($envioService);
        
        // La etapa queda en executing, la segunda ejecución no debe despachar otro job
        $job2 = new EjecutarNodosProgramados();
        $job2->handle($envioService);
        
        // Solo debería haber despachado UNA vez (la primera)
        Bus::assertDispatchedTimes(\App\Jobs\EnviarEtapaJob::class, 1);
        
        // Solo debería haber una etapa
        $etapas = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $this->ejecucion->id)
            ->where('node_id', 'stage-email-1')
            ->get();
        
        $this->assertCount(1, $etapas);
    }

    /** @test */
    public function etapa_en_executing_no_se_reejecuta(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        // Crear etapa en estado executing
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'estado' => 'executing',
            'ejecutado' => false,
            'fecha_programada' => now(),
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
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
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        $this->ejecucion->update(['estado' => 'completed']);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        Bus::assertNotDispatched(\App\Jobs\EnviarEtapaJob::class);
    }

    /** @test */
    public function no_procesa_ejecuciones_failed(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        $this->ejecucion->update(['estado' => 'failed']);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        Bus::assertNotDispatched(\App\Jobs\EnviarEtapaJob::class);
    }

    /** @test */
    public function no_procesa_ejecuciones_sin_proximo_nodo(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        $this->ejecucion->update(['proximo_nodo' => null]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        Bus::assertNotDispatched(\App\Jobs\EnviarEtapaJob::class);
    }

    /** @test */
    public function no_procesa_ejecuciones_con_fecha_futura(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        $this->ejecucion->update(['fecha_proximo_nodo' => now()->addHour()]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        Bus::assertNotDispatched(\App\Jobs\EnviarEtapaJob::class);
    }

    // ============================================
    // Tests de Performance
    // ============================================

    /** @test */
    public function job_completa_en_tiempo_razonable(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        $envioService = Mockery::mock(EnvioService::class);
        
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
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
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
        
        $startTime = microtime(true);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $elapsed = microtime(true) - $startTime;
        
        // Debería completar en menos de 30 segundos
        $this->assertLessThan(30, $elapsed);
    }

    // ============================================
    // Tests de Envíos Masivos con Batching
    // ============================================

    /** @test */
    public function cron_despacha_enviar_etapa_job_para_nodo_email(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));
        
        // Debe despachar EnviarEtapaJob en lugar de llamar EnvioService directamente
        Bus::assertDispatched(\App\Jobs\EnviarEtapaJob::class, function ($job) {
            return $job->flujoEjecucionId === $this->ejecucion->id
                && $job->etapaEjecucionId !== null
                && in_array($this->prospecto->id, $job->prospectoIds);
        });
    }

    /** @test */
    public function cron_marca_etapa_como_executing_antes_de_despachar_job(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));
        
        // La etapa debe estar en 'executing' (el job la marcará como completed)
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'estado' => 'executing',
        ]);
    }

    /** @test */
    public function cron_pasa_prospectos_ids_al_enviar_etapa_job(): void
    {
        // Crear múltiples prospectos
        $prospectos = collect([$this->prospecto]);
        for ($i = 0; $i < 5; $i++) {
            $prospectos->push(Prospecto::factory()->create([
                'tipo_prospecto_id' => $this->tipoProspecto->id,
                'email' => "test{$i}@example.com",
            ]));
        }
        
        $prospectoIds = $prospectos->pluck('id')->toArray();
        $this->ejecucion->update(['prospectos_ids' => $prospectoIds]);
        
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));
        
        Bus::assertDispatched(\App\Jobs\EnviarEtapaJob::class, function ($job) use ($prospectoIds) {
            return count($job->prospectoIds) === count($prospectoIds)
                && empty(array_diff($job->prospectoIds, $prospectoIds));
        });
    }

    /** @test */
    public function cron_pasa_branches_al_enviar_etapa_job(): void
    {
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));
        
        Bus::assertDispatched(\App\Jobs\EnviarEtapaJob::class, function ($job) {
            // Debe incluir los branches del flujo para que el job sepa hacia dónde continuar
            return !empty($job->branches);
        });
    }

    /** @test */
    public function cron_usa_prospectos_de_etapa_si_estan_disponibles(): void
    {
        // Crear etapa previa con subset de prospectos (simula filtrado por condición)
        $prospectosFiltrados = [$this->prospecto->id];
        
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'prospectos_ids' => $prospectosFiltrados,
            'fecha_programada' => now()->subMinute(),
            'estado' => 'pending',
        ]);
        
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));
        
        Bus::assertDispatched(\App\Jobs\EnviarEtapaJob::class, function ($job) use ($prospectosFiltrados) {
            return $job->prospectoIds === $prospectosFiltrados;
        });
    }

    /** @test */
    public function cron_no_despacha_job_si_etapa_ya_esta_executing(): void
    {
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'estado' => 'executing',
            'fecha_programada' => now()->subMinute(),
        ]);
        
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));
        
        Bus::assertNotDispatched(\App\Jobs\EnviarEtapaJob::class);
    }

    /** @test */
    public function cron_no_despacha_job_si_etapa_ya_esta_completed(): void
    {
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-email-1',
            'estado' => 'completed',
            'ejecutado' => true,
            'fecha_programada' => now()->subMinute(),
        ]);
        
        Bus::fake([\App\Jobs\EnviarEtapaJob::class]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));
        
        Bus::assertNotDispatched(\App\Jobs\EnviarEtapaJob::class);
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
