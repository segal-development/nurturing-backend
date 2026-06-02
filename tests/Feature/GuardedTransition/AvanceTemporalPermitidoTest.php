<?php

namespace Tests\Feature\GuardedTransition;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\GuardedTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario: avance permitido al cumplirse el tiempo (spec §guarded-transition)
 *
 * Mismo prospecto que AvanceTemporalBloqueadoTest pero con 60+ días → AVANZA.
 */
class AvanceTemporalPermitidoTest extends TestCase
{
    use RefreshDatabase;

    private GuardedTransition $guard;
    private Flujo $flujo;
    private FlujoEjecucion $ejecucion;
    private FlujoEjecucionEtapa $feeDia60;
    private ProspectoEnFlujo $pef;
    private Prospecto $prospecto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(GuardedTransition::class);

        $tipoProspecto = TipoProspecto::factory()->create();
        $user = User::factory()->create();

        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $user->id,
            'nombre' => 'Flujo 39 Test - Permitido',
            'config_structure' => $this->buildFlujo39Structure(),
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'prospectos_ids' => [],
        ]);

        $feeDia0 = FlujoEjecucionEtapa::factory()->completed()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'dia-0',
        ]);

        $this->feeDia60 = FlujoEjecucionEtapa::factory()->pending()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'dia-60',
        ]);

        // Prospecto con 65 días de antigüedad → offset 60 CUMPLIDO
        $this->prospecto = Prospecto::factory()->create();
        $this->pef = ProspectoEnFlujo::factory()->create([
            'prospecto_id' => $this->prospecto->id,
            'flujo_id' => $this->flujo->id,
            'ultima_etapa_node_id' => 'dia-0',
            'fecha_inicio' => now()->subDays(65),
        ]);

        $feeDia0->prospectos()->sync([$this->prospecto->id]);
        $this->feeDia60->prospectos()->sync([$this->prospecto->id]);
    }

    /** @test */
    public function prospecto_con_65_dias_avanza_a_dia_60(): void
    {
        $avanzados = $this->guard->avanzarProspectos(
            $this->ejecucion,
            'dia-60',
            [$this->pef->id]
        );

        $this->assertCount(1, $avanzados, 'Debe haber avanzado exactamente 1 prospecto');
        $this->assertContains($this->pef->id, $avanzados);

        // Posición actualizada
        $this->pef->refresh();
        $this->assertSame('dia-60', $this->pef->ultima_etapa_node_id);
    }

    /** @test */
    public function puede_avanzar_retorna_true_para_prospecto_con_65_dias(): void
    {
        $puedeAvanzar = $this->guard->puedeAvanzar(
            $this->flujo,
            $this->pef,
            'dia-60'
        );

        $this->assertTrue($puedeAvanzar);
    }

    // -------------------------------------------------------------------------

    private function buildFlujo39Structure(): array
    {
        return [
            'stages' => [
                ['id' => 'start-1', 'type' => 'start',  'tiempo_espera' => 0],
                ['id' => 'dia-0',   'type' => 'email',   'tiempo_espera' => 0],
                ['id' => 'dia-60',  'type' => 'email',   'tiempo_espera' => 60],
                ['id' => 'dia-120', 'type' => 'email',   'tiempo_espera' => 60],
                ['id' => 'end-1',   'type' => 'end',     'tiempo_espera' => 0],
            ],
            'branches' => [
                ['source_node_id' => 'start-1', 'target_node_id' => 'dia-0'],
                ['source_node_id' => 'dia-0',   'target_node_id' => 'dia-60'],
                ['source_node_id' => 'dia-60',  'target_node_id' => 'dia-120'],
                ['source_node_id' => 'dia-120', 'target_node_id' => 'end-1'],
            ],
            'initial_node' => 'start-1',
        ];
    }
}
