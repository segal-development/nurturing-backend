<?php

namespace Tests\Feature\Commands;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TDD RED → GREEN: RepacePosicionPerpetuoCommand
 *
 * Problema que resuelve:
 *   Una cohorte perpetua parada hace meses, al reanudar, está "due" para TODAS
 *   las etapas vencidas (gate = now() >= fecha_inicio + offset) → recibe todas de golpe.
 *
 * Solución — re-anclar fecha_inicio:
 *   fecha_inicio_nuevo = now() - currentOffset
 *   → La etapa ACTUAL queda "hoy", la SIGUIENTE sale en su intervalo normal.
 *
 * Condición de re-pacing (idempotencia):
 *   Si now() >= fecha_inicio + nextOffset → la próxima ya está vencida → re-anclar.
 *   Si now() <  fecha_inicio + nextOffset → la próxima ya está en el futuro → SKIP.
 *
 * Test scenarios:
 *   T-RP-1: prospecto con próxima etapa vencida → re-anclado (avalancha → cadencia normal)
 *   T-RP-2: prospecto ya paceado (próxima futura) → NO se toca
 *   T-RP-3: idempotente (2da corrida = 0 re-anclados)
 *   T-RP-4: dry-run no escribe
 *   T-RP-5: aborta si ejecución no está 'paused'
 *   T-RP-6: NO modifica fecha_ingreso
 *   T-RP-7: prospecto en cadena condicional (offset -1) → skip
 */
class RepacePosicionPerpetuoTest extends TestCase
{
    use RefreshDatabase;

    private TipoProspecto $tipoProspecto;

    private User $user;

    /**
     * Flujo lineal con 3 etapas ejecutables:
     *   stage-A: tiempo_espera=0   → offset acumulado=0d
     *   stage-B: tiempo_espera=7   → offset acumulado=7d
     *   stage-C: tiempo_espera=7   → offset acumulado=14d
     *   end-1:   end node
     *
     * Ejemplo de avalancha: prospecto con fecha_inicio=hace 20 días y ultima_etapa=stage-B.
     *   currentOffset (stage-B) = 7d
     *   nextOffset    (stage-C) = 14d
     *   now() >= fecha_inicio + 14d  →  20d >= 14d → TRUE → avalancha → re-anclar
     *
     * Ejemplo ya paceado: prospecto con fecha_inicio=hace 8 días y ultima_etapa=stage-B.
     *   nextOffset (stage-C) = 14d
     *   now() >= fecha_inicio + 14d  →  8d >= 14d → FALSE → ya paceado → skip
     */
    private Flujo $flujo;

