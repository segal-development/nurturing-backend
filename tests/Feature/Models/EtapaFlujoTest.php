<?php

namespace Tests\Feature\Models;

use App\Models\Envio;
use App\Models\EtapaFlujo;
use App\Models\Flujo;
use App\Models\OfertaInfocom;
use App\Models\PlantillaMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtapaFlujoTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_etapa_flujo(): void
    {
        $flujo = Flujo::factory()->create();
        $plantilla = PlantillaMensaje::factory()->create();

        $etapa = EtapaFlujo::factory()->create([
            'flujo_id' => $flujo->id,
            'plantilla_mensaje_id' => $plantilla->id,
            'dias_desde_inicio' => 30,
            'orden' => 1,
        ]);

        $this->assertDatabaseHas('etapas_flujo', [
            'id' => $etapa->id,
            'flujo_id' => $flujo->id,
            'dias_desde_inicio' => 30,
            'orden' => 1,
        ]);
    }

    public function test_casts_activo_como_boolean(): void
    {
        $etapa = EtapaFlujo::factory()->create(['activo' => true]);

        $this->assertIsBool($etapa->activo);
        $this->assertTrue($etapa->activo);
    }

    public function test_relacion_con_flujo(): void
    {
        $flujo = Flujo::factory()->create();
        $etapa = EtapaFlujo::factory()->create(['flujo_id' => $flujo->id]);

        $this->assertInstanceOf(Flujo::class, $etapa->flujo);
        $this->assertEquals($flujo->id, $etapa->flujo->id);
    }

    public function test_relacion_con_plantilla_mensaje(): void
    {
        $plantilla = PlantillaMensaje::factory()->create();
        $etapa = EtapaFlujo::factory()->create(['plantilla_mensaje_id' => $plantilla->id]);

        $this->assertInstanceOf(PlantillaMensaje::class, $etapa->plantillaMensaje);
        $this->assertEquals($plantilla->id, $etapa->plantillaMensaje->id);
    }

    public function test_relacion_con_ofertas(): void
    {
        $etapa = EtapaFlujo::factory()->create();
        $oferta = OfertaInfocom::factory()->create();

        $etapa->ofertas()->attach($oferta->id, ['orden' => 1, 'activo' => true]);

        $this->assertTrue($etapa->ofertas->contains($oferta));
        $this->assertCount(1, $etapa->ofertas);
    }

    public function test_relacion_ofertas_activas(): void
    {
        $etapa = EtapaFlujo::factory()->create();
        $oferta1 = OfertaInfocom::factory()->create();
        $oferta2 = OfertaInfocom::factory()->create();

        $etapa->ofertas()->attach($oferta1->id, ['orden' => 1, 'activo' => true]);
        $etapa->ofertas()->attach($oferta2->id, ['orden' => 2, 'activo' => false]);

        $this->assertCount(1, $etapa->ofertasActivas);
        $this->assertTrue($etapa->ofertasActivas->contains($oferta1));
        $this->assertFalse($etapa->ofertasActivas->contains($oferta2));
    }

    public function test_relacion_con_envios(): void
    {
        $etapa = EtapaFlujo::factory()->create();
        Envio::factory()->create(['etapa_flujo_id' => $etapa->id]);

        $this->assertCount(1, $etapa->envios);
    }

    public function test_scope_activos(): void
    {
        EtapaFlujo::factory()->create(['activo' => true]);
        EtapaFlujo::factory()->create(['activo' => true]);
        EtapaFlujo::factory()->create(['activo' => false]);

        $this->assertEquals(2, EtapaFlujo::activos()->count());
    }

    public function test_scope_ordenados(): void
    {
        EtapaFlujo::factory()->create(['orden' => 3]);
        EtapaFlujo::factory()->create(['orden' => 1]);
        EtapaFlujo::factory()->create(['orden' => 2]);

        $etapas = EtapaFlujo::ordenados()->get();

        $this->assertEquals(1, $etapas[0]->orden);
        $this->assertEquals(2, $etapas[1]->orden);
        $this->assertEquals(3, $etapas[2]->orden);
    }

    public function test_scope_por_flujo(): void
    {
        $flujo1 = Flujo::factory()->create();
        $flujo2 = Flujo::factory()->create();

        EtapaFlujo::factory()->count(3)->create(['flujo_id' => $flujo1->id]);
        EtapaFlujo::factory()->count(2)->create(['flujo_id' => $flujo2->id]);

        $this->assertEquals(3, EtapaFlujo::porFlujo($flujo1->id)->count());
        $this->assertEquals(2, EtapaFlujo::porFlujo($flujo2->id)->count());
    }

    public function test_calcular_fecha_programada(): void
    {
        $etapa = EtapaFlujo::factory()->create(['dias_desde_inicio' => 30]);
        $fechaInicio = new \DateTime('2025-01-01');

        $fechaProgramada = $etapa->calcularFechaProgramada($fechaInicio);

        $this->assertEquals('2025-01-31', $fechaProgramada->format('Y-m-d'));
    }

    public function test_calcular_fecha_programada_con_diferentes_dias(): void
    {
        $etapas = [
            ['dias' => 15, 'esperado' => '2025-01-16'],
            ['dias' => 30, 'esperado' => '2025-01-31'],
            ['dias' => 60, 'esperado' => '2025-03-02'],
            ['dias' => 90, 'esperado' => '2025-04-01'],
        ];

        foreach ($etapas as $data) {
            $etapa = EtapaFlujo::factory()->create(['dias_desde_inicio' => $data['dias']]);
            $fechaInicio = new \DateTime('2025-01-01');

            $fechaProgramada = $etapa->calcularFechaProgramada($fechaInicio);

            $this->assertEquals($data['esperado'], $fechaProgramada->format('Y-m-d'));
        }
    }

    public function test_ofertas_ordenadas_por_pivot_orden(): void
    {
        $etapa = EtapaFlujo::factory()->create();
        $oferta1 = OfertaInfocom::factory()->create();
        $oferta2 = OfertaInfocom::factory()->create();
        $oferta3 = OfertaInfocom::factory()->create();

        $etapa->ofertas()->attach($oferta1->id, ['orden' => 3, 'activo' => true]);
        $etapa->ofertas()->attach($oferta2->id, ['orden' => 1, 'activo' => true]);
        $etapa->ofertas()->attach($oferta3->id, ['orden' => 2, 'activo' => true]);

        $ofertas = $etapa->ofertas;

        $this->assertEquals($oferta2->id, $ofertas[0]->id);
        $this->assertEquals($oferta3->id, $ofertas[1]->id);
        $this->assertEquals($oferta1->id, $ofertas[2]->id);
    }
}
