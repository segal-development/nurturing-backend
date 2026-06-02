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
 * Scenario: avance bloqueado por posición incorrecta — salta etapa intermedia.
 *
 * Prospecto en DÍA 0 con 180 días de antigüedad no puede saltar directamente
 * a DÍA 120 (saltando DÍA 60) → position_guard_failed.
 */
class AvancePosicionIncorrectaTest extends TestCase
{
    use RefreshDatabase;

    private GuardedTransition $guard;
    private Flujo $flujo;
    private FlujoEjecucion $ejecucion;
    private ProspectoEnFlujo $pef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(GuardedTransition::class);

        $tipoProspecto = TipoProspecto::factory()->create();
        $user = User::factory()->create();

        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $user->id,
            'config_structure' => $this->buildFlowStructure(),
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'prospectos_ids' => [],
        ]);

        FlujoEjecucionEtapa::factory()->pending()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'dia-120',
        ]);

        // Prospecto en DÍA 0 con 180 días (temporalmente elegible para cualquier etapa)
        // pero POSICIÓN es DÍA 0 → no puede saltar a DÍA 120
        $prospecto = Prospecto::factory()->create();
        $this->pef = ProspectoEnFlujo::factory()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_id' => $this->flujo->id,
            'ultima_etapa_node_id' => 'dia-0',
            'fecha_inicio' => now()->subDays(180),
        ]);
    }

    /** @test */
    public function no_puede_saltar_etapas_intermedias(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'position_guard_failed')
                    || (isset($context['reason']) && $context['reason'] === 'position_guard_failed');
            });

        $avanzados = $this->guard->avanzarProspectos(
            $this->ejecucion,
            'dia-120',  // salta DÍA 60
            [$this->pef->id]
        );

        $this->assertEmpty($avanzados);

        $this->pef->refresh();
        $this->assertSame('dia-0', $this->pef->ultima_etapa_node_id);
    }

    /** @test */
    public function puede_avanzar_retorna_true_temporalmente_pero_avanzar_prospectos_rechaza_posicion(): void
    {
        // puedeAvanzar es un gate temporal PURO — el prospecto lleva 180 días, offset de
        // día-120 = 120 días → temporalmente elegible. El guard de posición es separado
        // y vive en avanzarProspectos (o guardPosicion).
        $puedeAvanzar = $this->guard->puedeAvanzar(
            $this->flujo,
            $this->pef,
            'dia-120'
        );

        // 180 días >= offset 120 días → temporalmente pasa
        $this->assertTrue($puedeAvanzar, 'puedeAvanzar es gate temporal puro, no verifica posición');

        // Sin embargo avanzarProspectos RECHAZA porque viola el guard de posición
        // (actual=dia-0, target=dia-120 requiere actual=dia-60)
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'position_guard_failed')
                    || (isset($context['reason']) && $context['reason'] === 'position_guard_failed');
            });

        $avanzados = $this->guard->avanzarProspectos(
            $this->ejecucion,
            'dia-120',
            [$this->pef->id]
        );

        $this->assertEmpty($avanzados);
        $this->pef->refresh();
        $this->assertSame('dia-0', $this->pef->ultima_etapa_node_id);
    }

    // -------------------------------------------------------------------------

    private function buildFlowStructure(): array
    {
        return [
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'tiempo_espera' => 0],
                ['id' => 'dia-0',   'type' => 'email',  'tiempo_espera' => 0],
                ['id' => 'dia-60',  'type' => 'email',  'tiempo_espera' => 60],
                ['id' => 'dia-120', 'type' => 'email',  'tiempo_espera' => 60],
                ['id' => 'end-1',   'type' => 'end',    'tiempo_espera' => 0],
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
