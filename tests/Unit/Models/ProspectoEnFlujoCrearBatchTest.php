<?php

namespace Tests\Unit\Models;

use App\Models\Flujo;
use App\Models\ProspectoEnFlujo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProspectoEnFlujoCrearBatchTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::create(2024, 1, 10, 12, 0, 0);
    }

    // ─── ESCENARIO-1: fecha_ingreso para flujo Clientes-Ingreso ──────────────

    #[Test]
    public function test_fecha_ingreso_es_hoy_menos_3_para_clientes_ingreso(): void
    {
        $flujo = Flujo::factory()->create([
            'origen' => 'Grupo Deudas - Clientes Ingreso',
        ]);
        $prospectoIds = $this->crearProspectoIds(2);

        $count = ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'email', $this->now);

        $this->assertSame(2, $count);

        $fechaEsperada = '2024-01-07';
        DB::table('prospecto_en_flujo')
            ->whereIn('prospecto_id', $prospectoIds)
            ->get()
            ->each(function ($row) use ($fechaEsperada) {
                $this->assertSame(
                    $fechaEsperada,
                    $row->fecha_ingreso,
                    "fecha_ingreso debe ser {$fechaEsperada} para Clientes Ingreso"
                );
            });
    }

    // ─── ESCENARIO-2: fecha_ingreso para flujo Contratos Nuevos ─────────────

    #[Test]
    public function test_fecha_ingreso_es_hoy_para_contratos_nuevos(): void
    {
        $flujo = Flujo::factory()->create([
            'origen' => 'Grupo Deudas - Contratos Nuevos',
        ]);
        $prospectoIds = $this->crearProspectoIds(1);

        $count = ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'sms', $this->now);

        $this->assertSame(1, $count);

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertSame('2024-01-10', $row->fecha_ingreso);
    }

    // ─── ESCENARIO-3: fecha_ingreso NULL para origen no nombrado ────────────

    #[Test]
    public function test_fecha_ingreso_es_null_para_origen_no_nombrado(): void
    {
        $flujo = Flujo::factory()->create([
            'origen' => 'SEGMENTO 1', // típico origen sysgal
        ]);
        $prospectoIds = $this->crearProspectoIds(1);

        ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'email', $this->now);

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertNull($row->fecha_ingreso);
    }

    // ─── ESCENARIO-4: canal 'ambos' → 'email' ────────────────────────────────

    #[Test]
    public function test_canal_ambos_se_normaliza_a_email(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = $this->crearProspectoIds(1);

        ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'ambos', $this->now);

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertSame('email', $row->canal_asignado);
    }

    // ─── ESCENARIO-5: canal 'email' se preserva ─────────────────────────────

    #[Test]
    public function test_canal_email_se_preserva(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = $this->crearProspectoIds(1);

        ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'email', $this->now);

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertSame('email', $row->canal_asignado);
    }

    // ─── ESCENARIO-6: canal 'sms' se preserva ───────────────────────────────

    #[Test]
    public function test_canal_sms_se_preserva(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = $this->crearProspectoIds(1);

        ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'sms', $this->now);

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertSame('sms', $row->canal_asignado);
    }

    // ─── ESCENARIO-7: defaults correctos ────────────────────────────────────

    #[Test]
    public function test_defaults_completado_false_cancelado_false_etapa_actual_null(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = $this->crearProspectoIds(1);

        ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'email', $this->now);

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertFalse((bool) $row->completado, 'completado debe ser false');
        $this->assertFalse((bool) $row->cancelado, 'cancelado debe ser false');
        $this->assertNull($row->etapa_actual_id, 'etapa_actual_id debe ser null');
    }

    #[Test]
    public function test_estado_pendiente_por_defecto(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = $this->crearProspectoIds(1);

        ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'email', $this->now);

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertSame('pendiente', $row->estado);
    }

    #[Test]
    public function test_estado_en_proceso_cuando_se_pasa(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = $this->crearProspectoIds(1);

        ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'email', $this->now, 'en_proceso');

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertSame('en_proceso', $row->estado);
    }

    #[Test]
    public function test_fecha_inicio_y_timestamps_no_son_null(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = $this->crearProspectoIds(1);

        ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'email', $this->now);

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertNotNull($row->fecha_inicio, 'fecha_inicio no debe ser null');
        $this->assertNotNull($row->created_at, 'created_at no debe ser null');
        $this->assertNotNull($row->updated_at, 'updated_at no debe ser null');
    }

    #[Test]
    public function test_ultima_etapa_node_id_se_persiste_cuando_se_pasa(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = $this->crearProspectoIds(1);

        ProspectoEnFlujo::crearBatch(
            $flujo,
            $prospectoIds,
            'email',
            $this->now,
            'pendiente',
            'node-abc-123'
        );

        $row = DB::table('prospecto_en_flujo')
            ->where('prospecto_id', $prospectoIds[0])
            ->first();

        $this->assertSame('node-abc-123', $row->ultima_etapa_node_id);
    }

    // ─── ESCENARIO-8: idempotencia parcial — prospecto ya en el flujo se omite ──

    #[Test]
    public function test_fallback_individual_no_crashea_ante_pk_collision(): void
    {
        $flujo = Flujo::factory()->create();
        // Insertar el prospecto 99 como ya existente
        [$id99, $id100, $id101] = $this->crearProspectoIds(3);

        // Pre-insertar id99 para simular duplicado
        DB::table('prospecto_en_flujo')->insert([
            'flujo_id'     => $flujo->id,
            'prospecto_id' => $id99,
            'canal_asignado' => 'email',
            'estado'         => 'pendiente',
            'fecha_inicio'   => $this->now,
            'completado'     => false,
            'cancelado'      => false,
            'created_at'     => $this->now,
            'updated_at'     => $this->now,
        ]);

        // No debe lanzar excepción
        $count = ProspectoEnFlujo::crearBatch(
            $flujo,
            [$id99, $id100, $id101],
            'email',
            $this->now
        );

        // id99 ya existe en el flujo → crearBatch lo filtra internamente; id100 e id101 se insertan
        $this->assertSame(2, $count);
    }

    // ─── ESCENARIO-9: idempotencia total ────────────────────────────────────

    #[Test]
    public function test_devuelve_cero_cuando_todos_duplicados(): void
    {
        $flujo = Flujo::factory()->create();
        [$id5, $id6] = $this->crearProspectoIds(2);

        // Pre-insertar ambos
        foreach ([$id5, $id6] as $pid) {
            DB::table('prospecto_en_flujo')->insert([
                'flujo_id'       => $flujo->id,
                'prospecto_id'   => $pid,
                'canal_asignado' => 'email',
                'estado'         => 'pendiente',
                'fecha_inicio'   => $this->now,
                'completado'     => false,
                'cancelado'      => false,
                'created_at'     => $this->now,
                'updated_at'     => $this->now,
            ]);
        }

        $count = ProspectoEnFlujo::crearBatch($flujo, [$id5, $id6], 'email', $this->now);

        $this->assertSame(0, $count);
    }

    #[Test]
    public function test_devuelve_count_de_insertados(): void
    {
        $flujo = Flujo::factory()->create();
        $prospectoIds = $this->crearProspectoIds(3);

        $count = ProspectoEnFlujo::crearBatch($flujo, $prospectoIds, 'email', $this->now);

        $this->assertSame(3, $count);
    }

    // ─── Helper ─────────────────────────────────────────────────────────────

    /**
     * Crea N prospectos en DB y devuelve sus IDs.
     *
     * @return int[]
     */
    private function crearProspectoIds(int $n): array
    {
        return \App\Models\Prospecto::factory()->count($n)->create()->pluck('id')->all();
    }
}