    private FlujoEjecucion $ejecucion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();

        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => $this->makeLinearThreeStageFlow(),
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'es_perpetuo' => true,
            'prospectos_ids' => [],
        ]);
    }

    // ============================================================
    // T-RP-1: Próxima etapa vencida → se re-ancla fecha_inicio
    // ============================================================

    #[Test]
    public function prospecto_con_proxima_etapa_vencida_se_reancla(): void
    {
        // Prospecto en stage-B hace 20 días.
        // currentOffset(stage-B) = 7d, nextOffset(stage-C) = 14d.
        // now() >= fecha_inicio + 14d  →  20d >= 14d → VENCIDA → debe re-anclar.
        // fecha_inicio_nueva = now() - 7d (currentOffset).
        // Después del re-anclado: now() >= nueva_fecha_inicio + 14d  →  0d+7d >= 14d → 7 >= 14 → FALSE → OK.
        $pef = $this->crearProspecto(
            fechaInicioAgo: 20,
            ultimaEtapaNodeId: 'stage-B',
            fechaIngreso: now()->subDays(20)->toDateString(),
        );

        $this->artisan('nurturing:repacear-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(0);

        $pefFresh = $pef->fresh();

        // fecha_inicio debe haberse movido a approximately now() - 7d (currentOffset=7d)
        // Verificamos con tolerancia de 1 minuto para evitar flakiness
        $expectedFechaInicio = now()->subDays(7);
        $this->assertEqualsWithDelta(
            $expectedFechaInicio->timestamp,
            $pefFresh->fecha_inicio->timestamp,
            60, // tolerancia 60 segundos
            'fecha_inicio debe re-anclarse a now() - currentOffset (7d)'
        );

        // La próxima etapa (stage-C, offset=14d) debe quedar en el FUTURO:
        // nueva_fecha_inicio + 14d = (now-7d) + 14d = now+7d → FUTURO → no avalancha
        $proximaFechaHabilitacion = $pefFresh->fecha_inicio->copy()->addDays(14);
        $this->assertTrue(
            now()->lessThan($proximaFechaHabilitacion),
            'Tras el re-anclado, la próxima etapa debe quedar en el FUTURO (no avalancha)'
        );
    }

    // ============================================================
    // T-RP-2: Próxima etapa ya está en el futuro → NO se toca
    // ============================================================

    #[Test]
    public function prospecto_ya_paceado_no_se_modifica(): void
    {
        // Prospecto en stage-B hace 8 días.
        // nextOffset(stage-C) = 14d.
        // now() >= fecha_inicio + 14d  →  8d >= 14d → FALSE → ya paceado → NO re-anclar.
        $fechaOriginal = now()->subDays(8);
        $pef = $this->crearProspecto(
            fechaInicioAgo: 8,
            ultimaEtapaNodeId: 'stage-B',
        );

        $this->artisan('nurturing:repacear-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(0);

        $pefFresh = $pef->fresh();

        // fecha_inicio NO debe haber cambiado
        $this->assertEqualsWithDelta(
            $fechaOriginal->timestamp,
            $pefFresh->fecha_inicio->timestamp,
            60,
            'fecha_inicio NO debe modificarse cuando la próxima etapa ya está en el futuro'
        );
    }

    // ============================================================
    // T-RP-3: Idempotente — segunda corrida no re-ancla de nuevo
    // ============================================================

    #[Test]
    public function es_idempotente_segunda_corrida_no_reancla(): void
    {
        $pef = $this->crearProspecto(
            fechaInicioAgo: 20,
            ultimaEtapaNodeId: 'stage-B',
        );

        // Primera corrida: debe re-anclar
        $this->artisan('nurturing:repacear-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(0);

        $fechaDespuesPrimera = $pef->fresh()->fecha_inicio;

        // Segunda corrida: la próxima etapa ya quedó en el futuro → skip
        $this->artisan('nurturing:repacear-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(0);

        $fechaDespuesSegunda = $pef->fresh()->fecha_inicio;

        // fecha_inicio no debe haber cambiado entre la 1ra y 2da corrida
        $this->assertEqualsWithDelta(
            $fechaDespuesPrimera->timestamp,
            $fechaDespuesSegunda->timestamp,
            60,
            'La segunda corrida NO debe modificar fecha_inicio (idempotencia)'
        );
    }

    // ============================================================
    // T-RP-4: dry-run no escribe
    // ============================================================

    #[Test]
    public function dry_run_no_escribe_cambios(): void
    {
        $fechaOriginal = now()->subDays(20);
        $pef = $this->crearProspecto(
            fechaInicioAgo: 20,
            ultimaEtapaNodeId: 'stage-B',
        );

        $this->artisan('nurturing:repacear-posicion', [
            '--flujo' => $this->flujo->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        // fecha_inicio NO debe haber cambiado
        $this->assertEqualsWithDelta(
            $fechaOriginal->timestamp,
            $pef->fresh()->fecha_inicio->timestamp,
            60,
            'dry-run NO debe escribir ningún cambio'
        );
    }

    // ============================================================
    // T-RP-5: Aborta si la ejecución no está 'paused'
    // ============================================================

    #[Test]
    public function aborta_si_la_ejecucion_no_esta_paused(): void
    {
        $this->ejecucion->update(['estado' => 'in_progress']);

        $pef = $this->crearProspecto(
            fechaInicioAgo: 20,
            ultimaEtapaNodeId: 'stage-B',
        );

        $this->artisan('nurturing:repacear-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(1);

        // fecha_inicio NO debe haber cambiado
        $this->assertEqualsWithDelta(
            now()->subDays(20)->timestamp,
            $pef->fresh()->fecha_inicio->timestamp,
            60,
            'Si la ejecución no está paused, no debe modificar nada'
        );
    }

    // ============================================================
    // T-RP-6: NUNCA modifica fecha_ingreso
    // ============================================================

    #[Test]
    public function no_modifica_fecha_ingreso(): void
    {
        $fechaIngresoOriginal = now()->subDays(30)->toDateString();
        $pef = $this->crearProspecto(
            fechaInicioAgo: 20,
            ultimaEtapaNodeId: 'stage-B',
            fechaIngreso: $fechaIngresoOriginal,
        );

        $this->artisan('nurturing:repacear-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(0);

        // fecha_ingreso debe permanecer intacta
        $this->assertEquals(
            $fechaIngresoOriginal,
            $pef->fresh()->fecha_ingreso?->toDateString(),
            'fecha_ingreso NUNCA debe ser modificada por el re-pacing'
        );
    }

    // ============================================================
    // T-RP-7: Flujo condicional (offsetAcumulado=-1) → skip
    // ============================================================

    #[Test]
    public function skip_cuando_offset_acumulado_es_negativo(): void
    {
        // Crear un flujo con topología que no incluye el stage en la cadena lineal
        // (offsetAcumulado retorna -1 para ese node_id)
        $flujoCondicional = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => $this->makeConditionalFlow(),
        ]);

        $ejecucionCondicional = FlujoEjecucion::factory()->create([
            'flujo_id' => $flujoCondicional->id,
            'estado' => 'paused',
            'es_perpetuo' => true,
            'prospectos_ids' => [],
        ]);

        // Prospecto con un node_id que NO está en la cadena lineal (topología condicional)
        $pef = $this->crearProspectoEnFlujo(
            flujoId: $flujoCondicional->id,
            fechaInicioAgo: 20,
            ultimaEtapaNodeId: 'stage-rama-izq', // rama que no está en cadena lineal principal
        );

        $fechaOriginal = $pef->fecha_inicio;

        $this->artisan('nurturing:repacear-posicion', [
            '--flujo' => $flujoCondicional->id,
        ])->assertExitCode(0);

        // fecha_inicio NO debe haber cambiado (skip por offset=-1)
        $this->assertEqualsWithDelta(
            $fechaOriginal->timestamp,
            $pef->fresh()->fecha_inicio->timestamp,
            60,
            'Prospecto con offset=-1 (cadena condicional) debe ser skipped, sin tocar fecha_inicio'
        );
    }

    // ============================================================
    // Helper methods
    // ============================================================

    private function crearProspecto(
        int $fechaInicioAgo,
        string $ultimaEtapaNodeId,
        ?string $fechaIngreso = null,
    ): ProspectoEnFlujo {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => fake()->unique()->safeEmail(),
        ]);

        return ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => $ultimaEtapaNodeId,
            'fecha_inicio' => now()->subDays($fechaInicioAgo),
            'fecha_ingreso' => $fechaIngreso,
            'completado' => false,
            'cancelado' => false,
        ]);
    }

    private function crearProspectoEnFlujo(
        int $flujoId,
        int $fechaInicioAgo,
        string $ultimaEtapaNodeId,
    ): ProspectoEnFlujo {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => fake()->unique()->safeEmail(),
        ]);

        return ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $flujoId,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => $ultimaEtapaNodeId,
            'fecha_inicio' => now()->subDays($fechaInicioAgo),
            'completado' => false,
            'cancelado' => false,
        ]);
    }

    /**
     * Flujo lineal con 3 etapas ejecutables:
     *   start-1  → stage-A (0d) → stage-B (7d) → stage-C (14d) → end-1
     *
     * Offsets acumulados:
     *   stage-A:  0d
     *   stage-B:  7d  (0+7)
     *   stage-C: 14d  (0+7+7)
     */
    private function makeLinearThreeStageFlow(): array
    {
        return [
            'initial_node' => 'start-1',
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'label' => 'Inicio', 'tiempo_espera' => 0],
                ['id' => 'stage-A', 'type' => 'email', 'label' => 'DÍA 0',  'tiempo_espera' => 0],
                ['id' => 'stage-B', 'type' => 'email', 'label' => 'DÍA 7',  'tiempo_espera' => 7],
                ['id' => 'stage-C', 'type' => 'email', 'label' => 'DÍA 14', 'tiempo_espera' => 7],
                ['id' => 'end-1',   'type' => 'end',   'label' => 'Fin',    'tiempo_espera' => 0],
            ],
            'branches' => [
                ['source_node_id' => 'start-1', 'target_node_id' => 'stage-A'],
                ['source_node_id' => 'stage-A', 'target_node_id' => 'stage-B'],
                ['source_node_id' => 'stage-B', 'target_node_id' => 'stage-C'],
                ['source_node_id' => 'stage-C', 'target_node_id' => 'end-1'],
            ],
        ];
    }

    /**
     * Flujo condicional donde 'stage-rama-izq' NO está en la cadena lineal principal.
     * offsetAcumulado('stage-rama-izq') retornará -1 (no encontrado en cadena).
     *
     * Estructura: start-1 → stage-A → end-1
     * El 'stage-rama-izq' existe como stage pero no está conectado en el camino principal.
     */
    private function makeConditionalFlow(): array
    {
        return [
            'initial_node' => 'start-1',
            'stages' => [
                ['id' => 'start-1',       'type' => 'start', 'label' => 'Inicio',    'tiempo_espera' => 0],
                ['id' => 'stage-A',       'type' => 'email', 'label' => 'Principal', 'tiempo_espera' => 0],
                ['id' => 'stage-rama-izq', 'type' => 'email', 'label' => 'Rama Izq', 'tiempo_espera' => 7],
                ['id' => 'end-1',         'type' => 'end',   'label' => 'Fin',       'tiempo_espera' => 0],
            ],
            'branches' => [
                // Cadena lineal: start-1 → stage-A → end-1
                // stage-rama-izq NO tiene conexión entrante desde la cadena principal
                ['source_node_id' => 'start-1', 'target_node_id' => 'stage-A'],
                ['source_node_id' => 'stage-A', 'target_node_id' => 'end-1'],
                // stage-rama-izq sí tiene salida pero no entrada desde la cadena principal
                ['source_node_id' => 'stage-rama-izq', 'target_node_id' => 'end-1'],
            ],
        ];
    }
}
