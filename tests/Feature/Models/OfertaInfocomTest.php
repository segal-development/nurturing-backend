<?php

namespace Tests\Feature\Models;

use App\Models\Envio;
use App\Models\EtapaFlujo;
use App\Models\OfertaInfocom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfertaInfocomTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_oferta_infocom(): void
    {
        $oferta = OfertaInfocom::factory()->create([
            'nombre' => 'Oferta Especial',
            'activo' => true,
        ]);

        $this->assertDatabaseHas('ofertas_infocom', [
            'id' => $oferta->id,
            'nombre' => 'Oferta Especial',
            'activo' => 1,
        ]);
    }

    public function test_casts_fecha_inicio_como_date(): void
    {
        $oferta = OfertaInfocom::factory()->create([
            'fecha_inicio' => '2025-01-01',
        ]);

        $this->assertInstanceOf(\DateTime::class, $oferta->fecha_inicio);
    }

    public function test_casts_fecha_fin_como_date(): void
    {
        $oferta = OfertaInfocom::factory()->create([
            'fecha_fin' => '2025-12-31',
        ]);

        $this->assertInstanceOf(\DateTime::class, $oferta->fecha_fin);
    }

    public function test_casts_activo_como_boolean(): void
    {
        $oferta = OfertaInfocom::factory()->create(['activo' => true]);

        $this->assertIsBool($oferta->activo);
        $this->assertTrue($oferta->activo);
    }

    public function test_casts_metadata_como_array(): void
    {
        $metadata = ['tipo' => 'descuento', 'valor' => '50%'];

        $oferta = OfertaInfocom::factory()->create([
            'metadata' => $metadata,
        ]);

        $this->assertIsArray($oferta->metadata);
        $this->assertEquals($metadata, $oferta->metadata);
    }

    public function test_relacion_con_etapas(): void
    {
        $oferta = OfertaInfocom::factory()->create();
        $etapa = EtapaFlujo::factory()->create();

        $oferta->etapas()->attach($etapa->id, ['orden' => 1, 'activo' => true]);

        $this->assertTrue($oferta->etapas->contains($etapa));
        $this->assertCount(1, $oferta->etapas);
    }

    public function test_relacion_con_envios(): void
    {
        $oferta = OfertaInfocom::factory()->create();
        $envio = Envio::factory()->create();

        $oferta->envios()->attach($envio->id);

        $this->assertTrue($oferta->envios->contains($envio));
        $this->assertCount(1, $oferta->envios);
    }

    public function test_scope_activos(): void
    {
        OfertaInfocom::factory()->create(['activo' => true]);
        OfertaInfocom::factory()->create(['activo' => true]);
        OfertaInfocom::factory()->create(['activo' => false]);

        $this->assertEquals(2, OfertaInfocom::activos()->count());
    }

    public function test_scope_vigentes(): void
    {
        OfertaInfocom::factory()->create([
            'fecha_inicio' => now()->subDays(10),
            'fecha_fin' => now()->addDays(10),
        ]);
        OfertaInfocom::factory()->create([
            'fecha_inicio' => now()->subDays(20),
            'fecha_fin' => now()->subDays(5),
        ]);

        $this->assertEquals(1, OfertaInfocom::vigentes()->count());
    }

    public function test_scope_disponibles(): void
    {
        OfertaInfocom::factory()->create([
            'activo' => true,
            'fecha_inicio' => now()->subDays(10),
            'fecha_fin' => now()->addDays(10),
        ]);
        OfertaInfocom::factory()->create([
            'activo' => false,
            'fecha_inicio' => now()->subDays(10),
            'fecha_fin' => now()->addDays(10),
        ]);
        OfertaInfocom::factory()->create([
            'activo' => true,
            'fecha_inicio' => now()->subDays(30),
            'fecha_fin' => now()->subDays(10),
        ]);

        $this->assertEquals(1, OfertaInfocom::disponibles()->count());
    }

    public function test_is_vigente_devuelve_true_cuando_vigente(): void
    {
        $oferta = OfertaInfocom::factory()->create([
            'fecha_inicio' => now()->subDays(10),
            'fecha_fin' => now()->addDays(10),
        ]);

        $this->assertTrue($oferta->isVigente());
    }

    public function test_is_vigente_devuelve_false_cuando_vencido(): void
    {
        $oferta = OfertaInfocom::factory()->vencido()->create();

        $this->assertFalse($oferta->isVigente());
    }

    public function test_is_vigente_devuelve_false_cuando_futuro(): void
    {
        $oferta = OfertaInfocom::factory()->futuro()->create();

        $this->assertFalse($oferta->isVigente());
    }

    public function test_is_disponible_devuelve_true_cuando_activo_y_vigente(): void
    {
        $oferta = OfertaInfocom::factory()->create([
            'activo' => true,
            'fecha_inicio' => now()->subDays(10),
            'fecha_fin' => now()->addDays(10),
        ]);

        $this->assertTrue($oferta->isDisponible());
    }

    public function test_is_disponible_devuelve_false_cuando_inactivo(): void
    {
        $oferta = OfertaInfocom::factory()->inactivo()->create([
            'fecha_inicio' => now()->subDays(10),
            'fecha_fin' => now()->addDays(10),
        ]);

        $this->assertFalse($oferta->isDisponible());
    }

    public function test_is_disponible_devuelve_false_cuando_no_vigente(): void
    {
        $oferta = OfertaInfocom::factory()->create([
            'activo' => true,
            'fecha_inicio' => now()->subDays(30),
            'fecha_fin' => now()->subDays(10),
        ]);

        $this->assertFalse($oferta->isDisponible());
    }

    public function test_fecha_inicio_null_siempre_valida(): void
    {
        $oferta = OfertaInfocom::factory()->create([
            'fecha_inicio' => null,
            'fecha_fin' => now()->addDays(10),
        ]);

        $this->assertTrue($oferta->isVigente());
    }

    public function test_fecha_fin_null_siempre_valida(): void
    {
        $oferta = OfertaInfocom::factory()->create([
            'fecha_inicio' => now()->subDays(10),
            'fecha_fin' => null,
        ]);

        $this->assertTrue($oferta->isVigente());
    }

    public function test_ambas_fechas_null_siempre_vigente(): void
    {
        $oferta = OfertaInfocom::factory()->create([
            'fecha_inicio' => null,
            'fecha_fin' => null,
        ]);

        $this->assertTrue($oferta->isVigente());
    }
}
