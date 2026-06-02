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
 * Guard: completitud bloqueada cuando quedan prospectos activos varados mid-flow.
 *
 * Reproduce BLOCKER 2 del incidente flujo 42:
 *   - Ejecución NO-perpetua alcanza end_node real (esEndNodeReal=true)
 *   - BUT hay prospectos activos cuya ultima_etapa_node_id tiene arista directa
 *     a un STAGE REAL (target != end-*, no en end_nodes, existe en stages)
 *   - ESPERADO: la ejecución NO debe marcarse 'completed'
 *
 * Spec:
 *   - stranded_guard: si ≥1 prospecto activo varado → NO completar, log warning
 *     con reason='completion_blocked_prospectos_stranded', retornar false.
 *   - legitimate_completion: si todos los activos están en etapa terminal (sin arista
 *     a stage real) → SÍ completar normalmente.
 */
class CompletitudProspectosVaradosTest extends TestCase
{
    use RefreshDatabase;

    private GuardedTransition $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = app(GuardedTransition::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Topología que replica flujo 42:
     *   start → MAIL1 → MAIL2 → SMS2 → end-1
     *
     * MAIL2 tiene arista directa a SMS2 (stage real).
     * SMS2 tiene arista directa a end-1 (end node).
     */
    private function buildFlujo42Structure(): array
    {
        return [
            'stages' => [
                ['id' => 'start-1',  'type' => 'start',  'tiempo_espera' => 0],
                ['id' => 'mail-1',   'type' => 'email',  'tiempo_espera' => 0],
                ['id' => 'mail-2',   'type' => 'email',  'tiempo_espera' => 30],
                ['id' => 'sms-2',    'type' => 'sms',    'tiempo_espera' => 15],
                ['id' => 'end-1',    'type' => 'end',    'tiempo_espera' => 0],
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

    private function createFlujoConEstructura(array $structure): Flujo
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        $user = User::factory()->create();

        return Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id'           => $user->id,
            'config_structure'  => $structure,
        ]);
    }

    private function createEjecucionInProgress(Flujo $flujo): FlujoEjecucion
    {
        return FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id'       => $flujo->id,
            'es_perpetuo'    => false,
            'prospectos_ids' => [],
        ]);
    }

    private function createProspectoActivo(Flujo $flujo, string $ultimaEtapaNodeId): ProspectoEnFlujo
    {
        $prospecto = Prospecto::factory()->create();

        return ProspectoEnFlujo::factory()->create([
            'prospecto_id'         => $prospecto->id,
            'flujo_id'             => $flujo->id,
            'ultima_etapa_node_id' => $ultimaEtapaNodeId,
            'completado'           => false,
            'cancelado'            => false,
            'fecha_inicio'         => now()->subDays(60),
        ]);
    }

    // -------------------------------------------------------------------------
    // TEST 1 — RED: ejecución NO debe completar cuando hay varados mid-flow
    // -------------------------------------------------------------------------

    /** @test */
    public function no_completa_cuando_hay_prospectos_activos_varados_mid_flow(): void
    {
        $flujo     = $this->createFlujoConEstructura($this->buildFlujo42Structure());
        $ejecucion = $this->createEjecucionInProgress($flujo);

        // La ejecución ya procesó todas sus FEEs y llegó al end-node real.
        FlujoEjecucionEtapa::factory()->completed()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id'            => 'sms-2',
        ]);

        // PERO hay un prospecto activo varado en MAIL2 (que tiene arista → SMS2, stage real)
        $pefVarado = $this->createProspectoActivo($flujo, 'mail-2');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) use ($ejecucion) {
                return isset($context['reason'])
                    && $context['reason'] === 'completion_blocked_prospectos_stranded'
                    && isset($context['ejecucion_id'])
                    && $context['ejecucion_id'] === $ejecucion->id
                    && isset($context['varados_count'])
                    && $context['varados_count'] >= 1;
            });

        // Además puede haber logs info — los ignoramos
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $resultado = $this->guard->finalizarSiAlcanzoEndNode(
            $ejecucion,
            'end-1',
            'test:varados:mid-flow'
        );

        $this->assertFalse($resultado, 'Debe retornar false cuando hay varados mid-flow');

        $ejecucion->refresh();
        $this->assertNotSame('completed', $ejecucion->estado, 'Estado no debe ser completed');
        $this->assertNull($ejecucion->fecha_fin, 'fecha_fin no debe setearse');
    }

    /** @test */
    public function no_completa_con_multiples_varados_en_distintas_etapas_mid_flow(): void
    {
        $flujo     = $this->createFlujoConEstructura($this->buildFlujo42Structure());
        $ejecucion = $this->createEjecucionInProgress($flujo);

        // Varado en mail-2 (tiene arista → sms-2, stage real)
        $this->createProspectoActivo($flujo, 'mail-2');
        // Varado en mail-1 (tiene arista → mail-2, stage real)
        $this->createProspectoActivo($flujo, 'mail-1');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return isset($context['reason'])
                    && $context['reason'] === 'completion_blocked_prospectos_stranded';
            });

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $resultado = $this->guard->finalizarSiAlcanzoEndNode(
            $ejecucion,
            'end-1',
            'test:varados:multiple'
        );

        $this->assertFalse($resultado);

        $ejecucion->refresh();
        $this->assertNotSame('completed', $ejecucion->estado);
    }

    // -------------------------------------------------------------------------
    // TEST 2 — completitud LEGÍTIMA: sin varados mid-flow → SÍ completa
    // -------------------------------------------------------------------------

    /** @test */
    public function completa_cuando_no_hay_prospectos_activos_varados(): void
    {
        $flujo     = $this->createFlujoConEstructura($this->buildFlujo42Structure());
        $ejecucion = $this->createEjecucionInProgress($flujo);

        FlujoEjecucionEtapa::factory()->completed()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id'            => 'sms-2',
        ]);

        // Prospecto ya completado — no cuenta como varado
        $prospecto = Prospecto::factory()->create();
        ProspectoEnFlujo::factory()->create([
            'prospecto_id'         => $prospecto->id,
            'flujo_id'             => $flujo->id,
            'ultima_etapa_node_id' => 'sms-2',
            'completado'           => true,
            'cancelado'            => false,
            'fecha_inicio'         => now()->subDays(60),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $resultado = $this->guard->finalizarSiAlcanzoEndNode(
            $ejecucion,
            'end-1',
            'test:sin-varados:completado'
        );

        $this->assertTrue($resultado, 'Debe completar cuando todos completaron');

        $ejecucion->refresh();
        $this->assertSame('completed', $ejecucion->estado);
        $this->assertNotNull($ejecucion->fecha_fin);
    }

    /** @test */
    public function completa_cuando_prospectos_activos_estan_en_ultima_etapa_antes_del_end(): void
    {
        $flujo     = $this->createFlujoConEstructura($this->buildFlujo42Structure());
        $ejecucion = $this->createEjecucionInProgress($flujo);

        FlujoEjecucionEtapa::factory()->completed()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id'            => 'sms-2',
        ]);

        // Prospecto activo en sms-2 — su siguiente es end-1 (no un stage real)
        // → NO es varado mid-flow, la arista va a end_node
        $pefTerminal = $this->createProspectoActivo($flujo, 'sms-2');

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $resultado = $this->guard->finalizarSiAlcanzoEndNode(
            $ejecucion,
            'end-1',
            'test:sin-varados:terminal'
        );

        $this->assertTrue($resultado, 'Prospecto en última etapa real (→ end) NO debe bloquear completitud');

        $ejecucion->refresh();
        $this->assertSame('completed', $ejecucion->estado);
        $this->assertNotNull($ejecucion->fecha_fin);
    }

    /** @test */
    public function completa_cuando_no_hay_prospectos_en_el_flujo(): void
    {
        $flujo     = $this->createFlujoConEstructura($this->buildFlujo42Structure());
        $ejecucion = $this->createEjecucionInProgress($flujo);

        // Sin prospectos en absoluto
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $resultado = $this->guard->finalizarSiAlcanzoEndNode(
            $ejecucion,
            'end-1',
            'test:sin-prospectos'
        );

        $this->assertTrue($resultado);

        $ejecucion->refresh();
        $this->assertSame('completed', $ejecucion->estado);
    }

    /** @test */
    public function prospectos_cancelados_no_bloquean_completitud(): void
    {
        $flujo     = $this->createFlujoConEstructura($this->buildFlujo42Structure());
        $ejecucion = $this->createEjecucionInProgress($flujo);

        // Prospecto cancelado en mail-2 — cancelado=true → no es activo varado
        $prospecto = Prospecto::factory()->create();
        ProspectoEnFlujo::factory()->create([
            'prospecto_id'         => $prospecto->id,
            'flujo_id'             => $flujo->id,
            'ultima_etapa_node_id' => 'mail-2',
            'completado'           => false,
            'cancelado'            => true,
            'fecha_inicio'         => now()->subDays(60),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $resultado = $this->guard->finalizarSiAlcanzoEndNode(
            $ejecucion,
            'end-1',
            'test:cancelados-no-bloquean'
        );

        $this->assertTrue($resultado);

        $ejecucion->refresh();
        $this->assertSame('completed', $ejecucion->estado);
    }
}
