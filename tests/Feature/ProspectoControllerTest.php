<?php

namespace Tests\Feature;

use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProspectoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'ver prospectos']);
        Permission::create(['name' => 'crear prospectos']);
        Permission::create(['name' => 'editar prospectos']);
        Permission::create(['name' => 'eliminar prospectos']);

        $role = Role::create(['name' => 'admin']);
        $role->givePermissionTo(['ver prospectos', 'crear prospectos', 'editar prospectos', 'eliminar prospectos']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_puede_listar_prospectos(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        Prospecto::factory()->count(5)->create(['tipo_prospecto_id' => $tipoProspecto->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/prospectos');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'nombre',
                        'email',
                        'telefono',
                        'estado',
                        'monto_deuda',
                    ],
                ],
                'meta',
            ]);
    }

    public function test_puede_filtrar_prospectos_por_estado(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        Prospecto::factory()->count(3)->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'estado' => 'activo',
        ]);
        Prospecto::factory()->count(2)->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'estado' => 'inactivo',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/prospectos?estado=activo');

        $response->assertStatus(200);
        $this->assertEquals(3, count($response->json('data')));
    }

    public function test_puede_buscar_prospectos(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'nombre' => 'Juan Pérez',
            'email' => 'juan@example.com',
        ]);
        Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'nombre' => 'María García',
            'email' => 'maria@example.com',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/prospectos?search=Juan');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
    }

    public function test_puede_crear_prospecto(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();

        $data = [
            'nombre' => 'Test Prospecto',
            'email' => 'test@example.com',
            'telefono' => '1234567890',
            'tipo_prospecto_id' => $tipoProspecto->id,
            'monto_deuda' => 1000.50,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/prospectos', $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'mensaje',
                'data' => [
                    'id',
                    'nombre',
                    'email',
                ],
            ]);

        $this->assertDatabaseHas('prospectos', [
            'email' => 'test@example.com',
            'nombre' => 'Test Prospecto',
        ]);
    }

    public function test_no_puede_crear_prospecto_con_email_duplicado(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'email' => 'duplicado@example.com',
        ]);

        $data = [
            'nombre' => 'Test Prospecto',
            'email' => 'duplicado@example.com',
            'tipo_prospecto_id' => $tipoProspecto->id,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/prospectos', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_puede_ver_detalle_de_prospecto(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/prospectos/{$prospecto->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'nombre',
                    'email',
                    'tipo_prospecto',
                ],
            ]);
    }

    public function test_puede_actualizar_prospecto(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
        ]);

        $data = [
            'nombre' => 'Nombre Actualizado',
            'monto_deuda' => 2000.00,
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/prospectos/{$prospecto->id}", $data);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'nombre' => 'Nombre Actualizado',
            ]);

        $this->assertDatabaseHas('prospectos', [
            'id' => $prospecto->id,
            'nombre' => 'Nombre Actualizado',
        ]);
    }

    public function test_puede_eliminar_prospecto(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/prospectos/{$prospecto->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('prospectos', [
            'id' => $prospecto->id,
        ]);
    }

    public function test_no_puede_eliminar_prospecto_en_flujo(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
        ]);
        ProspectoEnFlujo::factory()->create([
            'prospecto_id' => $prospecto->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/prospectos/{$prospecto->id}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'mensaje' => 'No se puede eliminar un prospecto que está en un flujo activo',
            ]);

        $this->assertDatabaseHas('prospectos', [
            'id' => $prospecto->id,
        ]);
    }

    public function test_puede_obtener_estadisticas(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        Prospecto::factory()->count(5)->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'estado' => 'activo',
        ]);
        Prospecto::factory()->count(3)->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'estado' => 'convertido',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/prospectos/estadisticas');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'total_prospectos',
                    'por_estado',
                    'por_tipo',
                    'monto_total_deuda',
                ],
            ]);
    }

    public function test_requiere_autenticacion(): void
    {
        $response = $this->getJson('/api/prospectos');

        $response->assertStatus(401);
    }
}
