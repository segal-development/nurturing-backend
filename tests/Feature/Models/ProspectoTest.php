<?php

namespace Tests\Feature\Models;

use App\Models\Envio;
use App\Models\Importacion;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProspectoTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_prospecto(): void
    {
        $tipo = TipoProspecto::factory()->create();
        $importacion = Importacion::factory()->create();

        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipo->id,
            'importacion_id' => $importacion->id,
            'nombre' => 'Juan Pérez',
            'email' => 'juan@example.com',
        ]);

        $this->assertDatabaseHas('prospectos', [
            'id' => $prospecto->id,
            'nombre' => 'Juan Pérez',
            'email' => 'juan@example.com',
        ]);
    }

    public function test_casts_monto_deuda_como_float(): void
    {
        $prospecto = Prospecto::factory()->create([
            'monto_deuda' => 150000.50,
        ]);

        $this->assertIsFloat($prospecto->monto_deuda);
        $this->assertEquals(150000.50, $prospecto->monto_deuda);
    }

    public function test_casts_fecha_ultimo_contacto_como_datetime(): void
    {
        $prospecto = Prospecto::factory()->create([
            'fecha_ultimo_contacto' => '2025-11-19 10:30:00',
        ]);

        $this->assertInstanceOf(\DateTime::class, $prospecto->fecha_ultimo_contacto);
    }

    public function test_casts_metadata_como_array(): void
    {
        $metadata = ['notas' => 'Cliente preferente', 'prioridad' => 'alta'];

        $prospecto = Prospecto::factory()->create([
            'metadata' => $metadata,
        ]);

        $this->assertIsArray($prospecto->metadata);
        $this->assertEquals($metadata, $prospecto->metadata);
    }

    public function test_relacion_con_importacion(): void
    {
        $importacion = Importacion::factory()->create();
        $prospecto = Prospecto::factory()->create(['importacion_id' => $importacion->id]);

        $this->assertInstanceOf(Importacion::class, $prospecto->importacion);
        $this->assertEquals($importacion->id, $prospecto->importacion->id);
    }

    public function test_relacion_con_tipo_prospecto(): void
    {
        $tipo = TipoProspecto::factory()->create();
        $prospecto = Prospecto::factory()->create(['tipo_prospecto_id' => $tipo->id]);

        $this->assertInstanceOf(TipoProspecto::class, $prospecto->tipoProspecto);
        $this->assertEquals($tipo->id, $prospecto->tipoProspecto->id);
    }

    public function test_relacion_con_prospectos_en_flujo(): void
    {
        $prospecto = Prospecto::factory()->create();
        $pef = ProspectoEnFlujo::factory()->create(['prospecto_id' => $prospecto->id]);

        $this->assertTrue($prospecto->prospectosEnFlujo->contains($pef));
        $this->assertCount(1, $prospecto->prospectosEnFlujo);
    }

    public function test_relacion_con_envios(): void
    {
        $prospecto = Prospecto::factory()->create();
        $envio = Envio::factory()->create(['prospecto_id' => $prospecto->id]);

        $this->assertTrue($prospecto->envios->contains($envio));
        $this->assertCount(1, $prospecto->envios);
    }

    public function test_scope_activos(): void
    {
        Prospecto::factory()->create(['estado' => 'activo']);
        Prospecto::factory()->create(['estado' => 'activo']);
        Prospecto::factory()->create(['estado' => 'inactivo']);

        $this->assertEquals(2, Prospecto::activos()->count());
    }

    public function test_scope_inactivos(): void
    {
        Prospecto::factory()->create(['estado' => 'activo']);
        Prospecto::factory()->create(['estado' => 'inactivo']);
        Prospecto::factory()->create(['estado' => 'inactivo']);

        $this->assertEquals(2, Prospecto::inactivos()->count());
    }

    public function test_scope_convertidos(): void
    {
        Prospecto::factory()->create(['estado' => 'activo']);
        Prospecto::factory()->create(['estado' => 'convertido']);
        Prospecto::factory()->create(['estado' => 'convertido']);

        $this->assertEquals(2, Prospecto::convertidos()->count());
    }

    public function test_scope_por_tipo(): void
    {
        $tipo1 = TipoProspecto::factory()->create();
        $tipo2 = TipoProspecto::factory()->create();

        Prospecto::factory()->count(3)->create(['tipo_prospecto_id' => $tipo1->id]);
        Prospecto::factory()->count(2)->create(['tipo_prospecto_id' => $tipo2->id]);

        $this->assertEquals(3, Prospecto::porTipo($tipo1->id)->count());
        $this->assertEquals(2, Prospecto::porTipo($tipo2->id)->count());
    }

    public function test_scope_por_origen(): void
    {
        $importacion1 = Importacion::factory()->create(['origen' => 'banco_x']);
        $importacion2 = Importacion::factory()->create(['origen' => 'campania_verano']);

        Prospecto::factory()->count(3)->create(['importacion_id' => $importacion1->id]);
        Prospecto::factory()->count(2)->create(['importacion_id' => $importacion2->id]);

        $this->assertEquals(3, Prospecto::porOrigen('banco_x')->count());
        $this->assertEquals(2, Prospecto::porOrigen('campania_verano')->count());
    }

    public function test_is_activo_devuelve_true_cuando_activo(): void
    {
        $prospecto = Prospecto::factory()->create(['estado' => 'activo']);

        $this->assertTrue($prospecto->isActivo());
    }

    public function test_is_activo_devuelve_false_cuando_no_activo(): void
    {
        $prospecto = Prospecto::factory()->create(['estado' => 'inactivo']);

        $this->assertFalse($prospecto->isActivo());
    }

    public function test_is_convertido_devuelve_true_cuando_convertido(): void
    {
        $prospecto = Prospecto::factory()->create(['estado' => 'convertido']);

        $this->assertTrue($prospecto->isConvertido());
    }

    public function test_is_convertido_devuelve_false_cuando_no_convertido(): void
    {
        $prospecto = Prospecto::factory()->create(['estado' => 'activo']);

        $this->assertFalse($prospecto->isConvertido());
    }

    public function test_get_origen_attribute_devuelve_origen_de_importacion(): void
    {
        $importacion = Importacion::factory()->create(['origen' => 'banco_x']);
        $prospecto = Prospecto::factory()->create(['importacion_id' => $importacion->id]);

        $this->assertEquals('banco_x', $prospecto->origen);
    }

    public function test_get_origen_attribute_devuelve_null_sin_importacion(): void
    {
        $prospecto = Prospecto::factory()->create(['importacion_id' => null]);

        $this->assertNull($prospecto->origen);
    }

    public function test_puede_tener_fila_excel(): void
    {
        $prospecto = Prospecto::factory()->create([
            'fila_excel' => 25,
        ]);

        $this->assertEquals(25, $prospecto->fila_excel);
    }

    public function test_telefono_formato_chileno(): void
    {
        $prospecto = Prospecto::factory()->create([
            'telefono' => '+56987654321',
        ]);

        $this->assertEquals('+56987654321', $prospecto->telefono);
    }

    public function test_estados_validos(): void
    {
        $estados = ['activo', 'inactivo', 'convertido'];

        foreach ($estados as $estado) {
            $prospecto = Prospecto::factory()->create(['estado' => $estado]);
            $this->assertEquals($estado, $prospecto->estado);
        }
    }

    public function test_metadata_puede_ser_null(): void
    {
        $prospecto = Prospecto::factory()->create(['metadata' => null]);

        $this->assertNull($prospecto->metadata);
    }

    public function test_puede_cambiar_de_estado(): void
    {
        $prospecto = Prospecto::factory()->create(['estado' => 'activo']);

        $prospecto->update(['estado' => 'convertido']);

        $this->assertEquals('convertido', $prospecto->fresh()->estado);
        $this->assertTrue($prospecto->fresh()->isConvertido());
    }

    public function test_puede_actualizar_monto_deuda(): void
    {
        $prospecto = Prospecto::factory()->create(['monto_deuda' => 100000]);

        $prospecto->update(['monto_deuda' => 150000]);

        $this->assertEquals(150000.0, $prospecto->fresh()->monto_deuda);
    }

    public function test_puede_actualizar_fecha_ultimo_contacto(): void
    {
        $prospecto = Prospecto::factory()->create();

        $nuevaFecha = now();
        $prospecto->update(['fecha_ultimo_contacto' => $nuevaFecha]);

        $this->assertEquals(
            $nuevaFecha->format('Y-m-d H:i:s'),
            $prospecto->fresh()->fecha_ultimo_contacto->format('Y-m-d H:i:s')
        );
    }
}
