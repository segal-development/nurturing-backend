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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TDD RED → GREEN: RepairPosicionPerpetuoCommand
 *
 * Design spec (Decision 5):
 *   - Signature: nurturing:repair-posicion {--flujo=} {--dry-run} {--limit=0}
 *   - Precondition: FlujoEjecucion.estado must be 'paused' — abort otherwise
 *   - Logic: stageCorrecto = max{ stageN : fecha_inicio + offsetAcumulado(stageN) <= now() }
 *   - Reusar GuardedTransition::offsetAcumulado para coherencia con el gate temporal
 *   - Corregir prospecto_en_flujo.ultima_etapa_node_id
 *   - Reconstruir pivote etapa_prospecto (sync → solo FEE de la PRÓXIMA etapa elegible)
 *   - Recalcular flujo_ejecucion_etapas.prospectos_ids + prospectos_count
 *   - Idempotente: correr 2 veces = mismo resultado
 *   - dry-run: muestra qué cambiaría sin escribir
 *   - --limit: máximo de prospectos a reparar
 *   - Métricas de salida: cuántos se corrigen y de qué stage a qué stage
 *
 * Test scenarios:
 *   T-RP-1: prospecto con 40 días y ultima_etapa='stage-DIA120' se corrige a su stage real
 *   T-RP-2: dry-run no escribe nada
 *   T-RP-3: aborta si la ejecución no está 'paused'
 *   T-RP-4: idempotente (correr 2 veces = mismo resultado)
 */
class RepairPosicionPerpetuoTest extends TestCase
{
    use RefreshDatabase;

    private TipoProspecto $tipoProspecto;

    private User $user;

    /**
     * Flujo con 3 etapas:
     *   stage-0:  tiempo_espera=0   → offset=0d
     *   stage-30: tiempo_espera=30  → offset=30d
     *   stage-60: tiempo_espera=30  → offset=60d (30+30)
     *   stage-120: tiempo_espera=60 → offset=120d (30+30+60)
     *   end-1: end node
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
            'config_structure' => $this->makeFourStageFlow(),
        ]);

        // Ejecución paused (precondición dura del comando)
        $this->ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'es_perpetuo' => true,
            'prospectos_ids' => [],
        ]);
    }

    // ============================================================
    // T-RP-1: Prospecto con 40 días corrige su stage incorrecto
    // ============================================================

    #[Test]
    public function prospecto_con_40_dias_y_stage_incorrecto_se_corrige(): void
    {
        // Prospecto: ingresó hace 40 días → debe estar en stage-30 (offset=30d ≤ 40 < 60d)
        // Pero su ultima_etapa_node_id está mal puesto en 'stage-DIA120'
        $pef = $this->crearProspecto(
            fechaInicioAgo: 40,
            ultimaEtapaNodeId: 'stage-DIA120',
        );

        // FEE de todas las etapas (para que el comando pueda reconstruir el pivote)
        $fees = $this->crearFees();

        $this->artisan('nurturing:repair-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(0);

        // Debe haberse corregido a stage-30 (la stage con offset 30d <= 40d)
        $this->assertEquals('stage-30', $pef->fresh()->ultima_etapa_node_id);

        // SUGGESTION 2: verificar que el pivote etapa_prospecto guarda Prospecto IDs
        // (no PEF IDs). La siguiente etapa del prospecto es stage-60 (next after stage-30).
        // prospectos()->pluck('prospectos.id') debe contener el Prospecto ID del PEF,
        // NO su PEF ID. Esto fija el WARNING 1 (repair pivot guardaba PEF IDs).
        $feeStage60 = $fees['stage-60'];
        $pivotProspectoIds = $feeStage60->fresh()->prospectos()->pluck('prospectos.id')->toArray();

        // El pivote debe contener el Prospecto ID real del prospecto reparado
        $this->assertContains(
            $pef->prospecto_id,
            $pivotProspectoIds,
            'El pivote etapa_prospecto debe contener el Prospecto ID (no el PEF ID) del prospecto reparado'
        );

        // Verificar que el registro en etapa_prospecto referencia prospectos.id,
        // no prospecto_en_flujo.id. Si el PEF ID difiere del Prospecto ID, el PEF ID
        // no debe estar en el pivote. Solo comprobamos esta condición cuando son distintos
        // (en IDs bajos de test pueden coincidir fortuitamente, lo que sería OK igualmente:
        // significa que el Prospecto ID == PEF ID, y el Prospecto ID correcto está guardado).
        if ($pef->id !== $pef->prospecto_id) {
            $this->assertNotContains(
                $pef->id,
                $pivotProspectoIds,
                'El pivote etapa_prospecto NO debe contener el PEF ID (solo Prospecto IDs)'
            );
        }
    }

    // ============================================================
    // T-RP-2: dry-run NO escribe nada
    // ============================================================

    #[Test]
    public function dry_run_no_escribe_cambios(): void
    {
        $pef = $this->crearProspecto(
            fechaInicioAgo: 40,
            ultimaEtapaNodeId: 'stage-DIA120',
        );

        $this->crearFees();

        $this->artisan('nurturing:repair-posicion', [
            '--flujo' => $this->flujo->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        // El valor NO debe haber cambiado
        $this->assertEquals('stage-DIA120', $pef->fresh()->ultima_etapa_node_id);
    }

    // ============================================================
    // T-RP-3: aborta si la ejecución NO está 'paused'
    // ============================================================

    #[Test]
    public function aborta_si_la_ejecucion_no_esta_paused(): void
    {
        // Cambiar a in_progress (no paused)
        $this->ejecucion->update(['estado' => 'in_progress']);

        $pef = $this->crearProspecto(
            fechaInicioAgo: 40,
            ultimaEtapaNodeId: 'stage-DIA120',
        );

        $this->artisan('nurturing:repair-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(1);

        // No debe haber cambiado nada
        $this->assertEquals('stage-DIA120', $pef->fresh()->ultima_etapa_node_id);
    }

    // ============================================================
    // T-RP-4: Idempotente — 2 corridas consecutivas = mismo resultado
    // ============================================================

    #[Test]
    public function idempotente_correr_dos_veces_da_mismo_resultado(): void
    {
        $pef = $this->crearProspecto(
            fechaInicioAgo: 40,
            ultimaEtapaNodeId: 'stage-DIA120',
        );

        $this->crearFees();

        // Primera corrida
        $this->artisan('nurturing:repair-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(0);

        $despuesPrimera = $pef->fresh()->ultima_etapa_node_id;

        // Segunda corrida
        $this->artisan('nurturing:repair-posicion', [
            '--flujo' => $this->flujo->id,
        ])->assertExitCode(0);

        $despuesSegunda = $pef->fresh()->ultima_etapa_node_id;

        // Ambas corridas deben dar el mismo resultado
        $this->assertEquals($despuesPrimera, $despuesSegunda);
        $this->assertEquals('stage-30', $despuesSegunda);
    }

    // ============================================================
    // Helper methods
    // ============================================================

    private function crearProspecto(int $fechaInicioAgo, string $ultimaEtapaNodeId): ProspectoEnFlujo
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => fake()->unique()->safeEmail(),
        ]);

        return ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'ultima_etapa_node_id' => $ultimaEtapaNodeId,
            'fecha_inicio' => now()->subDays($fechaInicioAgo),
            'completado' => false,
            'cancelado' => false,
        ]);
    }

    /**
     * Crea FlujoEjecucionEtapas para cada stage del flujo.
     * El comando las necesita para reconstruir el pivote etapa_prospecto.
     *
     * @return FlujoEjecucionEtapa[]
     */
    private function crearFees(): array
    {
        $fees = [];
        $stages = ['stage-0', 'stage-30', 'stage-60', 'stage-DIA120'];

        foreach ($stages as $nodeId) {
            $fees[$nodeId] = FlujoEjecucionEtapa::factory()->create([
                'flujo_ejecucion_id' => $this->ejecucion->id,
                'node_id' => $nodeId,
                'estado' => 'pending',
                'prospectos_ids' => [],
                'prospectos_count' => 0,
            ]);
        }

        return $fees;
    }

