<?php

namespace Tests\Feature\Models;

use App\Models\Importacion;
use App\Models\Prospecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_importacion(): void
    {
        $user = User::factory()->create();

        $importacion = Importacion::factory()->create([
            'user_id' => $user->id,
            'origen' => 'banco_x',
            'estado' => 'procesando',
        ]);

        $this->assertDatabaseHas('importaciones', [
            'id' => $importacion->id,
            'user_id' => $user->id,
            'origen' => 'banco_x',
            'estado' => 'procesando',
        ]);
    }

    public function test_casts_fecha_importacion_como_datetime(): void
    {
        $importacion = Importacion::factory()->create([
            'fecha_importacion' => '2025-11-19 10:30:00',
        ]);

        $this->assertInstanceOf(\DateTime::class, $importacion->fecha_importacion);
    }

    public function test_casts_metadata_como_array(): void
    {
        $metadata = ['error' => 'test error', 'linea' => 5];

        $importacion = Importacion::factory()->create([
            'metadata' => $metadata,
        ]);

        $this->assertIsArray($importacion->metadata);
        $this->assertEquals($metadata, $importacion->metadata);
    }

    public function test_relacion_con_user(): void
    {
        $user = User::factory()->create();
        $importacion = Importacion::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $importacion->user);
        $this->assertEquals($user->id, $importacion->user->id);
    }

    public function test_relacion_con_prospectos(): void
    {
        $importacion = Importacion::factory()->create();
        $prospecto = Prospecto::factory()->create(['importacion_id' => $importacion->id]);

        $this->assertTrue($importacion->prospectos->contains($prospecto));
        $this->assertCount(1, $importacion->prospectos);
    }

    public function test_is_completado_devuelve_true_cuando_completado(): void
    {
        $importacion = Importacion::factory()->create(['estado' => 'completado']);

        $this->assertTrue($importacion->isCompletado());
    }

    public function test_is_completado_devuelve_false_cuando_no_completado(): void
    {
        $importacion = Importacion::factory()->create(['estado' => 'procesando']);

        $this->assertFalse($importacion->isCompletado());
    }

    public function test_is_fallido_devuelve_true_cuando_fallido(): void
    {
        $importacion = Importacion::factory()->create(['estado' => 'fallido']);

        $this->assertTrue($importacion->isFallido());
    }

    public function test_is_fallido_devuelve_false_cuando_no_fallido(): void
    {
        $importacion = Importacion::factory()->create(['estado' => 'completado']);

        $this->assertFalse($importacion->isFallido());
    }

    public function test_is_procesando_devuelve_true_cuando_procesando(): void
    {
        $importacion = Importacion::factory()->create(['estado' => 'procesando']);

        $this->assertTrue($importacion->isProcesando());
    }

    public function test_is_procesando_devuelve_false_cuando_no_procesando(): void
    {
        $importacion = Importacion::factory()->create(['estado' => 'completado']);

        $this->assertFalse($importacion->isProcesando());
    }

    public function test_puede_tener_multiples_prospectos(): void
    {
        $importacion = Importacion::factory()->create();
        Prospecto::factory()->count(5)->create(['importacion_id' => $importacion->id]);

        $this->assertCount(5, $importacion->prospectos);
    }

    public function test_metadata_puede_ser_null(): void
    {
        $importacion = Importacion::factory()->create(['metadata' => null]);

        $this->assertNull($importacion->metadata);
    }

    public function test_estado_puede_cambiar_de_procesando_a_completado(): void
    {
        $importacion = Importacion::factory()->create(['estado' => 'procesando']);

        $importacion->update(['estado' => 'completado']);

        $this->assertTrue($importacion->fresh()->isCompletado());
        $this->assertFalse($importacion->fresh()->isProcesando());
    }

    public function test_estado_puede_cambiar_de_procesando_a_fallido(): void
    {
        $importacion = Importacion::factory()->create(['estado' => 'procesando']);

        $importacion->update(['estado' => 'fallido']);

        $this->assertTrue($importacion->fresh()->isFallido());
        $this->assertFalse($importacion->fresh()->isProcesando());
    }

    public function test_registros_exitosos_y_fallidos_suman_total_registros(): void
    {
        $importacion = Importacion::factory()->create([
            'total_registros' => 100,
            'registros_exitosos' => 85,
            'registros_fallidos' => 15,
        ]);

        $this->assertEquals(
            $importacion->total_registros,
            $importacion->registros_exitosos + $importacion->registros_fallidos
        );
    }

    public function test_puede_guardar_metadata_de_errores(): void
    {
        $errores = [
            ['linea' => 5, 'error' => 'Email inválido'],
            ['linea' => 12, 'error' => 'Teléfono inválido'],
        ];

        $importacion = Importacion::factory()->create([
            'estado' => 'completado',
            'metadata' => ['errores' => $errores],
        ]);

        $this->assertArrayHasKey('errores', $importacion->metadata);
        $this->assertCount(2, $importacion->metadata['errores']);
    }

    public function test_diferentes_origenes_son_validos(): void
    {
        $origenes = ['banco_x', 'campania_verano', 'base_general', 'referidos'];

        foreach ($origenes as $origen) {
            $importacion = Importacion::factory()->create(['origen' => $origen]);
            $this->assertEquals($origen, $importacion->origen);
        }
    }
}
