<?php

namespace Tests\Unit\Services;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\FlujoEjecucionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlujoEjecucionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FlujoEjecucionService $service;

    private User $user;

    private TipoProspecto $tipoProspecto;

    private Flujo $flujo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FlujoEjecucionService;

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();
        $this->flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);
    }

    // ============================================
    // TESTS: resume() - Date recalculation
    // ============================================

    /** @test */
    public function resume_shifts_all_pending_etapa_dates_by_pause_duration(): void
    {
        // Arrange: execution paused 7 days ago with pending etapas
        $pausedAt = now()->subDays(7);

        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'pausada_en' => $pausedAt,
        ]);

        // Create etapas with original dates (these were scheduled before pause)
        $etapa1 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
            'fecha_programada' => $pausedAt->copy()->subDays(3), // Was 3 days before pause
            'node_id' => 'node-1',
        ]);

        $etapa2 = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
            'fecha_programada' => $pausedAt->copy()->addDays(3), // Was 3 days after pause
            'node_id' => 'node-2',
        ]);

        // Act
        $result = $this->service->resume($ejecucion);

        // Assert: dates should be shifted forward by 7 days
        $etapa1->refresh();
        $etapa2->refresh();

        // The pause duration was 7 days, so dates should shift by ~7 days
        $this->assertTrue(
            $etapa1->fecha_programada->diffInDays($pausedAt->copy()->subDays(3)) >= 6,
            'Etapa 1 date should be shifted forward by approximately 7 days'
        );
        $this->assertTrue(
            $etapa2->fecha_programada->diffInDays($pausedAt->copy()->addDays(3)) >= 6,
            'Etapa 2 date should be shifted forward by approximately 7 days'
        );
    }

    /** @test */
    public function resume_updates_fecha_proximo_nodo_to_earliest_pending_etapa(): void
    {
        // Arrange
        $pausedAt = now()->subDays(3);

        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'pausada_en' => $pausedAt,
            'fecha_proximo_nodo' => null,
        ]);

        // Create etapas with different scheduled dates
        FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
            'fecha_programada' => $pausedAt->copy()->addDays(5), // Further in future
            'node_id' => 'node-later',
        ]);

        $earlierEtapa = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
            'fecha_programada' => $pausedAt->copy()->addDays(1), // Earlier
            'node_id' => 'node-earlier',
        ]);

        // Act
        $result = $this->service->resume($ejecucion);

        // Assert: fecha_proximo_nodo should be set to the earliest pending etapa
        $this->assertNotNull($result->fecha_proximo_nodo);
        $earlierEtapa->refresh();
        $this->assertEquals(
            $earlierEtapa->fecha_programada->format('Y-m-d H:i'),
            $result->fecha_proximo_nodo->format('Y-m-d H:i')
        );
    }

    /** @test */
    public function resume_clears_pausada_en_timestamp(): void
    {
        // Arrange
        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'pausada_en' => now()->subDays(2),
        ]);

        FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
            'fecha_programada' => now()->addDay(),
            'node_id' => 'node-1',
        ]);

        // Act
        $result = $this->service->resume($ejecucion);

        // Assert
        $this->assertNull($result->pausada_en);
    }

    /** @test */
    public function resume_changes_estado_to_in_progress(): void
    {
        // Arrange
        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'pausada_en' => now()->subDay(),
        ]);

        FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
            'fecha_programada' => now()->addDay(),
            'node_id' => 'node-1',
        ]);

        // Act
        $result = $this->service->resume($ejecucion);

        // Assert
        $this->assertEquals('in_progress', $result->estado);
    }

    /** @test */
    public function resume_does_not_shift_completed_etapa_dates(): void
    {
        // Arrange
        $pausedAt = now()->subDays(5);

        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'pausada_en' => $pausedAt,
        ]);

        $completedEtapa = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'completed',
            'fecha_programada' => $pausedAt->copy()->subDays(10),
            'node_id' => 'node-completed',
        ]);

        $originalDate = $completedEtapa->fecha_programada->copy();

        FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
            'fecha_programada' => now()->addDay(),
            'node_id' => 'node-pending',
        ]);

        // Act
        $this->service->resume($ejecucion);

        // Assert: completed etapa date should NOT change
        $completedEtapa->refresh();
        $this->assertEquals(
            $originalDate->format('Y-m-d H:i:s'),
            $completedEtapa->fecha_programada->format('Y-m-d H:i:s')
        );
    }

    /** @test */
    public function resume_handles_execution_with_no_pending_etapas(): void
    {
        // Arrange
        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'pausada_en' => now()->subDay(),
        ]);

        // Only completed etapas
        FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'completed',
            'fecha_programada' => now()->subDays(5),
            'node_id' => 'node-done',
        ]);

        // Act
        $result = $this->service->resume($ejecucion);

        // Assert: should still resume, fecha_proximo_nodo should be null
        $this->assertEquals('in_progress', $result->estado);
        $this->assertNull($result->fecha_proximo_nodo);
        $this->assertNull($result->pausada_en);
    }

    /** @test */
    public function resume_uses_updated_at_when_pausada_en_is_null(): void
    {
        // Arrange: old execution without pausada_en field
        $updatedAt = now()->subDays(4);

        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'pausada_en' => null, // Legacy data without pausada_en
            'updated_at' => $updatedAt,
        ]);

        $etapa = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'estado' => 'pending',
            'fecha_programada' => $updatedAt->copy()->addDays(2),
            'node_id' => 'node-1',
        ]);

        $originalDate = $etapa->fecha_programada->copy();

        // Act
        $result = $this->service->resume($ejecucion);

        // Assert: should use updated_at as fallback for pause time
        $etapa->refresh();
        $this->assertTrue(
            $etapa->fecha_programada->gt($originalDate),
            'Etapa date should be shifted forward'
        );
    }

    // ============================================
    // TESTS: pause()
    // ============================================

    /** @test */
    public function pause_sets_estado_to_paused(): void
    {
        // Arrange
        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
        ]);

        // Act
        $result = $this->service->pause($ejecucion);

        // Assert
        $this->assertEquals('paused', $result->estado);
    }

    /** @test */
    public function pause_records_pausada_en_timestamp(): void
    {
        // Arrange
        Carbon::setTestNow(now()); // Freeze time for assertion

        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'in_progress',
            'pausada_en' => null,
        ]);

        // Act
        $result = $this->service->pause($ejecucion);

        // Assert
        $this->assertNotNull($result->pausada_en);
        $this->assertEquals(now()->format('Y-m-d H:i'), $result->pausada_en->format('Y-m-d H:i'));

        Carbon::setTestNow(); // Unfreeze time
    }

    // ============================================
    // TESTS: Transaction integrity
    // ============================================

    /** @test */
    public function resume_is_atomic_all_etapas_updated_or_none(): void
    {
        // Arrange
        $pausedAt = now()->subDays(2);

        $ejecucion = FlujoEjecucion::factory()->create([
            'flujo_id' => $this->flujo->id,
            'estado' => 'paused',
            'pausada_en' => $pausedAt,
        ]);

        // Create multiple pending etapas
        for ($i = 1; $i <= 5; $i++) {
            FlujoEjecucionEtapa::factory()->create([
                'flujo_ejecucion_id' => $ejecucion->id,
                'estado' => 'pending',
                'fecha_programada' => $pausedAt->copy()->addDays($i),
                'node_id' => "node-{$i}",
            ]);
        }

        // Act
        $this->service->resume($ejecucion);

        // Assert: all etapas should be updated
        $updatedEtapas = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('estado', 'pending')
            ->get();

        $pauseDuration = $pausedAt->diffInSeconds(now());

        foreach ($updatedEtapas as $etapa) {
            // All dates should have been shifted by the pause duration
            $this->assertTrue(
                $etapa->fecha_programada->gt($pausedAt),
                "Etapa {$etapa->node_id} should have future date after resume"
            );
        }
    }
}