    /**
     * Flujo con 4 stages en cadena lineal:
     *   start-1 → stage-0 (0d) → stage-30 (30d) → stage-60 (60d) → stage-DIA120 (120d) → end-1
     *
     * Offsets acumulados:
     *   stage-0:      0d
     *   stage-30:    30d
     *   stage-60:    60d (30+30)
     *   stage-DIA120: 120d (30+30+60)
     */
    private function makeFourStageFlow(): array
    {
        return [
            'initial_node' => 'start-1',
            'stages' => [
                ['id' => 'start-1',      'type' => 'start', 'label' => 'Inicio',   'tiempo_espera' => 0],
                ['id' => 'stage-0',      'type' => 'email', 'label' => 'DÍA 0',    'tiempo_espera' => 0],
                ['id' => 'stage-30',     'type' => 'email', 'label' => 'DÍA 30',   'tiempo_espera' => 30],
                ['id' => 'stage-60',     'type' => 'email', 'label' => 'DÍA 60',   'tiempo_espera' => 30],
                ['id' => 'stage-DIA120', 'type' => 'email', 'label' => 'DÍA 120',  'tiempo_espera' => 60],
                ['id' => 'end-1',        'type' => 'end',   'label' => 'Fin',       'tiempo_espera' => 0],
            ],
            'branches' => [
                ['source_node_id' => 'start-1',      'target_node_id' => 'stage-0'],
                ['source_node_id' => 'stage-0',      'target_node_id' => 'stage-30'],
                ['source_node_id' => 'stage-30',     'target_node_id' => 'stage-60'],
                ['source_node_id' => 'stage-60',     'target_node_id' => 'stage-DIA120'],
                ['source_node_id' => 'stage-DIA120', 'target_node_id' => 'end-1'],
            ],
        ];
    }
}
