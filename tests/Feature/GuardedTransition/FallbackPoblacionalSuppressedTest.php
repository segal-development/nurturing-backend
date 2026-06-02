<?php

namespace Tests\Feature\GuardedTransition;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Scenario: FEE completada con pivote vacío → observer NO sincroniza prospectos
 * de ejecucion->prospectos() (fallback poblacional suprimido).
 *
 * Spec: GIVEN FEE que pasa a completed AND pivote etapa_prospecto vacío
 * THEN siguiente FEE NO se sincroniza con prospectos del fallback
 * AND Log::warning con 'empty_pivot_fallback_suppressed'.
 */
class FallbackPoblacionalSuppressedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function observer_no_usa_fallback_poblacional_cuando_pivote_vacio(): void
    {
        $tipoProspecto = TipoProspecto::factory()->create();
        $user = User::factory()->create();

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => $user->id,
            'config_structure' => [
                'stages' => [
                    ['id' => 'start-1', 'type' => 'start', 'tiempo_espera' => 0],
                    ['id' => 'mail-1',  'type' => 'email',  'tiempo_espera' => 0],
                    ['id' => 'mail-2',  'type' => 'email',  'tiempo_espera' => 30],
                    ['id' => 'end-1',   'type' => 'end',    'tiempo_espera' => 0],
                ],
                'branches' => [
                    ['source_node_id' => 'start-1', 'target_node_id' => 'mail-1'],
                    ['source_node_id' => 'mail-1',  'target_node_id' => 'mail-2'],
                    ['source_node_id' => 'mail-2',  'target_node_id' => 'end-1'],
                ],
                'initial_node' => 'start-1',
            ],
        ]);

        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujo->id,
            'es_perpetuo' => true,
            'prospectos_ids' => [],
        ]);

        // Crear 3 prospectos vinculados a la ejecución
        $prospectos = Prospecto::factory()->count(3)->create();
        $pefs = $prospectos->map(fn ($p) => ProspectoEnFlujo::factory()->create([
            'prospecto_id' => $p->id,
            'flujo_id' => $flujo->id,
            'ultima_etapa_node_id' => 'mail-1',
            'fecha_inicio' => now()->subDays(10),
        ]));
        $ejecucion->prospectos()->sync($prospectos->pluck('id')->toArray());

        // FEE mail-1 con pivote vacío (no tiene prospectos asociados en la relación pivot)
        $feeMail1 = FlujoEjecucionEtapa::factory()->pending()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => 'mail-1',
            'prospectos_ids' => [],
            'prospectos_count' => 0,
        ]);
        // NO sincronizar prospectos al pivote — está deliberadamente vacío

        // FEE mail-2 existente (para verificar que no se contamina con prospectos del fallback)
        $feeMail2 = FlujoEjecucionEtapa::factory()->pending()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => 'mail-2',
            'prospectos_ids' => [],
            'prospectos_count' => 0,
        ]);

        Log::spy();

        // Disparar el observer: marcar mail-1 como completed
        // Esto dispara FlujoEjecucionEtapaObserver::updated
        $feeMail1->update(['estado' => 'completed']);

        // FEE mail-2 NO debe recibir prospectos del fallback poblacional
        $feeMail2->refresh();
        $prospectosMail2 = $feeMail2->prospectos()->count();

        $this->assertSame(0, $prospectosMail2,
            'La FEE mail-2 no debe recibir prospectos via fallback poblacional cuando el pivote de mail-1 estaba vacío'
        );

        // Verificar que se logueó el warning de fallback suprimido
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'empty_pivot_fallback_suppressed')
                    || (isset($context['reason']) && $context['reason'] === 'empty_pivot_fallback_suppressed');
            });
    }
}
