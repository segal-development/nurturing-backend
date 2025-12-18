<?php

namespace Tests\Unit\Models;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlujoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear tipos de prospecto necesarios
        TipoProspecto::factory()->create([
            'nombre' => 'Deuda Baja',
            'monto_min' => 0,
            'monto_max' => 699999.99,
        ]);

        TipoProspecto::factory()->create([
            'nombre' => 'Deuda Media',
            'monto_min' => 700000,
            'monto_max' => 1499999.99,
        ]);
    }

    /** @test */
    public function puede_crear_un_flujo_con_datos_minimos(): void
    {
        $tipoProspecto = TipoProspecto::first();
        $user = User::factory()->create();

        $flujo = Flujo::create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'nombre' => 'Flujo Test',
            'user_id' => $user->id,
            'origen' => 'web',
            'activo' => true,
        ]);

        $this->assertNotNull($flujo);
        $this->assertEquals('Flujo Test', $flujo->nombre);
        $this->assertTrue($flujo->activo);
        $this->assertEquals($tipoProspecto->id, $flujo->tipo_prospecto_id);
    }

    /** @test */
    public function puede_crear_un_flujo_con_todos_los_datos(): void
    {
        $tipoProspecto = TipoProspecto::first();
        $user = User::factory()->create();

        $flujo = Flujo::create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'nombre' => 'Flujo Completo',
            'descripcion' => 'Descripción del flujo',
            'canal_envio' => 'email',
            'activo' => true,
            'user_id' => $user->id,
            'origen' => 'web',
            'metadata' => ['key' => 'value'],
            'config_visual' => ['x' => 100, 'y' => 200],
            'config_structure' => ['stages' => [], 'branches' => []],
        ]);

        $this->assertEquals('Flujo Completo', $flujo->nombre);
        $this->assertEquals('Descripción del flujo', $flujo->descripcion);
        $this->assertEquals('email', $flujo->canal_envio);
        $this->assertEquals('web', $flujo->origen);
        $this->assertEquals(['key' => 'value'], $flujo->metadata);
        $this->assertEquals(['x' => 100, 'y' => 200], $flujo->config_visual);
        $this->assertIsArray($flujo->config_structure);
    }

    /** @test */
    public function puede_marcar_un_flujo_como_inactivo(): void
    {
        $flujo = Flujo::factory()->create(['activo' => true]);

        $flujo->update(['activo' => false]);

        $this->assertFalse($flujo->fresh()->activo);
    }

    /** @test */
    public function scope_activos_filtra_correctamente(): void
    {
        Flujo::factory()->count(3)->create(['activo' => true]);
        Flujo::factory()->count(2)->create(['activo' => false]);

        $activos = Flujo::activos()->get();

        $this->assertCount(3, $activos);
        $this->assertTrue($activos->every(fn ($f) => $f->activo));
    }

    /** @test */
    public function scope_por_tipo_filtra_correctamente(): void
    {
        $tipo1 = TipoProspecto::first();
        $tipo2 = TipoProspecto::skip(1)->first();

        Flujo::factory()->count(3)->create(['tipo_prospecto_id' => $tipo1->id]);
        Flujo::factory()->count(2)->create(['tipo_prospecto_id' => $tipo2->id]);

        $flujosTipo1 = Flujo::porTipo($tipo1->id)->get();

        $this->assertCount(3, $flujosTipo1);
        $this->assertTrue($flujosTipo1->every(fn ($f) => $f->tipo_prospecto_id === $tipo1->id));
    }

    /** @test */
    public function scope_por_origen_filtra_correctamente(): void
    {
        Flujo::factory()->count(3)->create(['origen' => 'web']);
        Flujo::factory()->count(2)->create(['origen' => 'mobile']);

        $flujosWeb = Flujo::porOrigen('web')->get();

        $this->assertCount(3, $flujosWeb);
        $this->assertTrue($flujosWeb->every(fn ($f) => $f->origen === 'web'));
    }

    /** @test */
    public function find_or_create_for_prospecto_crea_flujo_si_no_existe(): void
    {
        User::factory()->create(); // Crear usuario para asignar al flujo
        $tipoProspecto = TipoProspecto::first();

        $flujo = Flujo::findOrCreateForProspecto($tipoProspecto->id, 'web');

        $this->assertNotNull($flujo);
        $this->assertEquals($tipoProspecto->id, $flujo->tipo_prospecto_id);
        $this->assertEquals('web', $flujo->origen);
        $this->assertTrue($flujo->activo);
        $this->assertStringContainsString('Deuda Baja', $flujo->nombre);
        $this->assertStringContainsString('Web', $flujo->nombre);
    }

    /** @test */
    public function find_or_create_for_prospecto_retorna_existente_si_ya_existe(): void
    {
        User::factory()->create(); // Crear usuario para asignar al flujo
        $tipoProspecto = TipoProspecto::first();

        $flujoExistente = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'origen' => 'web',
        ]);

        $flujo = Flujo::findOrCreateForProspecto($tipoProspecto->id, 'web');

        $this->assertEquals($flujoExistente->id, $flujo->id);
    }

    /** @test */
    public function generar_nombre_crea_nombre_correcto(): void
    {
        $tipoProspecto = TipoProspecto::first();

        // Usar reflexión para acceder al método protegido
        $reflection = new \ReflectionClass(Flujo::class);
        $method = $reflection->getMethod('generarNombre');
        $method->setAccessible(true);

        $nombre = $method->invokeArgs(null, [$tipoProspecto->id, 'web']);

        $this->assertEquals('Flujo Deuda Baja - Web', $nombre);
    }

    /** @test */
    public function generar_nombre_maneja_tipo_inexistente(): void
    {
        // Usar reflexión para acceder al método protegido
        $reflection = new \ReflectionClass(Flujo::class);
        $method = $reflection->getMethod('generarNombre');
        $method->setAccessible(true);

        $nombre = $method->invokeArgs(null, [999999, 'mobile']);

        $this->assertEquals('Flujo Tipo 999999 - Mobile', $nombre);
    }

    /** @test */
    public function flujo_data_combina_config_visual_y_structure(): void
    {
        $flujo = Flujo::factory()->create([
            'config_visual' => ['x' => 100, 'y' => 200],
            'config_structure' => ['stages' => ['stage1'], 'branches' => ['branch1']],
        ]);

        $flujoData = $flujo->flujo_data;

        $this->assertIsArray($flujoData);
        $this->assertEquals(100, $flujoData['x']);
        $this->assertEquals(200, $flujoData['y']);
        $this->assertEquals(['stage1'], $flujoData['stages']);
        $this->assertEquals(['branch1'], $flujoData['branches']);
    }

    /** @test */
    public function flujo_data_maneja_configs_null(): void
    {
        $flujo = Flujo::factory()->create([
            'config_visual' => null,
            'config_structure' => null,
        ]);

        $flujoData = $flujo->flujo_data;

        $this->assertIsArray($flujoData);
        $this->assertEmpty($flujoData);
    }

    /** @test */
    public function flujo_data_maneja_configs_vacias(): void
    {
        $flujo = Flujo::factory()->create([
            'config_visual' => [],
            'config_structure' => [],
        ]);

        $flujoData = $flujo->flujo_data;

        $this->assertIsArray($flujoData);
        $this->assertEmpty($flujoData);
    }

    /** @test */
    public function relacion_con_tipo_prospecto_funciona(): void
    {
        $tipoProspecto = TipoProspecto::first();
        $flujo = Flujo::factory()->create(['tipo_prospecto_id' => $tipoProspecto->id]);

        $this->assertInstanceOf(TipoProspecto::class, $flujo->tipoProspecto);
        $this->assertEquals($tipoProspecto->id, $flujo->tipoProspecto->id);
    }

    /** @test */
    public function relacion_con_user_funciona(): void
    {
        $user = User::factory()->create();
        $flujo = Flujo::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $flujo->user);
        $this->assertEquals($user->id, $flujo->user->id);
    }

    /** @test */
    public function relacion_con_ejecuciones_funciona(): void
    {
        $flujo = Flujo::factory()->create();
        FlujoEjecucion::factory()->count(3)->create(['flujo_id' => $flujo->id]);

        $this->assertCount(3, $flujo->ejecuciones);
        $this->assertInstanceOf(FlujoEjecucion::class, $flujo->ejecuciones->first());
    }

    /** @test */
    public function casts_convierten_correctamente_los_tipos(): void
    {
        $flujo = Flujo::factory()->create([
            'activo' => '1',
            'metadata' => ['test' => 'value'],
            'config_visual' => ['x' => 100],
            'config_structure' => ['stages' => []],
        ]);

        $this->assertTrue($flujo->activo);
        $this->assertIsArray($flujo->metadata);
        $this->assertIsArray($flujo->config_visual);
        $this->assertIsArray($flujo->config_structure);
    }

    /** @test */
    public function metadata_puede_almacenar_datos_complejos(): void
    {
        $metadata = [
            'version' => '1.0',
            'configuracion' => [
                'timeout' => 300,
                'reintentos' => 3,
            ],
            'tags' => ['importante', 'activo'],
        ];

        $flujo = Flujo::factory()->create(['metadata' => $metadata]);

        $this->assertEquals($metadata, $flujo->fresh()->metadata);
    }

    /** @test */
    public function puede_actualizar_config_visual(): void
    {
        $flujo = Flujo::factory()->create(['config_visual' => ['x' => 100, 'y' => 200]]);

        $flujo->update(['config_visual' => ['x' => 150, 'y' => 250, 'zoom' => 1.5]]);

        $updatedVisual = $flujo->fresh()->config_visual;
        $this->assertEquals(150, $updatedVisual['x']);
        $this->assertEquals(250, $updatedVisual['y']);
        $this->assertEquals(1.5, $updatedVisual['zoom']);
    }

    /** @test */
    public function puede_actualizar_config_structure(): void
    {
        $flujo = Flujo::factory()->create([
            'config_structure' => [
                'stages' => ['stage1'],
                'branches' => [],
            ],
        ]);

        $flujo->update([
            'config_structure' => [
                'stages' => ['stage1', 'stage2'],
                'branches' => ['branch1'],
            ],
        ]);

        $updatedStructure = $flujo->fresh()->config_structure;
        $this->assertCount(2, $updatedStructure['stages']);
        $this->assertCount(1, $updatedStructure['branches']);
    }

    /** @test */
    public function eliminar_flujo_no_elimina_tipo_prospecto(): void
    {
        $tipoProspecto = TipoProspecto::first();
        $flujo = Flujo::factory()->create(['tipo_prospecto_id' => $tipoProspecto->id]);

        $flujo->delete();

        $this->assertNotNull(TipoProspecto::find($tipoProspecto->id));
    }

    /** @test */
    public function eliminar_flujo_no_elimina_usuario(): void
    {
        $user = User::factory()->create();
        $flujo = Flujo::factory()->create(['user_id' => $user->id]);

        $flujo->delete();

        $this->assertNotNull(User::find($user->id));
    }
}
