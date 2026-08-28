<?php

namespace Tests\Feature\Controllers;

use App\Models\Envio;
use App\Models\EtapaFlujo;
use App\Models\Flujo;
use App\Models\Prospecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica el contrato JSON de EnvioController con el frontend:
 * el detalle debe ser plano (sin envoltorio error/data) y tanto index
 * como show deben exponer contenido, fecha_creacion y metadata
 * (destinatario, asunto, error) con los nombres que espera el frontend.
 */
class EnvioControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_show_devuelve_envio_plano_con_formato_frontend(): void
    {
        $prospecto = Prospecto::factory()->create();
        $flujo = Flujo::factory()->create();
        $etapa = EtapaFlujo::factory()->create(['flujo_id' => $flujo->id]);

        $envio = Envio::factory()->enviado()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_id' => $flujo->id,
            'etapa_flujo_id' => $etapa->id,
            'canal' => 'sms',
            'asunto' => null,
            'contenido_enviado' => 'Hola, este es el SMS enviado',
            'destinatario' => '+56912345678',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/envios/{$envio->id}");

        $response->assertStatus(200);

        $json = $response->json();

        // El detalle debe ser plano: sin envoltorio error/data en la raíz
        $this->assertArrayNotHasKey('error', $json);
        $this->assertArrayNotHasKey('data', $json);

        $response->assertJson([
            'id' => $envio->id,
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospecto->id,
            'estado' => 'enviado',
            'canal' => 'sms',
            'contenido' => 'Hola, este es el SMS enviado',
        ]);

        // El destinatario del envío (teléfono usado para SMS) tiene prioridad
        $this->assertEquals('+56912345678', $response->json('metadata.destinatario'));

        $this->assertNotNull($response->json('fecha_creacion'));
        $this->assertNotNull($response->json('fecha_enviado'));

        // Relaciones que el frontend consume (etapa.dia_envio, flujo.nombre, etc.)
        $this->assertNotNull($response->json('prospecto'));
        $this->assertNotNull($response->json('flujo'));
        $this->assertNotNull($response->json('etapa'));
        $this->assertEquals($etapa->id, $response->json('etapa.id'));
    }

    public function test_show_expone_error_de_metadata_para_envio_fallido(): void
    {
        $envio = Envio::factory()->create(['estado' => 'pendiente']);
        $envio->marcarComoFallido('X');

        $response = $this->actingAs($this->user)->getJson("/api/envios/{$envio->id}");

        $response->assertStatus(200);
        $this->assertEquals('fallido', $response->json('estado'));
        $this->assertEquals('X', $response->json('metadata.error'));
    }

    public function test_index_devuelve_contenido_del_envio(): void
    {
        Envio::factory()->create([
            'contenido_enviado' => 'Contenido real del envío',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/envios');

        $response->assertStatus(200);
        $this->assertEquals('Contenido real del envío', $response->json('data.0.contenido'));
    }

    public function test_destinatario_cae_a_email_del_prospecto_cuando_esta_vacio(): void
    {
        // La columna destinatario es NOT NULL: el valor "ausente" real es ''
        $prospecto = Prospecto::factory()->create(['email' => 'prospecto@example.com']);

        $envio = Envio::factory()->create([
            'prospecto_id' => $prospecto->id,
            'destinatario' => '',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/envios/{$envio->id}");

        $response->assertStatus(200);
        $this->assertEquals('prospecto@example.com', $response->json('metadata.destinatario'));
    }
}
