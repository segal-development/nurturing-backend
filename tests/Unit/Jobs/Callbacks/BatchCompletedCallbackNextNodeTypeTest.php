<?php

namespace Tests\Unit\Jobs\Callbacks;

use App\Jobs\Callbacks\BatchCompletedCallback;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * BatchCompletedCallback must not warn about "unknown node type" for the
 * real stage types (sms, email, ambos): their next node is scheduled by
 * FlujoEjecucionEtapaObserver, so the callback only logs at debug level.
 */
class BatchCompletedCallbackNextNodeTypeTest extends TestCase
{
    use RefreshDatabase;

    private FlujoEjecucion $ejecucion;

    private FlujoEjecucionEtapa $etapa;

    protected function setUp(): void
    {
        parent::setUp();

        $flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => TipoProspecto::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $flujo->id,
            'es_perpetuo' => false,
            'prospectos_ids' => [],
        ]);

        $this->etapa = FlujoEjecucionEtapa::factory()->completed()->create([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'source-1',
        ]);
    }

    /**
     * @test
     *
     * @dataProvider stageTypes
     */
    public function stage_types_log_debug_without_warning_or_scheduling(string $type): void
    {
        Log::spy();
        $etapasBefore = FlujoEjecucionEtapa::count();

        $this->procesarNodoSiguiente($type, 'next-1');

        Log::shouldNotHaveReceived('warning');
        Log::shouldHaveReceived('debug')->once()->with(
            'BatchCompletedCallback: Next node scheduled by FlujoEjecucionEtapaObserver',
            ['tipo' => $type, 'node_id' => 'next-1', 'ejecucion_id' => $this->ejecucion->id],
        );
        $this->assertSame($etapasBefore, FlujoEjecucionEtapa::count());
        $this->assertNull($this->ejecucion->fresh()->proximo_nodo);
    }

    public static function stageTypes(): array
    {
        return [['sms'], ['email'], ['ambos']];
    }

    /** @test */
    public function unknown_type_still_logs_warning(): void
    {
        Log::spy();

        $this->procesarNodoSiguiente('foo', 'next-1');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('BatchCompletedCallback: Tipo de nodo desconocido', ['tipo' => 'foo']);
        Log::shouldNotHaveReceived('debug');
    }

    /** @test */
    public function stage_type_still_schedules_next_stage(): void
    {
        $this->procesarNodoSiguiente('stage', 'next-1');

        $this->assertDatabaseHas('flujo_ejecucion_etapas', [
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'node_id' => 'next-1',
            'estado' => 'pending',
        ]);
        $this->assertSame('next-1', $this->ejecucion->fresh()->proximo_nodo);
    }

    /** @test */
    public function end_type_still_finalizes_the_flow(): void
    {
        $this->procesarNodoSiguiente('end', 'end-1');

        $this->assertSame('completed', $this->ejecucion->fresh()->estado);
    }

    private function procesarNodoSiguiente(string $type, string $nodeId): void
    {
        $callback = new BatchCompletedCallback([
            'flujo_ejecucion_id' => $this->ejecucion->id,
            'etapa_ejecucion_id' => $this->etapa->id,
        ]);

        $method = new ReflectionMethod($callback, 'procesarNodoSiguiente');
        $method->invoke(
            $callback,
            $this->ejecucion,
            $this->etapa,
            ['id' => $nodeId, 'type' => $type],
            $nodeId,
            ['source_node_id' => 'source-1', 'target_node_id' => $nodeId],
            12345,
            [],
            [],
        );
    }
}
