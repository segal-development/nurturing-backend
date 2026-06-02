<?php

namespace Tests\Feature\GuardedTransition;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\GuardedTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regresión flujo 42: ejecución con FEE completada cuya siguiente etapa no existe
 * (creación lazy de FEEs) Y el nodo siguiente no es un end_node REAL
 * → la ejecución NO debe marcarse como 'completed'.
 *
 * Scenario: NO completed por ausencia de FEE downstream (spec §completitud)
 */
class CompletitudSinEndNodeTest extends TestCase
{
    use RefreshDatabase;

    private GuardedTransition $guard;
    private FlujoEjecucion $ejecucion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(GuardedTransition::class);

        $tipoProspecto = TipoProspecto::factory()->create();
        $user = User::factory()->create();

        // Flujo 42: MAIL 2 → SMS 2 (stage, no end_node)
        // La FEE de SMS 2 nunca fue creada (lazy creation)
        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $user->id,
            'config_structure' => $this->buildFlujo42Structure(),
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujo->id,
            'es_perpetuo' => false,
            'prospectos_ids' => [],
        ]);

        // MAIL 2 está completada
        FlujoEjecucionEtapa::factory()->completed()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'mail-2',
        ]);
        // SMS 2 FEE NO existe (lazy creation — es el bug del flujo 42)
    }

    /** @test */
    public function no_se_marca_completed_cuando_siguiente_nodo_no_es_end_node(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'completion_blocked_no_end_node')
                    || (isset($context['reason']) && $context['reason'] === 'completion_blocked_no_end_node');
            });

        // 'sms-2' es el siguiente nodo, pero NO es un end_node
        $completado = $this->guard->finalizarSiAlcanzoEndNode(
            $this->ejecucion,
            'sms-2',
            'test:flujo42'
        );

        $this->assertFalse($completado, 'No debe haber completado la ejecución');

        $this->ejecucion->refresh();
        $this->assertNotSame('completed', $this->ejecucion->estado);
    }

    /** @test */
    public function no_se_marca_completed_cuando_siguiente_nodo_es_null(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'completion_blocked_no_end_node')
                    || (isset($context['reason']) && $context['reason'] === 'completion_blocked_no_end_node');
            });

        // Llamar con nextNodeId=null (sin conexión, pero no es end_node real)
        $completado = $this->guard->finalizarSiAlcanzoEndNode(
            $this->ejecucion,
            null,
            'test:flujo42:null'
        );

        $this->assertFalse($completado);

        $this->ejecucion->refresh();
        $this->assertNotSame('completed', $this->ejecucion->estado);
    }

    // -------------------------------------------------------------------------

    private function buildFlujo42Structure(): array
    {
        return [
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'tiempo_espera' => 0],
                ['id' => 'mail-1',  'type' => 'email',  'tiempo_espera' => 0],
                ['id' => 'mail-2',  'type' => 'email',  'tiempo_espera' => 30],
                ['id' => 'sms-2',   'type' => 'sms',    'tiempo_espera' => 15],
                ['id' => 'end-1',   'type' => 'end',    'tiempo_espera' => 0],
            ],
            'branches' => [
                ['source_node_id' => 'start-1', 'target_node_id' => 'mail-1'],
                ['source_node_id' => 'mail-1',  'target_node_id' => 'mail-2'],
                ['source_node_id' => 'mail-2',  'target_node_id' => 'sms-2'],
                ['source_node_id' => 'sms-2',   'target_node_id' => 'end-1'],
            ],
            'initial_node' => 'start-1',
        ];
    }
}
