<?php

namespace Tests\Feature;

use App\DTOs\CriteriosSeleccionProspectos;
use App\Models\Importacion;
use App\Models\Lote;
use App\Models\Prospecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests para filtros de metadata en prospectos.
 *
 * Verifica que:
 * - El DTO CriteriosSeleccionProspectos soporta metadataFilters
 * - El endpoint count respeta filtros de metadata
 * - El endpoint metadata-values devuelve valores únicos correctamente
 */
class MetadataFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Lote $lote;

    private Importacion $importacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->lote = Lote::factory()->create(['user_id' => $this->user->id]);
        $this->importacion = Importacion::factory()->create([
            'lote_id' => $this->lote->id,
            'user_id' => $this->user->id,
        ]);
    }

    // =========================================================================
    // Tests del DTO CriteriosSeleccionProspectos
    // =========================================================================

    public function test_dto_soporta_metadata_filters(): void
    {
        $criterios = new CriteriosSeleccionProspectos(
            origen: 'api',
            tipoProspectoId: null,
            selectAllFromOrigin: true,
            prospectoIds: [],
            loteIds: [],
            metadataFilters: ['nivel_deuda' => 'alta'],
        );

        $this->assertTrue($criterios->usarFiltroMetadata());
        $this->assertEquals(['nivel_deuda' => 'alta'], $criterios->metadataFilters);
    }

    public function test_dto_serializa_metadata_filters(): void
    {
        $criterios = new CriteriosSeleccionProspectos(
            origen: 'api',
            tipoProspectoId: null,
            selectAllFromOrigin: true,
            metadataFilters: ['nivel_deuda' => ['alta', 'media']],
        );

        $array = $criterios->toArray();

        $this->assertArrayHasKey('metadata_filters', $array);
        $this->assertEquals(['nivel_deuda' => ['alta', 'media']], $array['metadata_filters']);
    }

    public function test_dto_deserializa_metadata_filters(): void
    {
        $data = [
            'origen' => 'api',
            'tipo_prospecto_id' => null,
            'select_all_from_origin' => true,
            'metadata_filters' => ['nivel_deuda' => 'baja'],
        ];

        $criterios = CriteriosSeleccionProspectos::fromArray($data);

        $this->assertTrue($criterios->usarFiltroMetadata());
        $this->assertEquals(['nivel_deuda' => 'baja'], $criterios->metadataFilters);
    }

    public function test_dto_sin_metadata_filters(): void
    {
        $criterios = new CriteriosSeleccionProspectos(
            origen: 'api',
            tipoProspectoId: null,
            selectAllFromOrigin: true,
        );

        $this->assertFalse($criterios->usarFiltroMetadata());
        $this->assertEquals([], $criterios->metadataFilters);
    }

    // =========================================================================
    // Tests del endpoint count con filtros de metadata
    // =========================================================================

    public function test_count_filtra_por_metadata_valor_unico(): void
    {
        // Crear prospectos con diferentes niveles de deuda
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'alta'],
        ]);
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'alta'],
        ]);
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'baja'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/prospectos/count?metadata_nivel_deuda=alta');

        $response->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_count_filtra_por_metadata_multiples_valores(): void
    {
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'alta'],
        ]);
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'media'],
        ]);
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'baja'],
        ]);

        // Filtrar por alta O media
        $response = $this->actingAs($this->user)
            ->getJson('/api/prospectos/count?metadata_nivel_deuda[]=alta&metadata_nivel_deuda[]=media');

        $response->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    // =========================================================================
    // Tests del endpoint metadata-values
    // =========================================================================

    public function test_metadata_values_devuelve_valores_unicos(): void
    {
        Prospecto::factory()->count(3)->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'alta'],
        ]);
        Prospecto::factory()->count(2)->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'media'],
        ]);
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'baja'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/prospectos/metadata-values/nivel_deuda');

        $response->assertOk()
            ->assertJsonPath('data.campo', 'nivel_deuda')
            ->assertJsonCount(3, 'data.valores');

        // Verificar que están ordenados por total descendente
        $valores = $response->json('data.valores');
        $this->assertEquals('alta', $valores[0]['valor']);
        $this->assertEquals(3, $valores[0]['total']);
        $this->assertEquals('media', $valores[1]['valor']);
        $this->assertEquals(2, $valores[1]['total']);
    }

    public function test_metadata_values_filtra_por_lotes(): void
    {
        // Crear otro lote con su importación
        $otroLote = Lote::factory()->create(['user_id' => $this->user->id]);
        $otraImportacion = Importacion::factory()->create([
            'lote_id' => $otroLote->id,
            'user_id' => $this->user->id,
        ]);

        // Prospectos del primer lote
        Prospecto::factory()->count(3)->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'alta'],
        ]);

        // Prospectos del otro lote
        Prospecto::factory()->count(5)->create([
            'importacion_id' => $otraImportacion->id,
            'metadata' => ['nivel_deuda' => 'baja'],
        ]);

        // Filtrar solo por el primer lote
        $response = $this->actingAs($this->user)
            ->getJson("/api/prospectos/metadata-values/nivel_deuda?lote_ids[]={$this->lote->id}");

        $response->assertOk();
        $valores = $response->json('data.valores');

        // Solo debería ver "alta" (del primer lote)
        $this->assertCount(1, $valores);
        $this->assertEquals('alta', $valores[0]['valor']);
        $this->assertEquals(3, $valores[0]['total']);
    }

    public function test_metadata_values_rechaza_campo_invalido(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/prospectos/metadata-values/campo-con-guiones');

        $response->assertStatus(422);
    }

    public function test_metadata_values_excluye_valores_vacios(): void
    {
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => 'alta'],
        ]);
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['nivel_deuda' => ''],
        ]);
        Prospecto::factory()->create([
            'importacion_id' => $this->importacion->id,
            'metadata' => ['otro_campo' => 'valor'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/prospectos/metadata-values/nivel_deuda');

        $response->assertOk();
        $valores = $response->json('data.valores');

        // Solo debería ver "alta", no el vacío ni los null
        $this->assertCount(1, $valores);
        $this->assertEquals('alta', $valores[0]['valor']);
    }
}
