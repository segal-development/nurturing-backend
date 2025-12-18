<?php

namespace Tests\Feature\Models;

use App\Models\Envio;
use App\Models\EtapaFlujo;
use App\Models\Flujo;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProspectoEnFlujoTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_prospecto_en_flujo(): void
    {
        $prospecto = Prospecto::factory()->create();
        $flujo = Flujo::factory()->create();
        $etapa = EtapaFlujo::factory()->create(['flujo_id' => $flujo->id]);

        $pef = ProspectoEnFlujo::factory()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_id' => $flujo->id,
            'etapa_actual_id' => $etapa->id,
        ]);

        $this->assertDatabaseHas('prospecto_en_flujo', [
            'id' => $pef->id,
            'prospecto_id' => $prospecto->id,
            'flujo_id' => $flujo->id,
            'etapa_actual_id' => $etapa->id,
        ]);
    }

    public function test_casts_fecha_inicio_como_datetime(): void
    {
        $pef = ProspectoEnFlujo::factory()->create([
            'fecha_inicio' => '2025-01-01 10:00:00',
        ]);

        $this->assertInstanceOf(\DateTime::class, $pef->fecha_inicio);
    }

    public function test_casts_fecha_proxima_etapa_como_datetime(): void
    {
        $pef = ProspectoEnFlujo::factory()->create([
            'fecha_proxima_etapa' => '2025-02-01 10:00:00',
        ]);

        $this->assertInstanceOf(\DateTime::class, $pef->fecha_proxima_etapa);
    }

    public function test_casts_completado_como_boolean(): void
    {
        $pef = ProspectoEnFlujo::factory()->create(['completado' => false]);

        $this->assertIsBool($pef->completado);
        $this->assertFalse($pef->completado);
    }

    public function test_casts_cancelado_como_boolean(): void
    {
        $pef = ProspectoEnFlujo::factory()->create(['cancelado' => false]);

        $this->assertIsBool($pef->cancelado);
        $this->assertFalse($pef->cancelado);
    }

    public function test_relacion_con_prospecto(): void
    {
        $prospecto = Prospecto::factory()->create();
        $pef = ProspectoEnFlujo::factory()->create(['prospecto_id' => $prospecto->id]);

        $this->assertInstanceOf(Prospecto::class, $pef->prospecto);
        $this->assertEquals($prospecto->id, $pef->prospecto->id);
    }

    public function test_relacion_con_flujo(): void
    {
        $flujo = Flujo::factory()->create();
        $pef = ProspectoEnFlujo::factory()->create(['flujo_id' => $flujo->id]);

        $this->assertInstanceOf(Flujo::class, $pef->flujo);
        $this->assertEquals($flujo->id, $pef->flujo->id);
    }

    public function test_relacion_con_etapa_actual(): void
    {
        $etapa = EtapaFlujo::factory()->create();
        $pef = ProspectoEnFlujo::factory()->create(['etapa_actual_id' => $etapa->id]);

        $this->assertInstanceOf(EtapaFlujo::class, $pef->etapaActual);
        $this->assertEquals($etapa->id, $pef->etapaActual->id);
    }

    public function test_relacion_con_envios(): void
    {
        $pef = ProspectoEnFlujo::factory()->create();
        Envio::factory()->count(2)->create(['prospecto_en_flujo_id' => $pef->id]);

        $this->assertCount(2, $pef->envios);
    }

    public function test_scope_activos(): void
    {
        ProspectoEnFlujo::factory()->create(['completado' => false, 'cancelado' => false]);
        ProspectoEnFlujo::factory()->create(['completado' => false, 'cancelado' => false]);
        ProspectoEnFlujo::factory()->create(['completado' => true, 'cancelado' => false]);
        ProspectoEnFlujo::factory()->create(['completado' => false, 'cancelado' => true]);

        $this->assertEquals(2, ProspectoEnFlujo::activos()->count());
    }

    public function test_scope_completados(): void
    {
        ProspectoEnFlujo::factory()->create(['completado' => true]);
        ProspectoEnFlujo::factory()->create(['completado' => true]);
        ProspectoEnFlujo::factory()->create(['completado' => false]);

        $this->assertEquals(2, ProspectoEnFlujo::completados()->count());
    }

    public function test_scope_cancelados(): void
    {
        ProspectoEnFlujo::factory()->create(['cancelado' => true]);
        ProspectoEnFlujo::factory()->create(['cancelado' => false]);
        ProspectoEnFlujo::factory()->create(['cancelado' => true]);

        $this->assertEquals(2, ProspectoEnFlujo::cancelados()->count());
    }

    public function test_scope_por_flujo(): void
    {
        $flujo1 = Flujo::factory()->create();
        $flujo2 = Flujo::factory()->create();

        ProspectoEnFlujo::factory()->count(3)->create(['flujo_id' => $flujo1->id]);
        ProspectoEnFlujo::factory()->count(2)->create(['flujo_id' => $flujo2->id]);

        $this->assertEquals(3, ProspectoEnFlujo::porFlujo($flujo1->id)->count());
        $this->assertEquals(2, ProspectoEnFlujo::porFlujo($flujo2->id)->count());
    }

    public function test_scope_proximos_envios(): void
    {
        ProspectoEnFlujo::factory()->create([
            'completado' => false,
            'cancelado' => false,
            'fecha_proxima_etapa' => now()->subDays(1),
        ]);
        ProspectoEnFlujo::factory()->create([
            'completado' => false,
            'cancelado' => false,
            'fecha_proxima_etapa' => now()->addDays(5),
        ]);
        ProspectoEnFlujo::factory()->create([
            'completado' => true,
            'cancelado' => false,
            'fecha_proxima_etapa' => now()->subDays(1),
        ]);

        $this->assertEquals(1, ProspectoEnFlujo::proximosEnvios()->count());
    }

    public function test_avanzar_etapa(): void
    {
        $flujo = Flujo::factory()->create();
        $etapa1 = EtapaFlujo::factory()->create(['flujo_id' => $flujo->id, 'dias_desde_inicio' => 15]);
        $etapa2 = EtapaFlujo::factory()->create(['flujo_id' => $flujo->id, 'dias_desde_inicio' => 30]);

        $pef = ProspectoEnFlujo::factory()->create([
            'flujo_id' => $flujo->id,
            'etapa_actual_id' => $etapa1->id,
            'fecha_inicio' => now()->subDays(15),
        ]);

        $pef->avanzarEtapa($etapa2);

        $this->assertEquals($etapa2->id, $pef->fresh()->etapa_actual_id);
        $this->assertNotNull($pef->fresh()->fecha_proxima_etapa);
    }

    public function test_completar(): void
    {
        $pef = ProspectoEnFlujo::factory()->create([
            'completado' => false,
            'fecha_proxima_etapa' => now()->addDays(5),
        ]);

        $pef->completar();

        $pefActualizado = $pef->fresh();
        $this->assertTrue($pefActualizado->completado);
        $this->assertNull($pefActualizado->fecha_proxima_etapa);
    }

    public function test_cancelar(): void
    {
        $pef = ProspectoEnFlujo::factory()->create([
            'cancelado' => false,
            'fecha_proxima_etapa' => now()->addDays(5),
        ]);

        $pef->cancelar();

        $pefActualizado = $pef->fresh();
        $this->assertTrue($pefActualizado->cancelado);
        $this->assertNull($pefActualizado->fecha_proxima_etapa);
    }

    public function test_is_activo_devuelve_true_cuando_activo(): void
    {
        $pef = ProspectoEnFlujo::factory()->create([
            'completado' => false,
            'cancelado' => false,
        ]);

        $this->assertTrue($pef->isActivo());
    }

    public function test_is_activo_devuelve_false_cuando_completado(): void
    {
        $pef = ProspectoEnFlujo::factory()->completado()->create();

        $this->assertFalse($pef->isActivo());
    }

    public function test_is_activo_devuelve_false_cuando_cancelado(): void
    {
        $pef = ProspectoEnFlujo::factory()->cancelado()->create();

        $this->assertFalse($pef->isActivo());
    }

    public function test_is_activo_devuelve_false_cuando_completado_y_cancelado(): void
    {
        $pef = ProspectoEnFlujo::factory()->create([
            'completado' => true,
            'cancelado' => true,
        ]);

        $this->assertFalse($pef->isActivo());
    }

    public function test_fecha_proxima_etapa_puede_ser_null(): void
    {
        $pef = ProspectoEnFlujo::factory()->create([
            'fecha_proxima_etapa' => null,
        ]);

        $this->assertNull($pef->fecha_proxima_etapa);
    }

    public function test_puede_tener_multiples_envios(): void
    {
        $pef = ProspectoEnFlujo::factory()->create();
        Envio::factory()->count(5)->create(['prospecto_en_flujo_id' => $pef->id]);

        $this->assertCount(5, $pef->envios);
    }

    public function test_completar_no_afecta_campo_cancelado(): void
    {
        $pef = ProspectoEnFlujo::factory()->create([
            'completado' => false,
            'cancelado' => false,
        ]);

        $pef->completar();

        $this->assertTrue($pef->fresh()->completado);
        $this->assertFalse($pef->fresh()->cancelado);
    }

    public function test_cancelar_no_afecta_campo_completado(): void
    {
        $pef = ProspectoEnFlujo::factory()->create([
            'completado' => false,
            'cancelado' => false,
        ]);

        $pef->cancelar();

        $this->assertTrue($pef->fresh()->cancelado);
        $this->assertFalse($pef->fresh()->completado);
    }
}
