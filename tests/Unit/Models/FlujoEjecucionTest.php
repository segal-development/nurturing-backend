<?php

namespace Tests\Unit\Models;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlujoEjecucionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function puede_crear_ejecucion_con_datos_minimos(): void
    {
        $flujo = Flujo::factory()->create();

        $ejecucion = FlujoEjecucion::create([
            'flujo_id' => $flujo->id,
            'origen_id' => 'test-origen',
            'prospectos_ids' => [1, 2, 3],
            'estado' => 'pending',
            'fecha_inicio_programada' => now(),
        ]);

        $this->assertNotNull($ejecucion);
        $this->assertEquals('pending', $ejecucion->estado);
        $this->assertEquals($flujo->id, $ejecucion->flujo_id);
    }

    /** @test */
    public function puede_almacenar_ids_de_prospectos(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = [1, 2, 3, 4, 5];

        $ejecucion = FlujoEjecucion::create([
            'flujo_id' => $flujo->id,
            'origen_id' => 'test-origen',
            'fecha_inicio_programada' => now(),
            'prospectos_ids' => $prospectoIds,
            'estado' => 'pending',
        ]);

        $this->assertEquals($prospectoIds, $ejecucion->prospectos_ids);
        $this->assertIsArray($ejecucion->prospectos_ids);
    }

    /** @test */
    public function scope_pendientes_filtra_correctamente(): void
    {
        $flujo = Flujo::factory()->create();

        FlujoEjecucion::factory()->count(3)->create([
            'flujo_id' => $flujo->id,
            'estado' => 'pending',
        ]);

        FlujoEjecucion::factory()->count(2)->create([
            'flujo_id' => $flujo->id,
            'estado' => 'completed',
        ]);

        $pendientes = FlujoEjecucion::pendientes()->get();

        $this->assertCount(3, $pendientes);
    }

    /** @test */
    public function scope_en_progreso_filtra_correctamente(): void
    {
        $flujo = Flujo::factory()->create();

        FlujoEjecucion::factory()->count(2)->create([
            'flujo_id' => $flujo->id,
            'estado' => 'in_progress',
        ]);

        FlujoEjecucion::factory()->create([
            'flujo_id' => $flujo->id,
            'estado' => 'pending',
        ]);

        $enProgreso = FlujoEjecucion::enProgreso()->get();

        $this->assertCount(2, $enProgreso);
    }

    /** @test */
    public function scope_deberian_haber_comenzado_filtra_correctamente(): void
    {
        $flujo = Flujo::factory()->create();

        // Programadas en el pasado - deberían haber comenzado
        FlujoEjecucion::factory()->count(2)->create([
            'flujo_id' => $flujo->id,
            'estado' => 'pending',
            'fecha_inicio_programada' => now()->subHour(),
        ]);

        // Programadas en el futuro
        FlujoEjecucion::factory()->create([
            'flujo_id' => $flujo->id,
            'estado' => 'pending',
            'fecha_inicio_programada' => now()->addHour(),
        ]);

        $deberianHaberComenzado = FlujoEjecucion::deberianHaberComenzado()->get();

        $this->assertCount(2, $deberianHaberComenzado);
    }

    /** @test */
    public function puede_almacenar_configuracion_compleja(): void
    {
        $flujo = Flujo::factory()->create();
        $config = [
            'timeout' => 300,
            'reintentos' => 3,
            'delay_entre_envios' => 60,
        ];

        $ejecucion = FlujoEjecucion::create([
            'flujo_id' => $flujo->id,
            'origen_id' => 'test-origen',
            'prospectos_ids' => [1, 2, 3],
            'fecha_inicio_programada' => now(),
            'config' => $config,
            'estado' => 'pending',
        ]);

        $this->assertEquals($config, $ejecucion->fresh()->config);
    }

    /** @test */
    public function puede_registrar_error(): void
    {
        $ejecucion = FlujoEjecucion::factory()->create(['estado' => 'in_progress']);

        $ejecucion->update([
            'estado' => 'failed',
            'error_message' => 'Error en el envío',
        ]);

        $this->assertEquals('failed', $ejecucion->estado);
        $this->assertEquals('Error en el envío', $ejecucion->error_message);
    }

    /** @test */
    public function relacion_con_flujo_funciona(): void
    {
        $flujo = Flujo::factory()->create();
        $ejecucion = FlujoEjecucion::factory()->create(['flujo_id' => $flujo->id]);

        $this->assertInstanceOf(Flujo::class, $ejecucion->flujo);
        $this->assertEquals($flujo->id, $ejecucion->flujo->id);
    }

    /** @test */
    public function relacion_con_etapas_funciona(): void
    {
        $ejecucion = FlujoEjecucion::factory()->create();
        FlujoEjecucionEtapa::factory()->count(3)->create([
            'flujo_ejecucion_id' => $ejecucion->id,
        ]);

        $this->assertCount(3, $ejecucion->etapas);
    }

    /** @test */
    public function maneja_arrays_vacios_de_prospectos(): void
    {
        $flujo = Flujo::factory()->create();

        $ejecucion = FlujoEjecucion::create([
            'flujo_id' => $flujo->id,
            'origen_id' => 'test-origen',
            'fecha_inicio_programada' => now(),
            'prospectos_ids' => [],
            'estado' => 'pending',
        ]);

        $this->assertIsArray($ejecucion->prospectos_ids);
        $this->assertEmpty($ejecucion->prospectos_ids);
    }

    /** @test */
    public function maneja_null_en_fechas_opcionales(): void
    {
        $flujo = Flujo::factory()->create();

        $ejecucion = FlujoEjecucion::create([
            'flujo_id' => $flujo->id,
            'origen_id' => 'test-origen',
            'prospectos_ids' => [1, 2, 3],
            'fecha_inicio_programada' => now(),
            'estado' => 'pending',
            'fecha_inicio_real' => null,
            'fecha_fin' => null,
        ]);

        $this->assertNull($ejecucion->fecha_inicio_real);
        $this->assertNull($ejecucion->fecha_fin);
    }

    /** @test */
    public function puede_actualizar_estado_de_pending_a_in_progress(): void
    {
        $ejecucion = FlujoEjecucion::factory()->create(['estado' => 'pending']);

        $ejecucion->update([
            'estado' => 'in_progress',
            'fecha_inicio_real' => now(),
        ]);

        $this->assertEquals('in_progress', $ejecucion->estado);
        $this->assertNotNull($ejecucion->fecha_inicio_real);
    }

    /** @test */
    public function puede_actualizar_estado_de_in_progress_a_completed(): void
    {
        $ejecucion = FlujoEjecucion::factory()->create(['estado' => 'in_progress']);

        $ejecucion->update([
            'estado' => 'completed',
            'fecha_fin' => now(),
        ]);

        $this->assertEquals('completed', $ejecucion->estado);
        $this->assertNotNull($ejecucion->fecha_fin);
    }

    /** @test */
    public function casts_convierten_fechas_correctamente(): void
    {
        $fecha = now();
        $flujo = Flujo::factory()->create();

        $ejecucion = FlujoEjecucion::create([
            'flujo_id' => $flujo->id,
            'origen_id' => 'test-origen',
            'prospectos_ids' => [1, 2, 3],
            'estado' => 'pending',
            'fecha_inicio_programada' => $fecha->toDateTimeString(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $ejecucion->fecha_inicio_programada);
    }
}
