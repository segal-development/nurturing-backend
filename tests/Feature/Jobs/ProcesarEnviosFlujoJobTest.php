<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EnviarEmailProspectoJob;
use App\Jobs\EnviarSmsProspectoJob;
use App\Jobs\ProcesarEnviosFlujoJob;
use App\Models\Flujo;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProcesarEnviosFlujoJobTest extends TestCase
{
    use RefreshDatabase;

    protected TipoProspecto $tipoProspecto;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();
    }

    public function test_job_does_nothing_when_flujo_not_found(): void
    {
        Bus::fake();

        $job = new ProcesarEnviosFlujoJob(999);
        $job->handle();

        Bus::assertNothingDispatched();
    }

    public function test_job_does_nothing_when_flujo_is_inactive(): void
    {
        Bus::fake();

        $flujo = Flujo::factory()
            ->inactivo()
            ->create([
                'tipo_prospecto_id' => $this->tipoProspecto->id,
                'user_id' => $this->user->id,
            ]);

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertNothingDispatched();
    }

    public function test_job_does_nothing_when_no_pending_prospectos(): void
    {
        Bus::fake();

        $flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Create prospectos but mark them as completed
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        ProspectoEnFlujo::factory()->create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospecto->id,
            'estado' => 'completado',
        ]);

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertNothingDispatched();
    }

    public function test_job_dispatches_email_jobs_for_email_channel(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class, EnviarSmsProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $prospectos = Prospecto::factory()->count(5)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        foreach ($prospectos as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 5;
        });
    }

    public function test_job_dispatches_sms_jobs_for_sms_channel(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class, EnviarSmsProspectoJob::class]);

        $flujo = Flujo::factory()->porSms()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $prospectos = Prospecto::factory()->count(3)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        foreach ($prospectos as $prospecto) {
            ProspectoEnFlujo::factory()->porSms()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 3;
        });
    }

    public function test_job_dispatches_mixed_jobs_for_both_channels(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class, EnviarSmsProspectoJob::class]);

        $flujo = Flujo::factory()->porAmbos()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Create prospectos with different channels
        $prospectosEmail = Prospecto::factory()->count(3)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $prospectosSms = Prospecto::factory()->count(2)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        foreach ($prospectosEmail as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        foreach ($prospectosSms as $prospecto) {
            ProspectoEnFlujo::factory()->porSms()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 5;
        });
    }

    public function test_job_only_processes_pending_prospectos(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class, EnviarSmsProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Create 3 pending, 2 completed, 1 cancelled
        $prospectosPendientes = Prospecto::factory()->count(3)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $prospectosCompletados = Prospecto::factory()->count(2)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $prospectosCancelados = Prospecto::factory()->count(1)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        foreach ($prospectosPendientes as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        foreach ($prospectosCompletados as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
                'estado' => 'completado',
            ]);
        }

        foreach ($prospectosCancelados as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
                'estado' => 'cancelado',
            ]);
        }

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        // Should only process the 3 pending
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 3;
        });
    }

    public function test_job_updates_flujo_metadata_with_batch_info(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'metadata' => ['existing' => 'data'],
        ]);

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        $flujo->refresh();

        $this->assertArrayHasKey('ultimo_procesamiento', $flujo->metadata);
        $this->assertArrayHasKey('batch_id', $flujo->metadata['ultimo_procesamiento']);
        $this->assertEquals(1, $flujo->metadata['ultimo_procesamiento']['total_jobs']);
        $this->assertArrayHasKey('existing', $flujo->metadata);
    }

    public function test_job_chunks_large_datasets(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Create more prospectos than CHUNK_SIZE
        $numProspectos = ProcesarEnviosFlujoJob::CHUNK_SIZE + 50;

        $prospectos = Prospecto::factory()->count($numProspectos)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        foreach ($prospectos as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertBatched(function ($batch) use ($numProspectos) {
            return $batch->jobs->count() === $numProspectos;
        });
    }

    public function test_job_creates_correct_job_type_based_on_canal(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class, EnviarSmsProspectoJob::class]);

        $flujo = Flujo::factory()->porAmbos()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $prospectoEmail = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $prospectoSms = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospectoEmail->id,
        ]);

        ProspectoEnFlujo::factory()->porSms()->pendiente()->create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospectoSms->id,
        ]);

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertBatched(function ($batch) {
            $jobs = $batch->jobs;

            $emailJobs = $jobs->filter(fn ($job) => $job instanceof EnviarEmailProspectoJob);
            $smsJobs = $jobs->filter(fn ($job) => $job instanceof EnviarSmsProspectoJob);

            return $emailJobs->count() === 1 && $smsJobs->count() === 1;
        });
    }

    public function test_job_has_correct_tags(): void
    {
        $job = new ProcesarEnviosFlujoJob(123);

        $tags = $job->tags();

        $this->assertContains('flujo:123', $tags);
        $this->assertContains('procesar-envios', $tags);
    }

    public function test_job_passes_plantilla_to_child_jobs(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $asunto = 'Test Subject';
        $contenido = 'Test Content';

        $job = new ProcesarEnviosFlujoJob($flujo->id, $asunto, $contenido);
        $job->handle();

        Bus::assertBatched(function ($batch) use ($asunto, $contenido) {
            $emailJob = $batch->jobs->first();

            return $emailJob->asunto === $asunto && $emailJob->contenido === $contenido;
        });
    }

    // ============================================
    // TESTS: Volumen masivo (20k+ registros)
    // ============================================

    /**
     * @test
     *
     * @group volume
     */
    public function job_handles_1000_prospectos_efficiently(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Crear 1000 prospectos usando insert masivo para performance
        $prospectos = Prospecto::factory()->count(1000)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        foreach ($prospectos as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        $startTime = microtime(true);

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        $executionTime = microtime(true) - $startTime;

        // Verificar que se crearon todos los jobs
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1000;
        });

        // El job debería completarse en menos de 30 segundos
        $this->assertLessThan(30, $executionTime, "Job took too long: {$executionTime}s");
    }

    /**
     * @test
     *
     * @group volume
     */
    public function job_processes_in_correct_chunk_size(): void
    {
        // Verificar que la constante CHUNK_SIZE está configurada correctamente
        $this->assertEquals(100, ProcesarEnviosFlujoJob::CHUNK_SIZE);
    }

    /**
     * @test
     *
     * @group volume
     */
    public function job_handles_500_prospectos_with_mixed_channels(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class, EnviarSmsProspectoJob::class]);

        $flujo = Flujo::factory()->porAmbos()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // 300 email + 200 sms
        $prospectosEmail = Prospecto::factory()->count(300)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $prospectosSms = Prospecto::factory()->count(200)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        foreach ($prospectosEmail as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        foreach ($prospectosSms as $prospecto) {
            ProspectoEnFlujo::factory()->porSms()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertBatched(function ($batch) {
            $emailJobs = $batch->jobs->filter(fn ($job) => $job instanceof EnviarEmailProspectoJob);
            $smsJobs = $batch->jobs->filter(fn ($job) => $job instanceof EnviarSmsProspectoJob);

            return $emailJobs->count() === 300 && $smsJobs->count() === 200;
        });
    }

    /**
     * @test
     *
     * @group volume
     */
    public function job_memory_usage_stays_reasonable_for_large_datasets(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Crear 500 prospectos
        $prospectos = Prospecto::factory()->count(500)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        foreach ($prospectos as $prospecto) {
            ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
                'flujo_id' => $flujo->id,
                'prospecto_id' => $prospecto->id,
            ]);
        }

        $memoryBefore = memory_get_usage(true);

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        $memoryAfter = memory_get_usage(true);
        $memoryUsedMB = ($memoryAfter - $memoryBefore) / 1024 / 1024;

        // No debería usar más de 50MB adicionales para 500 prospectos
        $this->assertLessThan(50, $memoryUsedMB, "Memory usage too high: {$memoryUsedMB}MB");

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 500;
        });
    }

    /**
     * @test
     */
    public function job_batch_is_named_correctly(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'nombre' => 'Mi Flujo de Prueba',
        ]);

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertBatched(function ($batch) use ($flujo) {
            return str_contains($batch->name, "Flujo {$flujo->id}");
        });
    }

    /**
     * @test
     */
    public function job_dispatches_to_envios_queue(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertBatched(function ($batch) {
            // El batch debería estar en la cola 'envios'
            return ($batch->options['queue'] ?? null) === 'envios';
        });
    }

    /**
     * @test
     */
    public function job_allows_failures_in_batch(): void
    {
        Bus::fake([EnviarEmailProspectoJob::class]);

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $job = new ProcesarEnviosFlujoJob($flujo->id);
        $job->handle();

        Bus::assertBatched(function ($batch) {
            // El batch debería permitir fallos (allowFailures)
            return $batch->allowsFailures();
        });
    }
}
