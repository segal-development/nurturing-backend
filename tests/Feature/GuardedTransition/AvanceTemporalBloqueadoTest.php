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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regresión flujo 39: prospecto con 40 días de antigüedad no debe avanzar
 * a una etapa cuyo offset acumulado es 60 días.
 *
 * Scenario: avance bloqueado por tiempo insuficiente (spec §guarded-transition)
 */
class AvanceTemporalBloqueadoTest extends TestCase
{
    use RefreshDatabase;

    private GuardedTransition $guard;
    private Flujo $flujo;
    private FlujoEjecucion $ejecucion;
    private FlujoEjecucionEtapa $feeDia60;
    private ProspectoEnFlujo $pef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(GuardedTransition::class);

        $tipoProspecto = TipoProspecto::factory()->create();
        $user = User::factory()->create();

        // Flujo 39: DÍA 0 (tiempo_espera=0) → DÍA 60 (tiempo_espera=60) → ...
        // offset_acumulado(DÍA 60) = 0 + 60 = 60 días
        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $user->id,
            'nombre' => 'Flujo 39 Test',
            'config_structure' => $this->buildFlujo39Structure(),
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'prospectos_ids' => [],
        ]);

        // FEE para DÍA 0 (completada)
        $feeDia0 = FlujoEjecucionEtapa::factory()->completed()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'dia-0',
        ]);

        // FEE para DÍA 60 (pending — siguiente etapa)
        $this->feeDia60 = FlujoEjecucionEtapa::factory()->pending()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'dia-60',
        ]);

        // Prospecto con 40 días de antigüedad en el flujo
        // fecha_inicio hace 40 días → offset 60 NO se cumple
        $prospecto = Prospecto::factory()->create();
        $this->pef = ProspectoEnFlujo::factory()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_id' => $this->flujo->id,
            'ultima_etapa_node_id' => 'dia-0',
            'fecha_inicio' => now()->subDays(40),
        ]);

        // Vincular prospecto a la FEE de DÍA 0
        $feeDia0->prospectos()->sync([$prospecto->id]);
        $this->feeDia60->prospectos()->sync([$prospecto->id]);
    }

    /** @test */
    public function prospecto_con_40_dias_no_avanza_a_dia_60(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'temporal_gate_failed')
                    || (isset($context['reason']) && $context['reason'] === 'temporal_gate_failed');
            });

        $avanzados = $this->guard->avanzarProspectos(
            $this->ejecucion,
            'dia-60',
            [$this->pef->id]
        );

        $this->assertEmpty($avanzados, 'No debería haber avanzado ningún prospecto');

        // Recargar y verificar que no cambió posición
        $this->pef->refresh();
        $this->assertSame('dia-0', $this->pef->ultima_etapa_node_id);
    }

    /** @test */
    public function puede_avanzar_retorna_false_para_prospecto_con_40_dias(): void
    {
        $puedeAvanzar = $this->guard->puedeAvanzar(
            $this->flujo,
            $this->pef,
            'dia-60'
        );

        $this->assertFalse($puedeAvanzar);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildFlujo39Structure(): array
    {
        return [
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'tiempo_espera' => 0],
                ['id' => 'dia-0',  'type' => 'email',  'tiempo_espera' => 0],
                ['id' => 'dia-60', 'type' => 'email',  'tiempo_espera' => 60],
                ['id' => 'dia-120','type' => 'email',  'tiempo_espera' => 60],
                ['id' => 'end-1',  'type' => 'end',    'tiempo_espera' => 0],
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
