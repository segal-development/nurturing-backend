<?php

namespace Tests\Feature;

use App\Models\Importacion;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportacionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Permission::create(['name' => 'ver prospectos']);
        Permission::create(['name' => 'crear prospectos']);

        $role = Role::create(['name' => 'admin']);
        $role->givePermissionTo(['ver prospectos', 'crear prospectos']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_puede_listar_importaciones(): void
    {
        Importacion::factory()->count(5)->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/importaciones');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'nombre_archivo',
                        'origen',
                        'total_registros',
                        'registros_exitosos',
                        'registros_fallidos',
                        'estado',
                        'fecha_importacion',
                    ],
                ],
                'meta',
            ]);
    }

    public function test_puede_filtrar_importaciones_por_estado(): void
    {
        Importacion::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'estado' => 'completado',
        ]);
        Importacion::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'estado' => 'fallido',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/importaciones?estado=completado');

        $response->assertStatus(200);
        $this->assertEquals(3, count($response->json('data')));
    }

    public function test_puede_importar_prospectos_desde_excel(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();

        $csvContent = "nombre,email,telefono,monto_deuda\n";
        $csvContent .= "Juan Pérez,juan@example.com,1234567890,1000.50\n";
        $csvContent .= "María García,maria@example.com,0987654321,2000.75\n";

        $file = UploadedFile::fake()->createWithContent(
            'prospectos.csv',
            $csvContent
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/importaciones', [
                'archivo' => $file,
                'origen' => 'manual',
                'tipo_prospecto_id' => $tipoProspecto->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'mensaje',
                'data',
                'resumen' => [
                    'total_registros',
                    'registros_exitosos',
                    'registros_fallidos',
                ],
            ]);

        $this->assertDatabaseHas('importaciones', [
            'origen' => 'manual',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_validacion_falla_sin_archivo(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/importaciones', [
                'origen' => 'manual',
                'tipo_prospecto_id' => $tipoProspecto->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('archivo');
    }

    public function test_validacion_falla_con_tipo_archivo_invalido(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();

        $file = UploadedFile::fake()->create('documento.pdf', 100);

        $response = $this->actingAs($this->user)
            ->postJson('/api/importaciones', [
                'archivo' => $file,
                'origen' => 'manual',
                'tipo_prospecto_id' => $tipoProspecto->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('archivo');
    }

    public function test_puede_ver_detalle_de_importacion(): void
    {
        $importacion = Importacion::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/importaciones/{$importacion->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'nombre_archivo',
                    'origen',
                    'estado',
                    'user',
                ],
            ]);
    }

    public function test_no_puede_actualizar_importacion(): void
    {
        $importacion = Importacion::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/importaciones/{$importacion->id}", [
                'estado' => 'completado',
            ]);

        $response->assertStatus(403)
            ->assertJsonFragment([
                'mensaje' => 'Las importaciones no se pueden modificar',
            ]);
    }

    public function test_puede_eliminar_importacion_sin_prospectos(): void
    {
        $importacion = Importacion::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $importacionId = $importacion->id;

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/importaciones/{$importacionId}");

        $response->assertStatus(200);

        $this->assertNull(Importacion::find($importacionId));
    }

    public function test_no_puede_eliminar_importacion_con_prospectos(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        $importacion = Importacion::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $importacionId = $importacion->id;

        Prospecto::factory()->create([
            'importacion_id' => $importacionId,
            'tipo_prospecto_id' => $tipoProspecto->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/importaciones/{$importacionId}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'mensaje' => 'No se puede eliminar una importación con prospectos asociados',
            ]);

        $this->assertNotNull(Importacion::find($importacionId));
    }

    public function test_requiere_autenticacion(): void
    {
        $response = $this->getJson('/api/importaciones');

        $response->assertStatus(401);
    }

    public function test_requiere_permiso_para_crear_importacion(): void
    {
        $userSinPermiso = User::factory()->create();

        $tipoProspecto = TipoProspecto::factory()->create();
        $file = UploadedFile::fake()->create('prospectos.xlsx');

        $response = $this->actingAs($userSinPermiso)
            ->postJson('/api/importaciones', [
                'archivo' => $file,
                'origen' => 'manual',
                'tipo_prospecto_id' => $tipoProspecto->id,
            ]);

        $response->assertStatus(403);
    }
}
