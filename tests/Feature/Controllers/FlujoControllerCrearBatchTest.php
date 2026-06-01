<?php

namespace Tests\Feature\Controllers;

use App\Models\Flujo;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tanda 3 — regression tests for the two FlujoController call sites.
 *
 * ESCENARIO-10: agregarProspectosPorIds now populates fecha_ingreso.
 * ESCENARIO-11: asignarProspectosSync now populates fecha_ingreso.
 *
 * Both methods must preserve all observable behaviour (estado, canal, dedup) AND
 * now correctly set fecha_ingreso according to the flujo's origin via crearBatch.
 */
class FlujoControllerCrearBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TipoProspecto $tipoProspecto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->tipoProspecto = TipoProspecto::factory()->create();
    }

    // =========================================================================
    // agregarProspectosPorIds (POST /api/flujos/{flujo}/agregar-prospectos)
    // =========================================================================

    #[Test]
    public function test_agregar_prospectos_por_ids_popula_fecha_ingreso_clientes_ingreso(): void
    {
        // ESCENARIO-10: flujo with Clientes Ingreso origin → fecha_ingreso = now - 3 days
        $now = Carbon::create(2024, 3, 15, 10, 0, 0);
        Carbon::setTestNow($now);

        $flujo = Flujo::factory()->create([
            'origen' => 'Grupo Deudas - Clientes Ingreso',
            'user_id' => $this->user->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        $prospectos = Prospecto::factory()->count(3)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $ids = $prospectos->pluck('id')->toArray();

        $response = $this->actingAs($this->user)->postJson(
            "/api/flujos/{$flujo->id}/agregar-prospectos",
            [
                'prospecto_ids' => $ids,
                'canal_asignado' => 'email',
            ]
        );

        $response->assertStatus(200);

        $fechaEsperada = '2024-03-12'; // now - 3 days
        $rows = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->get();

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                $fechaEsperada,
                $row->fecha_ingreso,
                "fecha_ingreso must be now-3 for Clientes Ingreso origin"
            );
            $this->assertSame('pendiente', $row->estado);
            $this->assertSame('email', $row->canal_asignado);
        }

        Carbon::setTestNow();
    }

    #[Test]
    public function test_agregar_prospectos_por_ids_popula_fecha_ingreso_contratos_nuevos(): void
    {
        $now = Carbon::create(2024, 3, 15, 10, 0, 0);
        Carbon::setTestNow($now);

        $flujo = Flujo::factory()->create([
            'origen' => 'Grupo Deudas - Contratos Nuevos',
            'user_id' => $this->user->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        $prospectos = Prospecto::factory()->count(2)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $ids = $prospectos->pluck('id')->toArray();

        $response = $this->actingAs($this->user)->postJson(
            "/api/flujos/{$flujo->id}/agregar-prospectos",
            [
                'prospecto_ids' => $ids,
                'canal_asignado' => 'email',
            ]
        );

        $response->assertStatus(200);

        $fechaEsperada = '2024-03-15'; // same as now
        $rows = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                $fechaEsperada,
                $row->fecha_ingreso,
                "fecha_ingreso must equal now for Contratos Nuevos origin"
            );
        }

        Carbon::setTestNow();
    }

    #[Test]
    public function test_agregar_prospectos_por_ids_fecha_ingreso_null_para_origen_desconocido(): void
    {
        $now = Carbon::create(2024, 3, 15, 10, 0, 0);
        Carbon::setTestNow($now);

        $flujo = Flujo::factory()->create([
            'origen' => 'Origen Desconocido',
            'user_id' => $this->user->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        $prospectos = Prospecto::factory()->count(2)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $ids = $prospectos->pluck('id')->toArray();

        $response = $this->actingAs($this->user)->postJson(
            "/api/flujos/{$flujo->id}/agregar-prospectos",
            [
                'prospecto_ids' => $ids,
                'canal_asignado' => 'email',
            ]
        );

        $response->assertStatus(200);

        $rows = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull(
                $row->fecha_ingreso,
                "fecha_ingreso must be NULL for unknown/sysgal origin"
            );
        }

        Carbon::setTestNow();
    }

    #[Test]
    public function test_agregar_prospectos_por_ids_deduplica_existentes(): void
    {
        // The dedup logic (existingIds + array_diff) must still work after refactor
        $flujo = Flujo::factory()->create([
            'origen' => 'Grupo Deudas - Clientes Ingreso',
            'user_id' => $this->user->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        $prospectos = Prospecto::factory()->count(3)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $ids = $prospectos->pluck('id')->toArray();

        // Pre-insert first prospecto as already existing
        DB::table('prospecto_en_flujo')->insert([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $ids[0],
            'canal_asignado' => 'email',
            'estado' => 'pendiente',
            'fecha_inicio' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/flujos/{$flujo->id}/agregar-prospectos",
            [
                'prospecto_ids' => $ids,
                'canal_asignado' => 'email',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('resumen.ya_existentes', 1);
        $response->assertJsonPath('resumen.agregados', 2);

        // Only 3 total rows (1 existing + 2 new)
        $this->assertDatabaseCount('prospecto_en_flujo', 3);
    }

    // =========================================================================
    // asignarProspectosSync (via crearFlujoConProspectos POST endpoint, <=100 IDs)
    // =========================================================================

    #[Test]
    public function test_asignar_prospectos_sync_popula_fecha_ingreso_clientes_ingreso(): void
    {
        // ESCENARIO-11: asignarProspectosSync now populates fecha_ingreso
        $now = Carbon::create(2024, 3, 15, 10, 0, 0);
        Carbon::setTestNow($now);

        // We test asignarProspectosSync directly via the internal call path.
        // The method is private but called through crearFlujoConProspectos for <= 100 prospects.
        // We call the method directly using reflection to keep the test focused.
        $flujo = Flujo::factory()->create([
            'origen' => 'Grupo Deudas - Clientes Ingreso',
            'user_id' => $this->user->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        $prospectos = Prospecto::factory()->count(2)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $ids = $prospectos->pluck('id')->toArray();

        // Call the private method via reflection
        $controller = app(\App\Http\Controllers\FlujoController::class);
        $method = new \ReflectionMethod($controller, 'asignarProspectosSync');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $flujo, $ids, 'email');

        $this->assertSame(2, $result['total']);
        $this->assertFalse($result['is_async']);

        $fechaEsperada = '2024-03-12'; // now - 3 days
        $rows = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                $fechaEsperada,
                $row->fecha_ingreso,
                "asignarProspectosSync: fecha_ingreso must be now-3 for Clientes Ingreso"
            );
            $this->assertSame('pendiente', $row->estado);
        }

        Carbon::setTestNow();
    }

    #[Test]
    public function test_asignar_prospectos_sync_popula_fecha_ingreso_contratos_nuevos(): void
    {
        $now = Carbon::create(2024, 3, 15, 10, 0, 0);
        Carbon::setTestNow($now);

        $flujo = Flujo::factory()->create([
            'origen' => 'Grupo Deudas - Contratos Nuevos',
            'user_id' => $this->user->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        $prospectos = Prospecto::factory()->count(2)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $ids = $prospectos->pluck('id')->toArray();

        $controller = app(\App\Http\Controllers\FlujoController::class);
        $method = new \ReflectionMethod($controller, 'asignarProspectosSync');
        $method->setAccessible(true);

        $method->invoke($controller, $flujo, $ids, 'email');

        $fechaEsperada = '2024-03-15'; // same as now
        $rows = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($fechaEsperada, $row->fecha_ingreso);
        }

        Carbon::setTestNow();
    }

    #[Test]
    public function test_asignar_prospectos_sync_fecha_ingreso_null_para_origen_desconocido(): void
    {
        $flujo = Flujo::factory()->create([
            'origen' => 'SEGMENTO 1',
            'user_id' => $this->user->id,
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        $prospectos = Prospecto::factory()->count(2)->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);
        $ids = $prospectos->pluck('id')->toArray();

        $controller = app(\App\Http\Controllers\FlujoController::class);
        $method = new \ReflectionMethod($controller, 'asignarProspectosSync');
        $method->setAccessible(true);

        $method->invoke($controller, $flujo, $ids, 'sms');

        $rows = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->fecha_ingreso, "sysgal/unknown origin must have fecha_ingreso = NULL");
            $this->assertSame('sms', $row->canal_asignado);
        }
    }
}
