<?php

namespace Tests\Unit\Jobs;

use App\Jobs\AsignarProspectosAFlujoJob;
use App\Models\Flujo;
use Mockery;
use Tests\TestCase;

class AsignarProspectosAFlujoJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createMockFlujo(array $attributes = []): Flujo
    {
        $flujo = Mockery::mock(Flujo::class)->makePartial();
        $flujo->id = $attributes['id'] ?? 1;
        $flujo->estado_procesamiento = $attributes['estado_procesamiento'] ?? 'procesando';
        $flujo->metadata = $attributes['metadata'] ?? [];

        return $flujo;
    }

    /** @test */
    public function job_puede_ser_instanciado_con_parametros_validos(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1, 2, 3],
            canalAsignado: 'email'
        );

        $this->assertInstanceOf(AsignarProspectosAFlujoJob::class, $job);
        $this->assertEquals(1, $job->flujo->id);
        $this->assertCount(3, $job->prospectoIds);
        $this->assertEquals('email', $job->canalAsignado);
    }

    /** @test */
    public function job_tiene_timeout_configurado_correctamente(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1],
            canalAsignado: 'email'
        );

        $this->assertEquals(600, $job->timeout);
    }

    /** @test */
    public function job_tiene_intentos_configurados_correctamente(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1],
            canalAsignado: 'email'
        );

        $this->assertEquals(3, $job->tries);
    }

    /** @test */
    public function job_acepta_canal_email(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1, 2],
            canalAsignado: 'email'
        );

        $this->assertEquals('email', $job->canalAsignado);
    }

    /** @test */
    public function job_acepta_canal_sms(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1, 2],
            canalAsignado: 'sms'
        );

        $this->assertEquals('sms', $job->canalAsignado);
    }

    /** @test */
    public function job_acepta_array_vacio_de_prospectos(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [],
            canalAsignado: 'email'
        );

        $this->assertCount(0, $job->prospectoIds);
    }

    /** @test */
    public function job_puede_manejar_gran_cantidad_de_ids(): void
    {
        $flujo = $this->createMockFlujo();
        $prospectoIds = range(1, 5000);

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: $prospectoIds,
            canalAsignado: 'email'
        );

        $this->assertCount(5000, $job->prospectoIds);
    }

    /** @test */
    public function job_preserva_referencia_al_flujo(): void
    {
        $flujo = $this->createMockFlujo(['id' => 123]);

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1],
            canalAsignado: 'email'
        );

        $this->assertEquals(123, $job->flujo->id);
    }

    /** @test */
    public function job_preserva_ids_de_prospectos_en_orden(): void
    {
        $flujo = $this->createMockFlujo();
        $ids = [5, 3, 8, 1, 9];

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: $ids,
            canalAsignado: 'email'
        );

        $this->assertEquals([5, 3, 8, 1, 9], $job->prospectoIds);
    }

    /** @test */
    public function job_implementa_should_queue(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1],
            canalAsignado: 'email'
        );

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }

    /** @test */
    public function job_usa_queueable_trait(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1],
            canalAsignado: 'email'
        );

        // Verificar que tiene métodos del trait Queueable
        $this->assertTrue(method_exists($job, 'onQueue'));
        $this->assertTrue(method_exists($job, 'onConnection'));
    }

    /** @test */
    public function job_tiene_metodo_handle(): void
    {
        $this->assertTrue(method_exists(AsignarProspectosAFlujoJob::class, 'handle'));
    }

    /** @test */
    public function job_tiene_metodo_failed(): void
    {
        $this->assertTrue(method_exists(AsignarProspectosAFlujoJob::class, 'failed'));
    }

    /** @test */
    public function job_acepta_ids_como_enteros(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1, 2, 3],
            canalAsignado: 'email'
        );

        foreach ($job->prospectoIds as $id) {
            $this->assertIsInt($id);
        }
    }

    /** @test */
    public function job_acepta_ids_mixtos(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1, '2', 3],
            canalAsignado: 'email'
        );

        $this->assertCount(3, $job->prospectoIds);
    }

    /** @test */
    public function job_maneja_ids_duplicados(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1, 1, 2, 2, 3],
            canalAsignado: 'email'
        );

        // El job no filtra duplicados, eso es responsabilidad del caller
        $this->assertCount(5, $job->prospectoIds);
    }

    /** @test */
    public function job_timeout_es_suficiente_para_procesamiento_grande(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1],
            canalAsignado: 'email'
        );

        // 600 segundos = 10 minutos, suficiente para 350k registros
        $this->assertGreaterThanOrEqual(600, $job->timeout);
    }

    /** @test */
    public function job_tiene_reintentos_razonables(): void
    {
        $flujo = $this->createMockFlujo();

        $job = new AsignarProspectosAFlujoJob(
            flujo: $flujo,
            prospectoIds: [1],
            canalAsignado: 'email'
        );

        // 3 intentos es razonable para un job de inserción masiva
        $this->assertGreaterThanOrEqual(1, $job->tries);
        $this->assertLessThanOrEqual(5, $job->tries);
    }
}
