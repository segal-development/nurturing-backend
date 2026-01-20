<?php

namespace Tests\Unit\Jobs;

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
use App\Services\EnvioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Tests para EjecutarNodosProgramados
 * 
 * Este job es CRÍTICO para el sistema - ejecuta nodos programados de flujos.
 * Los tests verifican:
 * - Configuración de timeouts (previene locks de BD)
 * - Operaciones atómicas (sin transacciones largas)
 * - Manejo de estados
 * - Edge cases
 */
class EjecutarNodosProgramadosTest extends TestCase
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
        
        $this->flujo = Flujo::factory()->create([
            'user_id' => $this->user->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'config_structure' => [
                'stages' => [
                    [
                        'id' => 'stage-1',
                        'type' => 'email',
                        'label' => 'Email Inicial',
                        'tiempo_espera' => 0,
                        'tipo_mensaje' => 'email',
                        'plantilla_mensaje' => 'Hola {nombre}',
                    ],
                    [
                        'id' => 'stage-2',
                        'type' => 'email',
                        'label' => 'Email Seguimiento',
                        'tiempo_espera' => 1,
                        'tipo_mensaje' => 'email',
                    ],
                ],
                'conditions' => [
                    [
                        'id' => 'conditional-1',
                        'type' => 'condition',
                        'check_param' => 'Views',
                        'check_operator' => '>',
                        'check_value' => '0',
                    ],
                ],
                'branches' => [
                    ['source_node_id' => 'stage-1', 'target_node_id' => 'conditional-1', 'source_handle' => null],
                    ['source_node_id' => 'conditional-1', 'target_node_id' => 'stage-2', 'source_handle' => 'yes'],
                ],
                'edges' => [],
            ],
        ]);

        $this->prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
            'proximo_nodo' => 'stage-1',
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
    // Tests de Configuración del Job (CRÍTICOS)
    // ============================================

    /** @test */
    public function job_tiene_timeout_configurado(): void
    {
        $job = new EjecutarNodosProgramados();
        
        $this->assertEquals(60, $job->timeout, 'Timeout debe ser 60s para prevenir locks');
    }

    /** @test */
    public function job_tiene_tries_configurado_en_uno(): void
    {
        $job = new EjecutarNodosProgramados();
        
        $this->assertEquals(1, $job->tries, 'Tries debe ser 1 para evitar reintentos que causen loops');
    }

    /** @test */
    public function job_tiene_fail_on_timeout_habilitado(): void
    {
        $job = new EjecutarNodosProgramados();
        
        $this->assertTrue($job->failOnTimeout, 'failOnTimeout debe estar habilitado');
    }

    // ============================================
    // Tests de Ejecución Normal
    // ============================================

    /** @test */
    public function job_no_hace_nada_sin_ejecuciones_pendientes(): void
    {
        // Marcar la ejecución como completada
        $this->ejecucion->update(['estado' => 'completed', 'proximo_nodo' => null]);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldNotReceive('enviar');
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // No debería haber cambios
        $this->assertEquals('completed', $this->ejecucion->fresh()->estado);
    }

    /** @test */
    public function job_encuentra_ejecuciones_con_nodos_programados(): void
    {
        $envioService = $this->mockEnvioService();
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Debería haber actualizado el nodo_actual
        $this->assertEquals('stage-1', $this->ejecucion->fresh()->nodo_actual);
    }

    /** @test */
    public function job_crea_etapa_ejecucion_si_no_existe(): void
    {
        $envioService = $this->mockEnvioService();
        
        // Verificar que no existe la etapa
        $this->assertDatabaseMissing('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
        ]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Ahora debería existir
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
        ]);
    }

    /** @test */
    public function job_no_reejecutada_etapa_ya_completada(): void
    {
        // Crear etapa ya completada
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'completed',
            'ejecutado' => true,
            'fecha_programada' => now(),
            'fecha_ejecucion' => now(),
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldNotReceive('enviar');
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // No debería haber intentado enviar
        $this->assertTrue(true); // Si llegamos aquí sin excepción, pasó
    }

    /** @test */
    public function job_no_reejecutada_etapa_en_executing(): void
    {
        // Crear etapa en proceso
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'executing',
            'ejecutado' => false,
            'fecha_programada' => now(),
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldNotReceive('enviar');
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->assertTrue(true);
    }

    // ============================================
    // Tests de Nodos de Envío
    // ============================================

    /** @test */
    public function job_envia_email_para_nodo_tipo_email(): void
    {
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->once()
            ->with(
                Mockery::on(fn($tipo) => $tipo === 'email'),
                Mockery::any(),
                Mockery::any(),
                Mockery::any(),
                Mockery::any(),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn([
                'error' => false,
                'mensaje' => ['messageID' => 12345],
            ]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Verificar que la etapa se marcó como completada
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'completed',
            'ejecutado' => true,
            'message_id' => 12345,
        ]);
    }

    /** @test */
    public function job_actualiza_etapa_con_message_id(): void
    {
        $envioService = $this->mockEnvioService(54321);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'message_id' => 54321,
        ]);
    }

    // ============================================
    // Tests de Nodos Condicionales
    // ============================================

    /** @test */
    public function job_despacha_verificar_condicion_job_para_nodo_condition(): void
    {
        Bus::fake([VerificarCondicionJob::class]);
        
        // Crear etapa de email completada primero
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'completed',
            'ejecutado' => true,
            'message_id' => 12345,
            'fecha_programada' => now()->subHour(),
            'fecha_ejecucion' => now()->subHour(),
        ]);
        
        // Actualizar ejecución para que el próximo nodo sea la condición
        $this->ejecucion->update([
            'proximo_nodo' => 'conditional-1',
            'fecha_proximo_nodo' => now()->subMinute(),
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        Bus::assertDispatched(VerificarCondicionJob::class, function ($job) {
            return $job->flujoEjecucionId === $this->ejecucion->id
                && $job->messageId === 12345;
        });
    }

    /** @test */
    public function job_marca_condicion_como_failed_si_no_hay_message_id(): void
    {
        // Crear etapa sin message_id
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'completed',
            'ejecutado' => true,
            'message_id' => null, // Sin message_id
            'fecha_programada' => now()->subHour(),
            'fecha_ejecucion' => now()->subHour(),
        ]);
        
        $this->ejecucion->update([
            'proximo_nodo' => 'conditional-1',
            'fecha_proximo_nodo' => now()->subMinute(),
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Debería crear la etapa de condición como failed
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'conditional-1',
            'estado' => 'failed',
        ]);
    }

    // ============================================
    // Tests de Nodos Finales
    // ============================================

    /** @test */
    public function job_completa_ejecucion_cuando_alcanza_nodo_end(): void
    {
        // Modificar flujo para tener nodo end
        $this->flujo->update([
            'config_structure' => [
                'stages' => [
                    ['id' => 'stage-1', 'type' => 'email', 'tiempo_espera' => 0, 'tipo_mensaje' => 'email'],
                ],
                'branches' => [
                    ['source_node_id' => 'stage-1', 'target_node_id' => 'end-1', 'source_handle' => null],
                ],
            ],
        ]);
        
        $envioService = $this->mockEnvioService();
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->ejecucion->refresh();
        $this->assertEquals('completed', $this->ejecucion->estado);
        $this->assertNull($this->ejecucion->proximo_nodo);
    }

    // ============================================
    // Tests de Programación de Siguiente Nodo
    // ============================================

    /** @test */
    public function job_programa_siguiente_nodo_despues_de_envio(): void
    {
        $envioService = $this->mockEnvioService();
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->ejecucion->refresh();
        $this->assertEquals('conditional-1', $this->ejecucion->proximo_nodo);
    }

    /** @test */
    public function job_completa_ejecucion_si_no_hay_siguiente_nodo(): void
    {
        // Flujo sin conexiones después del stage
        $this->flujo->update([
            'config_structure' => [
                'stages' => [
                    ['id' => 'stage-1', 'type' => 'email', 'tiempo_espera' => 0, 'tipo_mensaje' => 'email'],
                ],
                'branches' => [], // Sin conexiones
            ],
        ]);
        
        $envioService = $this->mockEnvioService();
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->ejecucion->refresh();
        $this->assertEquals('completed', $this->ejecucion->estado);
    }

    // ============================================
    // Tests de Edge Cases
    // ============================================

    /** @test */
    public function job_maneja_nodo_no_encontrado(): void
    {
        $this->ejecucion->update(['proximo_nodo' => 'nodo-inexistente']);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Debería marcar la ejecución como fallida
        $this->ejecucion->refresh();
        $this->assertEquals('failed', $this->ejecucion->estado);
        $this->assertStringContainsString('No se encontró el nodo', $this->ejecucion->error_message);
    }

    /** @test */
    public function job_maneja_flujo_data_null(): void
    {
        $this->flujo->update(['config_structure' => null]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->ejecucion->refresh();
        $this->assertEquals('failed', $this->ejecucion->estado);
    }

    /** @test */
    public function job_maneja_stages_array_vacio(): void
    {
        $this->flujo->update([
            'config_structure' => [
                'stages' => [],
                'branches' => [],
            ],
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        $this->ejecucion->refresh();
        $this->assertEquals('failed', $this->ejecucion->estado);
    }

    /** @test */
    public function job_normaliza_edges_a_branches(): void
    {
        // Flujo con edges en lugar de branches
        $this->flujo->update([
            'config_structure' => [
                'stages' => [
                    ['id' => 'stage-1', 'type' => 'email', 'tiempo_espera' => 0, 'tipo_mensaje' => 'email'],
                    ['id' => 'stage-2', 'type' => 'email', 'tiempo_espera' => 0, 'tipo_mensaje' => 'email'],
                ],
                'branches' => [], // Vacío
                'edges' => [
                    ['source' => 'stage-1', 'target' => 'stage-2', 'sourceHandle' => null],
                ],
            ],
        ]);
        
        $envioService = $this->mockEnvioService();
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Debería haber programado stage-2 como siguiente
        $this->ejecucion->refresh();
        $this->assertEquals('stage-2', $this->ejecucion->proximo_nodo);
    }

    /** @test */
    public function job_busca_nodo_en_conditions_si_no_esta_en_stages(): void
    {
        // Crear condición en la BD
        FlujoCondicion::create([
            'id' => 'conditional-1',
            'flujo_id' => $this->flujo->id,
            'label' => 'Test',
            'condition_type' => 'email_opened',
            'condition_label' => 'Email abierto',
            'yes_label' => 'Sí',
            'no_label' => 'No',
            'check_param' => 'Views',
            'check_operator' => '>',
            'check_value' => '0',
        ]);
        
        // Crear etapa de email completada
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
            'estado' => 'completed',
            'ejecutado' => true,
            'message_id' => 99999,
            'fecha_programada' => now()->subHour(),
            'fecha_ejecucion' => now()->subHour(),
        ]);
        
        $this->ejecucion->update([
            'proximo_nodo' => 'conditional-1',
            'fecha_proximo_nodo' => now()->subMinute(),
        ]);
        
        Bus::fake([VerificarCondicionJob::class]);
        $envioService = Mockery::mock(EnvioService::class);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Debería haber encontrado el nodo en conditions
        Bus::assertDispatched(VerificarCondicionJob::class);
    }

    /** @test */
    public function job_detecta_tipo_desde_tipo_mensaje_si_no_hay_type(): void
    {
        $this->flujo->update([
            'config_structure' => [
                'stages' => [
                    [
                        'id' => 'stage-1',
                        // Sin 'type'
                        'tipo_mensaje' => 'email',
                        'plantilla_mensaje' => 'Test',
                    ],
                ],
                'branches' => [],
            ],
        ]);
        
        $envioService = $this->mockEnvioService();
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Debería haber detectado como stage y enviado
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'estado' => 'completed',
        ]);
    }

    // ============================================
    // Tests de Múltiples Ejecuciones
    // ============================================

    /** @test */
    public function job_procesa_multiples_ejecuciones(): void
    {
        // Crear segunda ejecución
        $ejecucion2 = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
            'proximo_nodo' => 'stage-1',
            'fecha_proximo_nodo' => now()->subMinute(),
            'prospectos_ids' => [$this->prospecto->id],
        ]);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->twice()
            ->andReturn(['error' => false, 'mensaje' => ['messageID' => 11111]]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Ambas ejecuciones deberían haber sido procesadas
        $this->assertEquals('stage-1', $this->ejecucion->fresh()->nodo_actual);
        $this->assertEquals('stage-1', $ejecucion2->fresh()->nodo_actual);
    }

    /** @test */
    public function job_continua_con_otras_ejecuciones_si_una_falla(): void
    {
        // Crear segunda ejecución con flujo válido
        $ejecucion2 = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
            'proximo_nodo' => 'stage-1',
            'fecha_proximo_nodo' => now()->subMinute(),
            'prospectos_ids' => [$this->prospecto->id],
        ]);
        
        // Hacer que la primera ejecución falle
        $this->ejecucion->update(['proximo_nodo' => 'nodo-inexistente']);
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->once()
            ->andReturn(['error' => false, 'mensaje' => ['messageID' => 22222]]);
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // Primera falla, segunda procesa
        $this->assertEquals('failed', $this->ejecucion->fresh()->estado);
        $this->assertEquals('stage-1', $ejecucion2->fresh()->nodo_actual);
    }

    // ============================================
    // Tests de Atomicidad (SIN transacciones largas)
    // ============================================

    /** @test */
    public function job_no_usa_transacciones_largas(): void
    {
        // Este test verifica indirectamente que no hay transacciones largas
        // verificando que cada operación es independiente
        
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->once()
            ->andThrow(new \Exception('Error de envío simulado'));
        
        $job = new EjecutarNodosProgramados();
        $job->handle($envioService);
        
        // La ejecución debería estar marcada como fallida
        $this->ejecucion->refresh();
        $this->assertEquals('failed', $this->ejecucion->estado);
        
        // Pero la etapa debería haberse creado (operación atómica previa)
        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-1',
        ]);
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
}
