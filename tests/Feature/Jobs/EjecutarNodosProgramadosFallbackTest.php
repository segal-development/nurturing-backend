<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EjecutarNodosProgramados;
use App\Jobs\EnviarEtapaJob;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\EnvioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TDD RED → GREEN: Fallback poblacional residual en EjecutarNodosProgramados
 *
 * Spec: cuando la FEE de una etapa NO-primera tiene pivote vacío, el scheduler
 * NO debe dumpear la población completa de la ejecución como lista de prospectos.
 * Debe despachar con lista VACÍA (fail-safe) y logear Log::warning con
 * 'empty_pivot_fee_scheduler'.
 *
 * Esto es la misma clase de bug que eliminamos del FlujoEjecucionEtapaObserver
 * en Fase 1. Ver AsignarProspectosAEjecucionPerpetua: la primera etapa siempre
 * tiene su pivote poblado explícitamente via syncWithoutDetaching. El fallback
 * es NUNCA legítimo.
 *
 * Test scenarios:
 *   T-ENP-FB-1: FEE no-primera con pivote vacío → dispatch con 0 prospectos
 *   T-ENP-FB-2: FEE con pivote poblado → dispatch normal con los prospectos correctos
 *   T-ENP-FB-3: path avance/recovery (L919) con FEE no-primera vacía → 0 prospectos en siguiente FEE
 */
class EjecutarNodosProgramadosFallbackTest extends TestCase
{
    use RefreshDatabase;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

