<?php

namespace Tests\Feature\Models;

use App\Models\Flujo;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TipoProspectoTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_tipo_prospecto(): void
    {
        $tipo = TipoProspecto::create([
            'nombre' => 'deuda_baja',
            'descripcion' => 'Prospectos con deuda baja',
            'monto_min' => 0.01,
            'monto_max' => 50000.00,
            'orden' => 1,
            'activo' => true,
        ]);

        $this->assertDatabaseHas('tipo_prospecto', [
            'nombre' => 'deuda_baja',
            'monto_min' => 0.01,
            'monto_max' => 50000.00,
        ]);
    }

    public function test_casts_monto_min_y_max_como_decimal(): void
    {
        $tipo = TipoProspecto::create([
            'nombre' => 'test',
            'monto_min' => '100.50',
            'monto_max' => '5000.75',
        ]);

        $this->assertIsFloat($tipo->monto_min);
        $this->assertIsFloat($tipo->monto_max);
        $this->assertEquals(100.50, $tipo->monto_min);
        $this->assertEquals(5000.75, $tipo->monto_max);
    }

    public function test_casts_activo_como_boolean(): void
    {
        $tipo = TipoProspecto::create([
            'nombre' => 'test',
            'activo' => 1,
        ]);

        $this->assertIsBool($tipo->activo);
        $this->assertTrue($tipo->activo);
    }

    public function test_relacion_con_prospectos(): void
    {
        $tipo = TipoProspecto::factory()->create();
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipo->id,
        ]);

        $this->assertTrue($tipo->prospectos->contains($prospecto));
        $this->assertEquals($tipo->id, $prospecto->tipoProspecto->id);
    }

    public function test_relacion_con_flujos(): void
    {
        $tipo = TipoProspecto::factory()->create();
        $flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipo->id,
        ]);

        $this->assertTrue($tipo->flujos->contains($flujo));
        $this->assertEquals($tipo->id, $flujo->tipoProspecto->id);
    }

    public function test_scope_activos(): void
    {
        TipoProspecto::factory()->create(['activo' => true, 'nombre' => 'activo1']);
        TipoProspecto::factory()->create(['activo' => true, 'nombre' => 'activo2']);
        TipoProspecto::factory()->create(['activo' => false, 'nombre' => 'inactivo']);

        $activos = TipoProspecto::activos()->get();

        $this->assertCount(2, $activos);
        $this->assertTrue($activos->every(fn ($tipo) => $tipo->activo === true));
    }

    public function test_scope_ordenados(): void
    {
        TipoProspecto::factory()->create(['orden' => 3, 'nombre' => 'tercero']);
        TipoProspecto::factory()->create(['orden' => 1, 'nombre' => 'primero']);
        TipoProspecto::factory()->create(['orden' => 2, 'nombre' => 'segundo']);

        $ordenados = TipoProspecto::ordenados()->get();

        $this->assertEquals('primero', $ordenados[0]->nombre);
        $this->assertEquals('segundo', $ordenados[1]->nombre);
        $this->assertEquals('tercero', $ordenados[2]->nombre);
    }

    public function test_en_rango_devuelve_true_cuando_monto_esta_dentro(): void
    {
        $tipo = TipoProspecto::factory()->create([
            'monto_min' => 1000.00,
            'monto_max' => 5000.00,
        ]);

        $this->assertTrue($tipo->enRango(3000.00));
        $this->assertTrue($tipo->enRango(1000.00)); // Límite inferior
        $this->assertTrue($tipo->enRango(5000.00)); // Límite superior
    }

    public function test_en_rango_devuelve_false_cuando_monto_esta_fuera(): void
    {
        $tipo = TipoProspecto::factory()->create([
            'monto_min' => 1000.00,
            'monto_max' => 5000.00,
        ]);

        $this->assertFalse($tipo->enRango(999.99));
        $this->assertFalse($tipo->enRango(5000.01));
        $this->assertFalse($tipo->enRango(0));
        $this->assertFalse($tipo->enRango(10000.00));
    }

    public function test_en_rango_con_monto_min_null(): void
    {
        $tipo = TipoProspecto::factory()->create([
            'monto_min' => null,
            'monto_max' => 5000.00,
        ]);

        $this->assertTrue($tipo->enRango(0));
        $this->assertTrue($tipo->enRango(2500.00));
        $this->assertTrue($tipo->enRango(5000.00));
        $this->assertFalse($tipo->enRango(5000.01));
    }

    public function test_en_rango_con_monto_max_null(): void
    {
        $tipo = TipoProspecto::factory()->create([
            'monto_min' => 200000.00,
            'monto_max' => null,
        ]);

        $this->assertTrue($tipo->enRango(200000.00));
        $this->assertTrue($tipo->enRango(500000.00));
        $this->assertTrue($tipo->enRango(999999999.99));
        $this->assertFalse($tipo->enRango(199999.99));
    }

    public function test_en_rango_devuelve_false_cuando_ambos_son_null(): void
    {
        $tipo = TipoProspecto::factory()->create([
            'monto_min' => null,
            'monto_max' => null,
        ]);

        $this->assertFalse($tipo->enRango(0));
        $this->assertFalse($tipo->enRango(1000.00));
    }

    public function test_find_by_monto_encuentra_tipo_correcto(): void
    {
        TipoProspecto::factory()->create([
            'nombre' => 'deuda_0',
            'monto_min' => 0,
            'monto_max' => 0,
            'orden' => 1,
            'activo' => true,
        ]);

        TipoProspecto::factory()->create([
            'nombre' => 'deuda_baja',
            'monto_min' => 0.01,
            'monto_max' => 50000.00,
            'orden' => 2,
            'activo' => true,
        ]);

        TipoProspecto::factory()->create([
            'nombre' => 'deuda_media',
            'monto_min' => 50000.01,
            'monto_max' => 200000.00,
            'orden' => 3,
            'activo' => true,
        ]);

        $tipoEncontrado = TipoProspecto::findByMonto(75000.00);

        $this->assertNotNull($tipoEncontrado);
        $this->assertEquals('deuda_media', $tipoEncontrado->nombre);
    }

    public function test_find_by_monto_devuelve_null_cuando_no_encuentra(): void
    {
        TipoProspecto::factory()->create([
            'monto_min' => 1000.00,
            'monto_max' => 5000.00,
            'activo' => true,
        ]);

        $tipoEncontrado = TipoProspecto::findByMonto(10000.00);

        $this->assertNull($tipoEncontrado);
    }

    public function test_find_by_monto_ignora_tipos_inactivos(): void
    {
        TipoProspecto::factory()->create([
            'nombre' => 'inactivo',
            'monto_min' => 1000.00,
            'monto_max' => 5000.00,
            'activo' => false,
        ]);

        TipoProspecto::factory()->create([
            'nombre' => 'activo',
            'monto_min' => 1000.00,
            'monto_max' => 5000.00,
            'activo' => true,
        ]);

        $tipoEncontrado = TipoProspecto::findByMonto(3000.00);

        $this->assertEquals('activo', $tipoEncontrado->nombre);
    }

    public function test_find_by_monto_respeta_orden(): void
    {
        TipoProspecto::factory()->create([
            'nombre' => 'segundo',
            'monto_min' => 0.01,
            'monto_max' => 10000.00,
            'orden' => 2,
            'activo' => true,
        ]);

        TipoProspecto::factory()->create([
            'nombre' => 'primero',
            'monto_min' => 0.01,
            'monto_max' => 10000.00,
            'orden' => 1,
            'activo' => true,
        ]);

        $tipoEncontrado = TipoProspecto::findByMonto(5000.00);

        $this->assertEquals('primero', $tipoEncontrado->nombre);
    }

    public function test_find_by_monto_casos_limite(): void
    {
        $tipo = TipoProspecto::factory()->create([
            'nombre' => 'test',
            'monto_min' => 50000.00,
            'monto_max' => 200000.00,
            'activo' => true,
        ]);

        $this->assertEquals('test', TipoProspecto::findByMonto(50000.00)->nombre);
        $this->assertEquals('test', TipoProspecto::findByMonto(200000.00)->nombre);
        $this->assertNull(TipoProspecto::findByMonto(49999.99));
        $this->assertNull(TipoProspecto::findByMonto(200000.01));
    }

    public function test_find_by_monto_con_deuda_cero(): void
    {
        TipoProspecto::factory()->create([
            'nombre' => 'deuda_0',
            'monto_min' => 0,
            'monto_max' => 0,
            'orden' => 1,
            'activo' => true,
        ]);

        $tipoEncontrado = TipoProspecto::findByMonto(0);

        $this->assertNotNull($tipoEncontrado);
        $this->assertEquals('deuda_0', $tipoEncontrado->nombre);
    }

    public function test_find_by_monto_con_monto_muy_alto(): void
    {
        TipoProspecto::factory()->create([
            'nombre' => 'deuda_alta',
            'monto_min' => 200000.01,
            'monto_max' => null,
            'activo' => true,
        ]);

        $tipoEncontrado = TipoProspecto::findByMonto(999999999.99);

        $this->assertNotNull($tipoEncontrado);
        $this->assertEquals('deuda_alta', $tipoEncontrado->nombre);
    }
}
