<?php

namespace Tests\Unit\Models;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FlujoEjecucionEtapaTest extends TestCase
{
    use RefreshDatabase;

    // ============================================
    // TESTS: Model validation for node_id
    // ============================================

    /** @test */
    public function creating_etapa_without_node_id_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FlujoEjecucionEtapa requires nodo_id or node_id');

        $ejecucion = FlujoEjecucion::factory()->create();

        // Try to create etapa with null node_id
        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => null,
            'fecha_programada' => now(),
            'estado' => 'pending',
        ]);
    }

    /** @test */
    public function creating_etapa_with_empty_string_node_id_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FlujoEjecucionEtapa requires nodo_id or node_id');

        $ejecucion = FlujoEjecucion::factory()->create();

        FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => '',
            'fecha_programada' => now(),
            'estado' => 'pending',
        ]);
    }

    /** @test */
    public function creating_etapa_with_valid_node_id_succeeds(): void
    {
        $ejecucion = FlujoEjecucion::factory()->create();

        $etapa = FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => 'valid-node-123',
            'fecha_programada' => now(),
            'estado' => 'pending',
        ]);

        $this->assertNotNull($etapa->id);
        $this->assertEquals('valid-node-123', $etapa->node_id);
    }

    /** @test */
    public function updating_etapa_to_remove_node_id_throws_exception(): void
    {
        $etapa = FlujoEjecucionEtapa::factory()->create([
            'node_id' => 'original-node-id',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FlujoEjecucionEtapa requires nodo_id or node_id');

        $etapa->node_id = null;
        $etapa->save();
    }

    /** @test */
    public function updating_etapa_keeps_valid_node_id(): void
    {
        $etapa = FlujoEjecucionEtapa::factory()->create([
            'node_id' => 'original-node-id',
        ]);

        $etapa->node_id = 'new-node-id';
        $etapa->save();

        $this->assertEquals('new-node-id', $etapa->fresh()->node_id);
    }

    // ============================================
    // TESTS: Existing functionality not broken
    // ============================================

    /** @test */
    public function factory_creates_valid_etapa(): void
    {
        $etapa = FlujoEjecucionEtapa::factory()->create();

        $this->assertNotNull($etapa->id);
        $this->assertNotNull($etapa->node_id);
        $this->assertNotNull($etapa->fecha_programada);
    }

    /** @test */
    public function etapa_belongs_to_ejecucion(): void
    {
        $ejecucion = FlujoEjecucion::factory()->create();
        $etapa = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
        ]);

        $this->assertInstanceOf(FlujoEjecucion::class, $etapa->ejecucion);
        $this->assertEquals($ejecucion->id, $etapa->ejecucion->id);
    }

    /** @test */
    public function scope_pendientes_works(): void
    {
        $ejecucion = FlujoEjecucion::factory()->create();

        FlujoEjecucionEtapa::factory()->count(2)->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
        ]);

        FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'completed',
        ]);

        $pendientes = FlujoEjecucionEtapa::pendientes()->get();

        $this->assertCount(2, $pendientes);
    }
}
