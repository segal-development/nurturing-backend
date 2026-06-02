<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EnviarEtapaJob;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * TDD RED → GREEN: gate temporal en EnviarEtapaJob (path normal, <=5000 prospectos).
 *
 * Cierra el gap detectado por sdd-verify: el gate temporal estaba en EnviarEtapaChunkJob
 * (alto volumen) pero NO en obtenerProspectosEnFlujo del path normal, así que un flujo
 * perpetuo con carga baja seguía mandando mails prematuros (reproduce flujo 39 en carga baja).
 *
 * Gate: now() >= fecha_inicio + offset_acumulado(etapaDestino). Mismo criterio que el ChunkJob.
 */
class EnviarEtapaJobGateTemporalTest extends TestCase
{
    use RefreshDatabase;

    private TipoProspecto $tipoProspecto;

    private User $user;

    private Flujo $flujo;

    private FlujoEjecucion $ejecucion;

    private FlujoEjecucionEtapa $etapaEjecucion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();

        // Flujo perpetuo: stage-1 (día 0) → stage-2 (día 60).
        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
            'config_structure' => $this->createTwoStageFlowWith60DayOffset(),
        ]);

        // Ejecución perpetua posicionada en stage-2 (la etapa con offset de 60 días).
        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'nodo_actual' => 'stage-2',
            'prospectos_ids' => [],
        ]);

        $this->etapaEjecucion = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'stage-2',
            'estado' => 'pending',
        ]);
    }

    /** @test */
    public function prospecto_muy_joven_no_se_encola_en_path_normal(): void
    {
        Bus::fake();

        // fecha_inicio = 40 días atrás, stage-2 requiere 60 → NO elegible.
        $joven = $this->crearProspecto(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 40);

        $this->dispatchStage2([$joven->prospecto_id]);

        Bus::assertNothingBatched();
    }

    /** @test */
    public function prospecto_con_suficiente_antiguedad_si_se_encola(): void
    {
        Bus::fake();

        // fecha_inicio = 70 días atrás, stage-2 requiere 60 → elegible.
        $mayor = $this->crearProspecto(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 70);

        $this->dispatchStage2([$mayor->prospecto_id]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    /** @test */
    public function mezcla_solo_encola_los_maduros(): void
    {
        Bus::fake();

        $j1 = $this->crearProspecto(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 30);
        $j2 = $this->crearProspecto(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 55);
        $m1 = $this->crearProspecto(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 61);
        $m2 = $this->crearProspecto(ultimaEtapaNodeId: 'stage-1', fechaInicioAgo: 90);

        $this->dispatchStage2([
            $j1->prospecto_id,
            $j2->prospecto_id,
            $m1->prospecto_id,
            $m2->prospecto_id,
        ]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
    }

    // ============================================
    // Helpers
    // ============================================

    private function crearProspecto(string $ultimaEtapaNodeId, int $fechaInicioAgo): ProspectoEnFlujo
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

    private function dispatchStage2(array $prospectoIds): void
    {
        $job = new EnviarEtapaJob(
            flujoEjecucionId: $this->ejecucion->id,
            etapaEjecucionId: $this->etapaEjecucion->id,
            stage: [
                'id' => 'stage-2',
                'type' => 'email',
                'label' => 'Etapa 2 (60 días)',
                'tipo_mensaje' => 'email',
                'plantilla_mensaje' => 'Content stage 2',
                'template' => ['asunto' => 'Stage 2'],
            ],
            prospectoIds: $prospectoIds,
        );

        $job->handle();
    }

    private function createTwoStageFlowWith60DayOffset(): array
    {
        return [
            'initial_node' => 'start-1',
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'label' => 'Inicio', 'tiempo_espera' => 0],
                ['id' => 'stage-1', 'type' => 'email', 'label' => 'Etapa 1', 'tiempo_espera' => 0],
                ['id' => 'stage-2', 'type' => 'email', 'label' => 'Etapa 2', 'tiempo_espera' => 60],
                ['id' => 'end-1', 'type' => 'end', 'label' => 'Fin', 'tiempo_espera' => 0],
            ],
            'branches' => [
                ['source_node_id' => 'start-1', 'target_node_id' => 'stage-1'],
                ['source_node_id' => 'stage-1', 'target_node_id' => 'stage-2'],
                ['source_node_id' => 'stage-2', 'target_node_id' => 'end-1'],
            ],
        ];
    }
}
