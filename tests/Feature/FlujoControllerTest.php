<?php

namespace Tests\Feature\Feature;

use App\Models\Flujo;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlujoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_puede_filtrar_flujos_por_origen(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();

        // Crear flujos con diferentes orígenes
        $flujoOrigen1 = Flujo::factory()->create([
            'origen' => 'Facebook',
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $flujoOrigen2 = Flujo::factory()->create([
            'origen' => 'Google',
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $flujoOrigen3 = Flujo::factory()->create([
            'origen' => 'Facebook',
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Sin filtro: debe devolver todos los flujos
        $response = $this->actingAs($this->user)->getJson('/api/flujos');
        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));

        // Con filtro por origen "Facebook": debe devolver solo 2 flujos
        $response = $this->actingAs($this->user)->getJson('/api/flujos?origen=Facebook');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals('Facebook', $response->json('data.0.origen'));
        $this->assertEquals('Facebook', $response->json('data.1.origen'));

        // Con filtro por origen "Google": debe devolver solo 1 flujo
        $response = $this->actingAs($this->user)->getJson('/api/flujos?origen=Google');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Google', $response->json('data.0.origen'));
    }

    public function test_devuelve_array_vacio_cuando_origen_no_tiene_flujos(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();

        // Crear flujos solo con origen "Facebook"
        Flujo::factory()->create([
            'origen' => 'Facebook',
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Buscar por un origen que no existe: debe devolver array vacío
        $response = $this->actingAs($this->user)->getJson('/api/flujos?origen=Instagram');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_puede_filtrar_flujos_por_origen_id(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();

        // Crear flujos con diferentes origen_id
        $flujo1 = Flujo::factory()->create([
            'origen_id' => 1,
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        $flujo2 = Flujo::factory()->create([
            'origen_id' => 2,
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);

        // Con filtro por origen_id=1: debe devolver solo 1 flujo
        $response = $this->actingAs($this->user)->getJson('/api/flujos?origen_id=1');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(1, $response->json('data.0.origen_id'));
    }
}
