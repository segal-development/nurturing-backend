<?php

namespace Tests\Feature\Models;

use App\Models\Envio;
use App\Models\EtapaFlujo;
use App\Models\Flujo;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlujoTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_flujo(): void
    {
        $tipo = TipoProspecto::factory()->create();

        $flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipo->id,
            'origen' => 'banco_x',
            'nombre' => 'Flujo Test',
        ]);

        $this->assertDatabaseHas('flujos', [
            'id' => $flujo->id,
            'tipo_prospecto_id' => $tipo->id,
            'origen' => 'banco_x',
            'nombre' => 'Flujo Test',
        ]);
    }

    public function test_casts_activo_como_boolean(): void
    {
        $flujo = Flujo::factory()->create(['activo' => true]);

        $this->assertIsBool($flujo->activo);
        $this->assertTrue($flujo->activo);
    }

    public function test_relacion_con_tipo_prospecto(): void
    {
        $tipo = TipoProspecto::factory()->create();
        $flujo = Flujo::factory()->create(['tipo_prospecto_id' => $tipo->id]);

        $this->assertInstanceOf(TipoProspecto::class, $flujo->tipoProspecto);
        $this->assertEquals($tipo->id, $flujo->tipoProspecto->id);
    }

    public function test_relacion_con_etapas(): void
    {
        $flujo = Flujo::factory()->create();
        EtapaFlujo::factory()->count(3)->create(['flujo_id' => $flujo->id]);

        $this->assertCount(3, $flujo->etapas);
    }

    public function test_relacion_con_prospectos_en_flujo(): void
    {
        $flujo = Flujo::factory()->create();
        ProspectoEnFlujo::factory()->create(['flujo_id' => $flujo->id]);

        $this->assertCount(1, $flujo->prospectosEnFlujo);
    }

    public function test_relacion_con_envios(): void
    {
        $flujo = Flujo::factory()->create();
        Envio::factory()->create(['flujo_id' => $flujo->id]);

        $this->assertCount(1, $flujo->envios);
    }

    public function test_scope_activos(): void
    {
        Flujo::factory()->create(['activo' => true]);
        Flujo::factory()->create(['activo' => true]);
        Flujo::factory()->create(['activo' => false]);

        $this->assertEquals(2, Flujo::activos()->count());
    }

    public function test_scope_por_tipo(): void
    {
        $tipo1 = TipoProspecto::factory()->create();
        $tipo2 = TipoProspecto::factory()->create();

        Flujo::factory()->create(['tipo_prospecto_id' => $tipo1->id, 'origen' => 'banco_x']);
        Flujo::factory()->create(['tipo_prospecto_id' => $tipo1->id, 'origen' => 'campania_verano']);
        Flujo::factory()->create(['tipo_prospecto_id' => $tipo1->id, 'origen' => 'base_general']);
        Flujo::factory()->create(['tipo_prospecto_id' => $tipo2->id, 'origen' => 'banco_x']);
        Flujo::factory()->create(['tipo_prospecto_id' => $tipo2->id, 'origen' => 'referidos']);

        $this->assertEquals(3, Flujo::porTipo($tipo1->id)->count());
        $this->assertEquals(2, Flujo::porTipo($tipo2->id)->count());
    }

    public function test_scope_por_origen(): void
    {
        $tipo1 = TipoProspecto::factory()->create();
        $tipo2 = TipoProspecto::factory()->create();
        $tipo3 = TipoProspecto::factory()->create();
        $tipo4 = TipoProspecto::factory()->create();
        $tipo5 = TipoProspecto::factory()->create();

        Flujo::factory()->create(['tipo_prospecto_id' => $tipo1->id, 'origen' => 'banco_x']);
        Flujo::factory()->create(['tipo_prospecto_id' => $tipo2->id, 'origen' => 'banco_x']);
        Flujo::factory()->create(['tipo_prospecto_id' => $tipo3->id, 'origen' => 'banco_x']);
        Flujo::factory()->create(['tipo_prospecto_id' => $tipo4->id, 'origen' => 'campania_verano']);
        Flujo::factory()->create(['tipo_prospecto_id' => $tipo5->id, 'origen' => 'campania_verano']);

        $this->assertEquals(3, Flujo::porOrigen('banco_x')->count());
        $this->assertEquals(2, Flujo::porOrigen('campania_verano')->count());
    }

    public function test_find_or_create_for_prospecto_crea_nuevo_flujo(): void
    {
        $tipo = TipoProspecto::factory()->create(['nombre' => 'deuda_alta']);

        $flujo = Flujo::findOrCreateForProspecto($tipo->id, 'banco_x');

        $this->assertInstanceOf(Flujo::class, $flujo);
        $this->assertEquals($tipo->id, $flujo->tipo_prospecto_id);
        $this->assertEquals('banco_x', $flujo->origen);
        $this->assertTrue($flujo->activo);
        $this->assertStringContainsString('deuda_alta', $flujo->nombre);
        $this->assertStringContainsString('Banco_x', $flujo->nombre);
    }

    public function test_find_or_create_for_prospecto_encuentra_flujo_existente(): void
    {
        $tipo = TipoProspecto::factory()->create();
        $flujoExistente = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipo->id,
            'origen' => 'banco_x',
        ]);

        $flujo = Flujo::findOrCreateForProspecto($tipo->id, 'banco_x');

        $this->assertEquals($flujoExistente->id, $flujo->id);
        $this->assertEquals(1, Flujo::count());
    }

    public function test_generar_nombre_con_tipo_existente(): void
    {
        $tipo = TipoProspecto::factory()->create(['nombre' => 'deuda_media']);

        $flujo = Flujo::findOrCreateForProspecto($tipo->id, 'campania_verano');

        $this->assertEquals('Flujo deuda_media - Campania_verano', $flujo->nombre);
    }

    public function test_combinacion_tipo_origen_es_unica(): void
    {
        $tipo = TipoProspecto::factory()->create();

        Flujo::factory()->create([
            'tipo_prospecto_id' => $tipo->id,
            'origen' => 'banco_x',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Flujo::factory()->create([
            'tipo_prospecto_id' => $tipo->id,
            'origen' => 'banco_x',
        ]);
    }

    public function test_diferentes_origenes_mismo_tipo(): void
    {
        $tipo = TipoProspecto::factory()->create();

        $flujo1 = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipo->id,
            'origen' => 'banco_x',
        ]);

        $flujo2 = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipo->id,
            'origen' => 'campania_verano',
        ]);

        $this->assertNotEquals($flujo1->id, $flujo2->id);
        $this->assertEquals(2, Flujo::porTipo($tipo->id)->count());
    }

    public function test_mismo_origen_diferentes_tipos(): void
    {
        $tipo1 = TipoProspecto::factory()->create();
        $tipo2 = TipoProspecto::factory()->create();

        $flujo1 = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipo1->id,
            'origen' => 'banco_x',
        ]);

        $flujo2 = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipo2->id,
            'origen' => 'banco_x',
        ]);

        $this->assertNotEquals($flujo1->id, $flujo2->id);
        $this->assertEquals(2, Flujo::porOrigen('banco_x')->count());
    }

    public function test_etapas_ordenadas_por_orden(): void
    {
        $flujo = Flujo::factory()->create();

        EtapaFlujo::factory()->create(['flujo_id' => $flujo->id, 'orden' => 3]);
        EtapaFlujo::factory()->create(['flujo_id' => $flujo->id, 'orden' => 1]);
        EtapaFlujo::factory()->create(['flujo_id' => $flujo->id, 'orden' => 2]);

        $etapas = $flujo->etapas;

        $this->assertEquals(1, $etapas[0]->orden);
        $this->assertEquals(2, $etapas[1]->orden);
        $this->assertEquals(3, $etapas[2]->orden);
    }
}
