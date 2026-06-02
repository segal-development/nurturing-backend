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
 * Scenario: completed legítimo por cfg['end_nodes'] sin prefijo 'end-'.
 *
 * El nodo 'terminus-node' no tiene prefijo 'end-' pero está listado en
 * config_structure['end_nodes'] → debe marcar la ejecución como completed.
 */
class CompletitudEndNodeCfgTest extends TestCase
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
                    ['id' => 'start-1',      'type' => 'start',  'tiempo_espera' => 0],
                    ['id' => 'mail-1',       'type' => 'email',  'tiempo_espera' => 0],
                    // nodo final SIN prefijo end- pero declarado en end_nodes
                    ['id' => 'terminus-node','type' => 'stage',  'tiempo_espera' => 0],
                ],
                'branches' => [
                    ['source_node_id' => 'start-1', 'target_node_id' => 'mail-1'],
                    ['source_node_id' => 'mail-1',  'target_node_id' => 'terminus-node'],
                ],
                'initial_node' => 'start-1',
                // Declaración explícita de end_nodes en config_structure
                'end_nodes' => ['terminus-node'],
            ],
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujo->id,
            'es_perpetuo' => false,
            'prospectos_ids' => [],
        ]);

        FlujoEjecucionEtapa::factory()->completed()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'mail-1',
        ]);
    }

    /** @test */
    public function se_marca_completed_cuando_nodo_esta_en_cfg_end_nodes(): void
    {
        $completado = $this->guard->finalizarSiAlcanzoEndNode(
            $this->ejecucion,
            'terminus-node',
            'test:cfg_end_nodes'
        );

        $this->assertTrue($completado, 'Debe marcar completed por cfg end_nodes');

        $this->ejecucion->refresh();
        $this->assertSame('completed', $this->ejecucion->estado);
        $this->assertNotNull($this->ejecucion->fecha_fin);
    }
}
