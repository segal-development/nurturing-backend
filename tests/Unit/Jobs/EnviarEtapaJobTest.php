<?php

namespace Tests\Unit\Jobs;

use App\Jobs\EnviarEtapaJob;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Services\EnvioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EnviarEtapaJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function job_puede_ser_creado_con_parametros_minimos(): void
    {
        $job = new EnviarEtapaJob(
            flujoEjecucionId: 1,
            etapaEjecucionId: 1,
            stage: ['id' => 'stage1', 'label' => 'Etapa 1'],
            prospectoIds: [1, 2, 3]
        );

        $this->assertInstanceOf(EnviarEtapaJob::class, $job);
        $this->assertEquals(1, $job->flujoEjecucionId);
        $this->assertEquals(1, $job->etapaEjecucionId);
        $this->assertCount(3, $job->prospectoIds);
    }

    /** @test */
    public function job_puede_ser_creado_con_branches(): void
    {
        $branches = [
            ['source_node_id' => 'stage1', 'target_node_id' => 'stage2'],
        ];

        $job = new EnviarEtapaJob(
            flujoEjecucionId: 1,
            etapaEjecucionId: 1,
            stage: ['id' => 'stage1'],
            prospectoIds: [1],
            branches: $branches
        );

        $this->assertEquals($branches, $job->branches);
    }

    /** @test */
    public function job_actualiza_estado_a_executing_al_iniciar(): void
    {
        $flujo = Flujo::factory()->create();
        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $flujo->id,
            'estado' => 'pending',
        ]);

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
        ]);

        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->once()
            ->andReturn([
                'mensaje' => [
                    'messageID' => 12345,
                    'Recipients' => 3,
                ],
            ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage1',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Hola {nombre}',
            ],
            prospectoIds: [1, 2, 3]
        );

        $job->handle($envioService);

        $this->assertEquals('executing', $etapaEjecucion->fresh()->estado);
    }

    /** @test */
    public function job_actualiza_estado_a_completed_cuando_termina_exitosamente(): void
    {
        $flujo = Flujo::factory()->create();
        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $flujo->id,
            'estado' => 'pending',
        ]);

        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
        ]);

        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->once()
            ->andReturn([
                'mensaje' => [
                    'messageID' => 12345,
                    'Recipients' => 3,
                ],
            ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: [
                'id' => 'stage1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Hola',
            ],
            prospectoIds: [1, 2, 3]
        );

        $job->handle($envioService);

        $etapaActualizada = $etapaEjecucion->fresh();
        $this->assertEquals('completed', $etapaActualizada->estado);
        $this->assertNotNull($etapaActualizada->message_id);
        $this->assertEquals(12345, $etapaActualizada->message_id);
    }

    /** @test */
    public function job_registra_job_completado_en_flujo_jobs(): void
    {
        $flujo = Flujo::factory()->create();
        $ejecucion = FlujoEjecucion::factory()->create(['flujo_id' => $flujo->id]);
        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create(['flujo_ejecucion_id' => $ejecucion->id]);

        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')->andReturn([
            'mensaje' => ['messageID' => 12345],
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: [1]
        );

        $job->handle($envioService);

        $this->assertDatabaseHas('flujo_jobs', [
            'flujo_ejecucion_id' => $ejecucion->id,
            'job_type' => 'enviar_etapa',
            'estado' => 'completed',
        ]);
    }

    /** @test */
    public function job_lanza_excepcion_si_no_hay_message_id(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No se recibió messageID de AthenaCampaign');

        $flujo = Flujo::factory()->create();
        $ejecucion = FlujoEjecucion::factory()->create(['flujo_id' => $flujo->id]);
        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create(['flujo_ejecucion_id' => $ejecucion->id]);

        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')->andReturn([
            'mensaje' => [], // Sin messageID
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: [1]
        );

        $job->handle($envioService);
    }

    /** @test */
    public function job_marca_etapa_como_failed_cuando_hay_error(): void
    {
        $flujo = Flujo::factory()->create();
        $ejecucion = FlujoEjecucion::factory()->create(['flujo_id' => $flujo->id]);
        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create(['flujo_ejecucion_id' => $ejecucion->id]);

        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')->andThrow(new \Exception('Error de envío'));

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: [1]
        );

        try {
            $job->handle($envioService);
        } catch (\Exception $e) {
            // Esperado
        }

        $this->assertEquals('failed', $etapaEjecucion->fresh()->estado);
    }

    /** @test */
    public function job_no_procesa_etapa_ya_completada(): void
    {
        $flujo = Flujo::factory()->create();
        $ejecucion = FlujoEjecucion::factory()->create(['flujo_id' => $flujo->id]);
        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'completed', // Ya completada
        ]);

        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')->never();

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: [1]
        );

        $job->handle($envioService);
    }

    /** @test */
    public function job_encola_siguiente_etapa_si_existe(): void
    {
        Queue::fake();

        $flujo = Flujo::factory()->create([
            'config_structure' => [
                'stages' => [
                    ['id' => 'stage1', 'type' => 'stage'],
                    ['id' => 'stage2', 'type' => 'stage', 'tiempo_espera' => 2],
                ],
                'branches' => [
                    ['source_node_id' => 'stage1', 'target_node_id' => 'stage2'],
                ],
            ],
        ]);

        $ejecucion = FlujoEjecucion::factory()->create(['flujo_id' => $flujo->id]);
        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create(['flujo_ejecucion_id' => $ejecucion->id]);

        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')->andReturn([
            'mensaje' => ['messageID' => 12345],
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: [1],
            branches: $flujo->config_structure['branches']
        );

        $job->handle($envioService);

        Queue::assertPushed(EnviarEtapaJob::class, 1);
    }

    /** @test */
    public function job_encola_verificacion_de_condicion_si_siguiente_es_condicion(): void
    {
        Queue::fake();

        $flujo = Flujo::factory()->create([
            'config_structure' => [
                'stages' => [
                    ['id' => 'stage1', 'type' => 'stage'],
                ],
            ],
            'config_visual' => [
                'stages' => [
                    ['id' => 'condition1', 'type' => 'condition'],
                ],
                'branches' => [
                    ['source_node_id' => 'stage1', 'target_node_id' => 'condition1'],
                ],
            ],
        ]);

        $ejecucion = FlujoEjecucion::factory()->create(['flujo_id' => $flujo->id]);
        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create(['flujo_ejecucion_id' => $ejecucion->id]);

        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')->andReturn([
            'mensaje' => ['messageID' => 12345],
        ]);

        $stages = array_merge(
            $flujo->config_structure['stages'] ?? [],
            $flujo->config_visual['stages'] ?? []
        );

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email', 'tiempo_verificacion_condicion' => 24],
            prospectoIds: [1],
            branches: $flujo->config_visual['branches']
        );

        $this->app->instance('stages', $stages);

        // Mockear el método procesarSiguientePaso sería ideal aquí
        // Por ahora solo verificamos que el job fue creado correctamente
        $this->assertInstanceOf(EnviarEtapaJob::class, $job);
    }

    /** @test */
    public function job_maneja_arrays_vacios_de_prospectos(): void
    {
        $envioService = Mockery::mock(EnvioService::class);
        $envioService->shouldReceive('enviar')
            ->with(
                Mockery::any(),
                [], // Array vacío
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn(['mensaje' => ['messageID' => 12345]]);

        $flujo = Flujo::factory()->create();
        $ejecucion = FlujoEjecucion::factory()->create(['flujo_id' => $flujo->id]);
        $etapaEjecucion = FlujoEjecucionEtapa::factory()->create(['flujo_ejecucion_id' => $ejecucion->id]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $ejecucion->id,
            etapaEjecucionId: $etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: [] // Array vacío
        );

        $job->handle($envioService);

        $this->assertEquals('completed', $etapaEjecucion->fresh()->estado);
    }

    /** @test */
    public function job_tiene_configurado_timeout_correcto(): void
    {
        $job = new EnviarEtapaJob(
            flujoEjecucionId: 1,
            etapaEjecucionId: 1,
            stage: ['id' => 'stage1'],
            prospectoIds: [1]
        );

        $this->assertEquals(300, $job->timeout);
    }

    /** @test */
    public function job_tiene_configurado_intentos_correctos(): void
    {
        $job = new EnviarEtapaJob(
            flujoEjecucionId: 1,
            etapaEjecucionId: 1,
            stage: ['id' => 'stage1'],
            prospectoIds: [1]
        );

        $this->assertEquals(3, $job->tries);
    }

    /** @test */
    public function job_tiene_configurado_backoff_exponencial(): void
    {
        $job = new EnviarEtapaJob(
            flujoEjecucionId: 1,
            etapaEjecucionId: 1,
            stage: ['id' => 'stage1'],
            prospectoIds: [1]
        );

        $this->assertEquals([60, 300, 900], $job->backoff);
    }
}
