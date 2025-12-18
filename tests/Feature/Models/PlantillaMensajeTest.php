<?php

namespace Tests\Feature\Models;

use App\Models\Envio;
use App\Models\EtapaFlujo;
use App\Models\PlantillaMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlantillaMensajeTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_plantilla_mensaje(): void
    {
        $plantilla = PlantillaMensaje::factory()->create([
            'nombre' => 'Bienvenida',
            'tipo_canal' => 'email',
        ]);

        $this->assertDatabaseHas('plantillas_mensaje', [
            'id' => $plantilla->id,
            'nombre' => 'Bienvenida',
            'tipo_canal' => 'email',
        ]);
    }

    public function test_casts_variables_disponibles_como_array(): void
    {
        $variables = ['nombre', 'email', 'monto_deuda'];

        $plantilla = PlantillaMensaje::factory()->create([
            'variables_disponibles' => $variables,
        ]);

        $this->assertIsArray($plantilla->variables_disponibles);
        $this->assertEquals($variables, $plantilla->variables_disponibles);
    }

    public function test_casts_activo_como_boolean(): void
    {
        $plantilla = PlantillaMensaje::factory()->create(['activo' => true]);

        $this->assertIsBool($plantilla->activo);
        $this->assertTrue($plantilla->activo);
    }

    public function test_relacion_con_etapas(): void
    {
        $plantilla = PlantillaMensaje::factory()->create();
        EtapaFlujo::factory()->count(2)->create(['plantilla_mensaje_id' => $plantilla->id]);

        $this->assertCount(2, $plantilla->etapas);
    }

    public function test_relacion_con_envios(): void
    {
        $plantilla = PlantillaMensaje::factory()->create();
        Envio::factory()->create(['plantilla_mensaje_id' => $plantilla->id]);

        $this->assertCount(1, $plantilla->envios);
    }

    public function test_scope_activos(): void
    {
        PlantillaMensaje::factory()->create(['activo' => true]);
        PlantillaMensaje::factory()->create(['activo' => true]);
        PlantillaMensaje::factory()->create(['activo' => false]);

        $this->assertEquals(2, PlantillaMensaje::activos()->count());
    }

    public function test_scope_por_canal(): void
    {
        PlantillaMensaje::factory()->count(2)->create(['tipo_canal' => 'email']);
        PlantillaMensaje::factory()->count(3)->create(['tipo_canal' => 'sms']);
        PlantillaMensaje::factory()->create(['tipo_canal' => 'whatsapp']);

        $this->assertEquals(2, PlantillaMensaje::porCanal('email')->count());
        $this->assertEquals(3, PlantillaMensaje::porCanal('sms')->count());
        $this->assertEquals(1, PlantillaMensaje::porCanal('whatsapp')->count());
    }

    public function test_renderizar_reemplaza_variables(): void
    {
        $plantilla = PlantillaMensaje::factory()->create([
            'contenido' => 'Hola {nombre}, tu deuda es de {monto_deuda}.',
        ]);

        $datos = [
            'nombre' => 'Juan Pérez',
            'monto_deuda' => '150000',
        ];

        $resultado = $plantilla->renderizar($datos);

        $this->assertEquals('Hola Juan Pérez, tu deuda es de 150000.', $resultado);
    }

    public function test_renderizar_con_multiples_variables(): void
    {
        $plantilla = PlantillaMensaje::factory()->create([
            'contenido' => 'Hola {nombre}, tu email es {email} y debes {monto_deuda} desde {origen}.',
        ]);

        $datos = [
            'nombre' => 'Ana García',
            'email' => 'ana@example.com',
            'monto_deuda' => '250000',
            'origen' => 'banco_x',
        ];

        $resultado = $plantilla->renderizar($datos);

        $this->assertEquals('Hola Ana García, tu email es ana@example.com y debes 250000 desde banco_x.', $resultado);
    }

    public function test_renderizar_sin_variables(): void
    {
        $plantilla = PlantillaMensaje::factory()->create([
            'contenido' => 'Mensaje sin variables.',
        ]);

        $resultado = $plantilla->renderizar([]);

        $this->assertEquals('Mensaje sin variables.', $resultado);
    }

    public function test_renderizar_asunto_con_variables(): void
    {
        $plantilla = PlantillaMensaje::factory()->create([
            'asunto' => 'Deuda pendiente: {monto_deuda}',
        ]);

        $datos = ['monto_deuda' => '100000'];

        $resultado = $plantilla->renderizarAsunto($datos);

        $this->assertEquals('Deuda pendiente: 100000', $resultado);
    }

    public function test_renderizar_asunto_devuelve_null_sin_asunto(): void
    {
        $plantilla = PlantillaMensaje::factory()->sms()->create([
            'asunto' => null,
        ]);

        $resultado = $plantilla->renderizarAsunto(['nombre' => 'Test']);

        $this->assertNull($resultado);
    }

    public function test_get_variables_array_devuelve_variables_configuradas(): void
    {
        $variables = ['nombre', 'email', 'telefono'];

        $plantilla = PlantillaMensaje::factory()->create([
            'variables_disponibles' => $variables,
        ]);

        $this->assertEquals($variables, $plantilla->getVariablesArray());
    }

    public function test_get_variables_array_devuelve_default_sin_configuracion(): void
    {
        $plantilla = PlantillaMensaje::factory()->create([
            'variables_disponibles' => null,
        ]);

        $variablesDefault = $plantilla->getVariablesArray();

        $this->assertContains('nombre', $variablesDefault);
        $this->assertContains('email', $variablesDefault);
        $this->assertContains('monto_deuda', $variablesDefault);
        $this->assertContains('origen', $variablesDefault);
    }

    public function test_diferentes_canales(): void
    {
        $canales = ['email', 'sms', 'whatsapp'];

        foreach ($canales as $canal) {
            $plantilla = PlantillaMensaje::factory()->create(['tipo_canal' => $canal]);
            $this->assertEquals($canal, $plantilla->tipo_canal);
        }
    }

    public function test_plantilla_email_tiene_asunto(): void
    {
        $plantilla = PlantillaMensaje::factory()->email()->create();

        $this->assertNotNull($plantilla->asunto);
        $this->assertEquals('email', $plantilla->tipo_canal);
    }

    public function test_plantilla_sms_sin_asunto(): void
    {
        $plantilla = PlantillaMensaje::factory()->sms()->create();

        $this->assertNull($plantilla->asunto);
        $this->assertEquals('sms', $plantilla->tipo_canal);
    }

    public function test_plantilla_whatsapp_sin_asunto(): void
    {
        $plantilla = PlantillaMensaje::factory()->whatsapp()->create();

        $this->assertNull($plantilla->asunto);
        $this->assertEquals('whatsapp', $plantilla->tipo_canal);
    }
}
