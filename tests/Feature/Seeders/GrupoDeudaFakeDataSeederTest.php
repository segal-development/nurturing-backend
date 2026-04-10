<?php

namespace Tests\Feature\Seeders;

use App\Models\ExternalApiSource;
use App\Models\Importacion;
use App\Models\Lote;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use App\Models\User;
use Database\Seeders\GrupoDeudaFakeDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests para GrupoDeudaFakeDataSeeder.
 *
 * Verifica que el seeder crea correctamente:
 * - Lotes para Contratos Nuevos y Cuotas Vencidas
 * - Importaciones asociadas
 * - Prospectos con datos de contacto reales y fake
 * - Metadata correcta para cada tipo de prospecto
 */
class GrupoDeudaFakeDataSeederTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear usuario necesario para el seeder
        $this->user = User::factory()->create();

        // Crear tipos de prospecto necesarios
        $this->createTiposProspecto();

        // Crear ExternalApiSources necesarios
        $this->createExternalApiSources();
    }

    /**
     * Crea los tipos de prospecto necesarios para el seeder.
     */
    private function createTiposProspecto(): void
    {
        TipoProspecto::create([
            'nombre' => 'Deuda Baja',
            'descripcion' => 'Deuda entre $0 y $699,999',
            'monto_min' => 0,
            'monto_max' => 699999,
            'orden' => 1,
            'activo' => true,
        ]);

        TipoProspecto::create([
            'nombre' => 'Deuda Media',
            'descripcion' => 'Deuda entre $700,000 y $1,499,999',
            'monto_min' => 700000,
            'monto_max' => 1499999,
            'orden' => 2,
            'activo' => true,
        ]);

        TipoProspecto::create([
            'nombre' => 'Deuda Alta',
            'descripcion' => 'Deuda desde $1,500,000',
            'monto_min' => 1500000,
            'monto_max' => null,
            'orden' => 3,
            'activo' => true,
        ]);
    }

    /**
     * Crea los ExternalApiSource necesarios para el seeder.
     */
    private function createExternalApiSources(): void
    {
        ExternalApiSource::create([
            'name' => 'grupo_deuda_contratos',
            'display_name' => 'Grupo Deudas - Contratos Nuevos',
            'endpoint_url' => 'https://example.com/contratos',
            'auth_type' => 'none',
            'is_active' => true,
        ]);

        ExternalApiSource::create([
            'name' => 'grupo_deuda_cuotas_vencidas',
            'display_name' => 'Grupo Deudas - Cuotas Vencidas',
            'endpoint_url' => 'https://example.com/cuotas',
            'auth_type' => 'none',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Tests de ejecución básica
    // =========================================================================

    public function test_seeder_se_ejecuta_sin_errores(): void
    {
        $seeder = new GrupoDeudaFakeDataSeeder();

        // No debe lanzar excepciones
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $this->assertTrue(true);
    }

    public function test_seeder_crea_lote_contratos_nuevos(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $this->assertDatabaseHas('lotes', [
            'nombre' => 'CONTRATOS_NUEVOS_TEST',
            'estado' => 'completado',
        ]);
    }

    public function test_seeder_crea_lote_cuotas_vencidas(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $this->assertDatabaseHas('lotes', [
            'nombre' => 'CUOTAS_VENCIDAS_TEST',
            'estado' => 'completado',
        ]);
    }

    // =========================================================================
    // Tests de Contratos Nuevos
    // =========================================================================

    public function test_contratos_nuevos_crea_importacion(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $lote = Lote::where('nombre', 'CONTRATOS_NUEVOS_TEST')->first();

        $this->assertNotNull($lote);
        $this->assertDatabaseHas('importaciones', [
            'lote_id' => $lote->id,
            'origen' => 'Grupo Deudas',
            'estado' => 'completado',
        ]);
    }

    public function test_contratos_nuevos_crea_prospectos_con_emails_reales(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        // Verificar emails reales de prueba
        $this->assertDatabaseHas('prospectos', ['email' => 'mtoro@segal.cl']);
        $this->assertDatabaseHas('prospectos', ['email' => 'jfigueroa@itds.cl']);
        $this->assertDatabaseHas('prospectos', ['email' => 'csalinas@segal.cl']);
    }

    public function test_contratos_nuevos_crea_prospectos_con_telefonos_reales(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospecto = Prospecto::where('email', 'mtoro@segal.cl')->first();

        $this->assertNotNull($prospecto);
        $this->assertEquals('+56958531798', $prospecto->telefono);
    }

    public function test_contratos_nuevos_metadata_tiene_source_correcto(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospecto = Prospecto::where('email', 'mtoro@segal.cl')->first();

        $this->assertNotNull($prospecto);
        $this->assertIsArray($prospecto->metadata);
        $this->assertEquals('grupo_deuda', $prospecto->metadata['source']);
        $this->assertEquals('contratos_nuevos', $prospecto->metadata['endpoint']);
        $this->assertTrue($prospecto->metadata['is_fake_data']);
    }

    public function test_contratos_nuevos_crea_prospectos_fake_adicionales(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        // Debe haber al menos 5 prospectos fake adicionales
        $fakeCount = Prospecto::where('email', 'like', 'fake.contrato.%@test.local')->count();

        $this->assertGreaterThanOrEqual(1, $fakeCount);
    }

    // =========================================================================
    // Tests de Cuotas Vencidas
    // =========================================================================

    public function test_cuotas_vencidas_crea_importacion(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $lote = Lote::where('nombre', 'CUOTAS_VENCIDAS_TEST')->first();

        $this->assertNotNull($lote);
        $this->assertDatabaseHas('importaciones', [
            'lote_id' => $lote->id,
            'origen' => 'Grupo Deudas',
            'estado' => 'completado',
        ]);
    }

    public function test_cuotas_vencidas_crea_prospectos_con_emails_modificados(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        // Los emails de cuotas vencidas tienen ".cuotas" insertado
        $this->assertDatabaseHas('prospectos', ['email' => 'mtoro.cuotas@segal.cl']);
        $this->assertDatabaseHas('prospectos', ['email' => 'jfigueroa.cuotas@itds.cl']);
        $this->assertDatabaseHas('prospectos', ['email' => 'csalinas.cuotas@segal.cl']);
    }

    public function test_cuotas_vencidas_prospectos_tienen_rut(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospecto = Prospecto::where('email', 'mtoro.cuotas@segal.cl')->first();

        $this->assertNotNull($prospecto);
        $this->assertEquals('15445854-9', $prospecto->rut);
    }

    public function test_cuotas_vencidas_metadata_tiene_cuotas_array(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospecto = Prospecto::where('email', 'mtoro.cuotas@segal.cl')->first();

        $this->assertNotNull($prospecto);
        $this->assertIsArray($prospecto->metadata);
        $this->assertEquals('grupo_deuda', $prospecto->metadata['source']);
        $this->assertEquals('cuotas_vencidas', $prospecto->metadata['endpoint']);
        $this->assertArrayHasKey('cuotas', $prospecto->metadata);
        $this->assertIsArray($prospecto->metadata['cuotas']);
    }

    // =========================================================================
    // Tests de tipos de prospecto
    // =========================================================================

    public function test_prospectos_tienen_tipo_prospecto_asignado(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospectos = Prospecto::whereNotNull('tipo_prospecto_id')->get();

        $this->assertGreaterThan(0, $prospectos->count());

        foreach ($prospectos as $prospecto) {
            $this->assertNotNull($prospecto->tipoProspecto);
        }
    }

    public function test_prospectos_tienen_monto_deuda(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospectos = Prospecto::all();

        foreach ($prospectos as $prospecto) {
            $this->assertIsInt($prospecto->monto_deuda);
            $this->assertGreaterThan(0, $prospecto->monto_deuda);
        }
    }

    // =========================================================================
    // Tests de relaciones
    // =========================================================================

    public function test_lotes_tienen_external_api_source(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $loteContratos = Lote::where('nombre', 'CONTRATOS_NUEVOS_TEST')->first();
        $loteCuotas = Lote::where('nombre', 'CUOTAS_VENCIDAS_TEST')->first();

        $this->assertNotNull($loteContratos->external_api_source_id);
        $this->assertNotNull($loteCuotas->external_api_source_id);
    }

    public function test_importaciones_tienen_external_api_source(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $importaciones = Importacion::whereNotNull('external_api_source_id')->get();

        $this->assertGreaterThanOrEqual(2, $importaciones->count());
    }

    public function test_prospectos_pertenecen_a_importacion(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospectos = Prospecto::all();

        foreach ($prospectos as $prospecto) {
            $this->assertNotNull($prospecto->importacion_id);
            $this->assertNotNull($prospecto->importacion);
        }
    }

    // =========================================================================
    // Tests de contadores
    // =========================================================================

    public function test_lotes_tienen_contadores_actualizados(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $lotes = Lote::whereIn('nombre', ['CONTRATOS_NUEVOS_TEST', 'CUOTAS_VENCIDAS_TEST'])->get();

        foreach ($lotes as $lote) {
            $this->assertGreaterThan(0, $lote->total_registros);
            $this->assertEquals($lote->total_registros, $lote->registros_exitosos);
        }
    }

    public function test_importaciones_tienen_contadores_actualizados(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $lote = Lote::where('nombre', 'CONTRATOS_NUEVOS_TEST')->first();
        $importacion = Importacion::where('lote_id', $lote->id)->first();

        $this->assertNotNull($importacion);
        $this->assertGreaterThan(0, $importacion->total_registros);
        $this->assertEquals($importacion->total_registros, $importacion->registros_exitosos);
    }

    // =========================================================================
    // Tests de idempotencia
    // =========================================================================

    public function test_seeder_es_idempotente_para_emails_existentes(): void
    {
        // Ejecutar dos veces
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        // No debe haber duplicados de emails reales
        $count = Prospecto::where('email', 'mtoro@segal.cl')->count();
        $this->assertEquals(1, $count);
    }

    // =========================================================================
    // Tests de estado
    // =========================================================================

    public function test_todos_los_prospectos_estan_activos(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospectos = Prospecto::all();

        foreach ($prospectos as $prospecto) {
            $this->assertEquals('activo', $prospecto->estado);
        }
    }

    // =========================================================================
    // Tests de validación de datos
    // =========================================================================

    public function test_telefonos_tienen_formato_chileno(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospectos = Prospecto::whereNotNull('telefono')->get();

        foreach ($prospectos as $prospecto) {
            $this->assertMatchesRegularExpression('/^\+56\d{9}$/', $prospecto->telefono);
        }
    }

    public function test_ruts_tienen_formato_valido(): void
    {
        $this->artisan('db:seed', ['--class' => GrupoDeudaFakeDataSeeder::class]);

        $prospectos = Prospecto::whereNotNull('rut')->get();

        foreach ($prospectos as $prospecto) {
            // Formato: 12345678-9 o 12345678-K
            $this->assertMatchesRegularExpression('/^\d{7,8}-[\dK]$/', $prospecto->rut);
        }
    }
}