    private FlujoEjecucion $ejecucion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user          = User::factory()->create();

        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id'           => $this->user->id,
            'config_structure'  => $this->makeTwoStageFlow(),
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id'          => $this->flujo->id,
            'es_perpetuo'       => true,
            'prospectos_ids'    => [],
            'proximo_nodo'      => 'stage-30',
            'fecha_proximo_nodo' => now()->subMinutes(1),
        ]);
    }

    // ============================================================
    // T-ENP-FB-1: FEE no-primera con pivote vacío → dispatch vacío
    // ============================================================

    #[Test]
    public function fee_no_primera_con_pivote_vacio_no_dumpea_poblacion(): void
    {
        // 3 prospectos en la ejecución (la "población")
        $prospectos = Prospecto::factory()->count(3)->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);
        foreach ($prospectos as $p) {
            ProspectoEnFlujo::factory()->porEmail()->create([
                'flujo_id'            => $this->flujo->id,
                'prospecto_id'        => $p->id,
                'ultima_etapa_node_id' => 'stage-0',
                'fecha_inicio'        => now()->subDays(35),
            ]);
        }
        $this->ejecucion->prospectos()->sync($prospectos->pluck('id')->toArray());
        $this->ejecucion->update([
            'prospectos_ids'   => $prospectos->pluck('id')->toArray(),
            'prospectos_count' => 3,
        ]);

        // FEE stage-30 EXIST pero con pivote VACÍO (sin prospectos asociados)
        $fee = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id'            => 'stage-30',
            'estado'             => 'pending',
            'prospectos_ids'     => [],
            'prospectos_count'   => 0,
            'fecha_programada'   => now()->subMinutes(2),
            'ejecutado'          => false,
        ]);
        // NO sincronizamos prospectos al pivote → está deliberadamente vacío

        Bus::fake();
        Log::spy();

        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));

        // El EnviarEtapaJob NO debe haber sido despachado con prospectos de la población
        Bus::assertDispatched(EnviarEtapaJob::class, function (EnviarEtapaJob $dispatched) {
            // El job debe haberse despachado con 0 prospectos (fail-safe), NO con 3
            $ids = $this->getEnviarEtapaJobProspectoIds($dispatched);

            return count($ids) === 0;
        });

        // Debe loguear warning de pivote vacío suprimido
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                return str_contains($message, 'empty_pivot_fee_scheduler')
                    || (isset($context['reason']) && str_contains($context['reason'], 'empty_pivot'));
            });
    }

    // ============================================================
    // T-ENP-FB-2: FEE con pivote poblado → dispatch normal
    // ============================================================

    #[Test]
    public function fee_con_pivote_poblado_despacha_normalmente(): void
    {
        // 2 prospectos en la ejecución y en el pivote de stage-30
        $prospectos = Prospecto::factory()->count(2)->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);
        foreach ($prospectos as $p) {
            ProspectoEnFlujo::factory()->porEmail()->create([
                'flujo_id'             => $this->flujo->id,
                'prospecto_id'         => $p->id,
                'ultima_etapa_node_id' => 'stage-0',
                'fecha_inicio'         => now()->subDays(35),
            ]);
        }
        $this->ejecucion->prospectos()->sync($prospectos->pluck('id')->toArray());
        $this->ejecucion->update([
            'prospectos_ids'   => $prospectos->pluck('id')->toArray(),
            'prospectos_count' => 2,
        ]);

        // FEE stage-30 con pivote POBLADO (2 prospectos)
        $fee = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id'            => 'stage-30',
            'estado'             => 'pending',
            'prospectos_ids'     => $prospectos->pluck('id')->toArray(),
            'prospectos_count'   => 2,
            'fecha_programada'   => now()->subMinutes(2),
            'ejecutado'          => false,
        ]);
        // Poblar el pivote correctamente
        $fee->prospectos()->sync($prospectos->pluck('id')->toArray());

        Bus::fake();

        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));

        // El job debe haberse despachado con los 2 prospectos correctos
        Bus::assertDispatched(EnviarEtapaJob::class, function (EnviarEtapaJob $dispatched) use ($prospectos) {
            $ids = $this->getEnviarEtapaJobProspectoIds($dispatched);

            return count($ids) === 2;
        });
    }

    // ============================================================
    // T-ENP-FB-3: path avance (L919) — FEE vacía → 0 en siguiente FEE
    // ============================================================

    #[Test]
    public function path_avance_con_fee_vacia_no_propaga_poblacion(): void
    {
        // Este test verifica el segundo fallback (L919-921): cuando el scheduler
        // construye la siguiente FEE para un avance y la FEE actual tiene pivote
        // vacío, NO debe propagar la población completa a la siguiente etapa.

        // Ejecución con 3 prospectos
        $prospectos = Prospecto::factory()->count(3)->create(['tipo_prospecto_id' => $this->tipoProspecto->id]);
        foreach ($prospectos as $p) {
            ProspectoEnFlujo::factory()->porEmail()->create([
                'flujo_id'             => $this->flujo->id,
                'prospecto_id'         => $p->id,
                'ultima_etapa_node_id' => null,
                'fecha_inicio'         => now()->subDays(35),
            ]);
        }
        $this->ejecucion->prospectos()->sync($prospectos->pluck('id')->toArray());
        $this->ejecucion->update([
            'prospectos_ids'   => $prospectos->pluck('id')->toArray(),
            'prospectos_count' => 3,
            'proximo_nodo'     => 'stage-0',
            'fecha_proximo_nodo' => now()->subMinutes(1),
        ]);

        // FEE stage-0 con pivote vacío (recién creada, sin prospectos)
        $feeStage0 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id'            => 'stage-0',
            'estado'             => 'pending',
            'prospectos_ids'     => [],
            'prospectos_count'   => 0,
            'fecha_programada'   => now()->subMinutes(2),
            'ejecutado'          => false,
        ]);
        // FEE stage-30 ya existe y también vacía
        $feeStage30 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id'            => 'stage-30',
            'estado'             => 'pending',
            'prospectos_ids'     => [],
            'prospectos_count'   => 0,
            'fecha_programada'   => now()->addDays(30),
            'ejecutado'          => false,
        ]);

        Bus::fake();
        Log::spy();

        $job = new EjecutarNodosProgramados();
        $job->handle(app(EnvioService::class));

        // El dispatch de stage-0 debe tener 0 prospectos (pivote vacío → fail-safe)
        Bus::assertDispatched(EnviarEtapaJob::class, function (EnviarEtapaJob $dispatched) {
            $ids = $this->getEnviarEtapaJobProspectoIds($dispatched);

            return count($ids) === 0;
        });

        // La FEE stage-30 (siguiente) tampoco debe haber recibido prospectos de la población
        $feeStage30->refresh();
        $this->assertSame(0, $feeStage30->prospectos()->count(),
            'La FEE de la siguiente etapa no debe recibir prospectos via fallback poblacional'
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                return str_contains($message, 'empty_pivot_fee_scheduler')
                    || (isset($context['reason']) && str_contains($context['reason'], 'empty_pivot'));
            });
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Extrae el array prospectoIds del EnviarEtapaJob via reflexión.
     */
    private function getEnviarEtapaJobProspectoIds(EnviarEtapaJob $job): array
    {
        $ref  = new \ReflectionClass($job);
        $prop = $ref->getProperty('prospectoIds');
        $prop->setAccessible(true);

        return $prop->getValue($job) ?? [];
    }

    /**
     * Flujo simple con 2 etapas ejecutables:
     *   start → stage-0 (0d) → stage-30 (30d) → end-1
     */
    private function makeTwoStageFlow(): array
    {
        return [
            'initial_node' => 'start-1',
            'stages'       => [
                ['id' => 'start-1',  'type' => 'start', 'label' => 'Inicio',  'tiempo_espera' => 0],
                ['id' => 'stage-0',  'type' => 'email', 'label' => 'Día 0',   'tiempo_espera' => 0],
                ['id' => 'stage-30', 'type' => 'email', 'label' => 'Día 30',  'tiempo_espera' => 30],
                ['id' => 'end-1',    'type' => 'end',   'label' => 'Fin',     'tiempo_espera' => 0],
            ],
            'branches'     => [
                ['source_node_id' => 'start-1',  'target_node_id' => 'stage-0'],
                ['source_node_id' => 'stage-0',  'target_node_id' => 'stage-30'],
                ['source_node_id' => 'stage-30', 'target_node_id' => 'end-1'],
            ],
        ];
    }
}
