<?php

namespace Tests\Unit\Jobs;

use App\Jobs\EnviarEmailEtapaProspectoJob;
use App\Jobs\EnviarEtapaJob;
use App\Jobs\EnviarSmsEtapaProspectoJob;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EnviarEtapaJobTest extends TestCase
{
    use RefreshDatabase;

    private Flujo $flujo;

    private FlujoEjecucion $ejecucion;

    private FlujoEjecucionEtapa $etapaEjecucion;

    private Prospecto $prospecto;

    protected function setUp(): void
    {
        parent::setUp();

        // Create base entities for tests
        $this->flujo = Flujo::factory()->create([
            'config_structure' => [
                'stages' => [
                    ['id' => 'stage1', 'type' => 'stage', 'label' => 'Etapa 1'],
                ],
                'branches' => [],
            ],
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'pending',
        ]);

        $this->etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'estado' => 'pending',
        ]);

        // Create a real prospecto to satisfy foreign key constraints
        $tipoProspecto = TipoProspecto::factory()->create();
        $this->prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'email' => 'test@example.com',
            'telefono' => '+56912345678',
        ]);
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
        Bus::fake();

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: [
                'id' => 'stage1',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Hola {nombre}',
            ],
            prospectoIds: [$this->prospecto->id]
        );

        $job->handle();

        $this->assertEquals('executing', $this->etapaEjecucion->fresh()->estado);
    }

    /** @test */
    public function job_despacha_batch_para_email(): void
    {
        Bus::fake();

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: [
                'id' => 'stage1',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Hola {nombre}',
            ],
            prospectoIds: [$this->prospecto->id]
        );

        $job->handle();

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1
                && $batch->jobs->first() instanceof EnviarEmailEtapaProspectoJob;
        });
    }

    /** @test */
    public function job_despacha_batch_para_sms(): void
    {
        Bus::fake();

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: [
                'id' => 'stage1',
                'label' => 'Etapa 1',
                'tipo_mensaje' => 'sms',
                'plantilla_mensaje' => 'Hola {nombre}',
            ],
            prospectoIds: [$this->prospecto->id]
        );

        $job->handle();

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1
                && $batch->jobs->first() instanceof EnviarSmsEtapaProspectoJob;
        });
    }

    /** @test */
    public function job_registra_flujo_job_al_despachar_batch(): void
    {
        Bus::fake();

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: [$this->prospecto->id]
        );

        $job->handle();

        $this->assertDatabaseHas('flujo_jobs', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'job_type' => 'enviar_etapa_batch',
            'estado' => 'processing',
        ]);
    }

    /** @test */
    public function job_no_procesa_etapa_ya_completada(): void
    {
        Bus::fake();

        $this->etapaEjecucion->update(['estado' => 'completed']);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: [$this->prospecto->id]
        );

        $job->handle();

        Bus::assertNothingBatched();
    }

    /** @test */
    public function job_maneja_arrays_vacios_de_prospectos(): void
    {
        Bus::fake();

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: []
        );

        $job->handle();

        // With empty prospectos, should mark as completed without batch
        Bus::assertNothingBatched();
        $this->assertEquals('completed', $this->etapaEjecucion->fresh()->estado);
    }

    /** @test */
    public function job_puede_ser_creado_con_branches_para_siguiente_etapa(): void
    {
        $branches = [
            ['source_node_id' => 'stage1', 'target_node_id' => 'stage2'],
        ];

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: ['id' => 'stage1', 'tipo_mensaje' => 'email'],
            prospectoIds: [$this->prospecto->id],
            branches: $branches
        );

        // Verify the job stores branches for processing next step
        $this->assertInstanceOf(EnviarEtapaJob::class, $job);
        $this->assertCount(1, $job->branches);
        $this->assertEquals('stage1', $job->branches[0]['source_node_id']);
        $this->assertEquals('stage2', $job->branches[0]['target_node_id']);
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

        // Timeout is 120 seconds now (batch dispatcher only)
        $this->assertEquals(120, $job->timeout);
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

    /** @test */
    public function job_crea_prospecto_en_flujo_si_no_existe(): void
    {
        Bus::fake();

        // Verify no ProspectoEnFlujo exists
        $this->assertDatabaseMissing('prospecto_en_flujo', [
            'prospecto_id' => $this->prospecto->id,
            'flujo_id' => $this->flujo->id,
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: [
                'id' => 'stage1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test',
            ],
            prospectoIds: [$this->prospecto->id]
        );

        $job->handle();

        // Now ProspectoEnFlujo should exist
        $this->assertDatabaseHas('prospecto_en_flujo', [
            'prospecto_id' => $this->prospecto->id,
            'flujo_id' => $this->flujo->id,
            'canal_asignado' => 'email',
        ]);
    }

    /** @test */
    public function job_despacha_multiple_jobs_para_multiple_prospectos(): void
    {
        Bus::fake();

        // Create additional prospectos
        $tipoProspecto = TipoProspecto::first();
        $prospecto2 = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'email' => 'test2@example.com',
        ]);
        $prospecto3 = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'email' => 'test3@example.com',
        ]);

        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: [
                'id' => 'stage1',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Test',
            ],
            prospectoIds: [$this->prospecto->id, $prospecto2->id, $prospecto3->id]
        );

        $job->handle();

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 3;
        });
    }
}
