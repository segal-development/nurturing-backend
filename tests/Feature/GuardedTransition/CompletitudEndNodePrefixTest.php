<?php

namespace Tests\Feature\GuardedTransition;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\GuardedTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario: completed legítimo al alcanzar end_node real con prefijo 'end-'.
 *
 * Spec: GIVEN transición a nodo con id 'end-1' THEN completed con fecha_fin.
 */
class CompletitudEndNodePrefixTest extends TestCase
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

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $user->id,
            'config_structure' => [
                'stages' => [
                    ['id' => 'start-1', 'type' => 'start', 'tiempo_espera' => 0],
                    ['id' => 'mail-1',  'type' => 'email',  'tiempo_espera' => 0],
                    ['id' => 'end-1',   'type' => 'end',    'tiempo_espera' => 0],
                ],
                'branches' => [
                    ['source_node_id' => 'start-1', 'target_node_id' => 'mail-1'],
                    ['source_node_id' => 'mail-1',  'target_node_id' => 'end-1'],
                ],
                'initial_node' => 'start-1',
            ],
        ]);

        // Ejecución no-perpetua, en progreso
        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujo->id,
            'es_perpetuo' => false,
            'prospectos_ids' => [],
        ]);

        // MAIL 1 completada
        FlujoEjecucionEtapa::factory()->completed()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'mail-1',
        ]);
    }

    /** @test */
    public function se_marca_completed_al_alcanzar_nodo_end_prefix(): void
    {
        $completado = $this->guard->finalizarSiAlcanzoEndNode(
            $this->ejecucion,
            'end-1',
            'test:end_prefix'
        );

        $this->assertTrue($completado, 'Debe haber completado la ejecución');

        $this->ejecucion->refresh();
        $this->assertSame('completed', $this->ejecucion->estado);
        $this->assertNotNull($this->ejecucion->fecha_fin);
    }
}
