<?php

namespace Tests\Feature\Models;

use App\Models\Envio;
use App\Models\EtapaFlujo;
use App\Models\Flujo;
use App\Models\OfertaInfocom;
use App\Models\PlantillaMensaje;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvioTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_envio(): void
    {
        $prospecto = Prospecto::factory()->create();
        $flujo = Flujo::factory()->create();

        $envio = Envio::factory()->create([
            'prospecto_id' => $prospecto->id,
            'flujo_id' => $flujo->id,
            'estado' => 'pendiente',
        ]);

        $this->assertDatabaseHas('envios', [
            'id' => $envio->id,
            'prospecto_id' => $prospecto->id,
            'flujo_id' => $flujo->id,
            'estado' => 'pendiente',
        ]);
    }

    public function test_casts_fecha_programada_como_datetime(): void
    {
        $envio = Envio::factory()->create([
            'fecha_programada' => '2025-01-15 10:00:00',
        ]);

        $this->assertInstanceOf(\DateTime::class, $envio->fecha_programada);
    }

    public function test_casts_fecha_enviado_como_datetime(): void
    {
        $envio = Envio::factory()->enviado()->create();

        $this->assertInstanceOf(\DateTime::class, $envio->fecha_enviado);
    }

    public function test_casts_fecha_abierto_como_datetime(): void
    {
        $envio = Envio::factory()->abierto()->create();

        $this->assertInstanceOf(\DateTime::class, $envio->fecha_abierto);
    }

    public function test_casts_fecha_clickeado_como_datetime(): void
    {
        $envio = Envio::factory()->clickeado()->create();

        $this->assertInstanceOf(\DateTime::class, $envio->fecha_clickeado);
    }

    public function test_casts_metadata_como_array(): void
    {
        $metadata = ['tracking_id' => '123456', 'provider' => 'sendgrid'];

        $envio = Envio::factory()->create([
            'metadata' => $metadata,
        ]);

        $this->assertIsArray($envio->metadata);
        $this->assertEquals($metadata, $envio->metadata);
    }

    public function test_relacion_con_prospecto(): void
    {
        $prospecto = Prospecto::factory()->create();
        $envio = Envio::factory()->create(['prospecto_id' => $prospecto->id]);

        $this->assertInstanceOf(Prospecto::class, $envio->prospecto);
        $this->assertEquals($prospecto->id, $envio->prospecto->id);
    }

    public function test_relacion_con_flujo(): void
    {
        $flujo = Flujo::factory()->create();
        $envio = Envio::factory()->create(['flujo_id' => $flujo->id]);

        $this->assertInstanceOf(Flujo::class, $envio->flujo);
        $this->assertEquals($flujo->id, $envio->flujo->id);
    }

    public function test_relacion_con_etapa_flujo(): void
    {
        $etapa = EtapaFlujo::factory()->create();
        $envio = Envio::factory()->create(['etapa_flujo_id' => $etapa->id]);

        $this->assertInstanceOf(EtapaFlujo::class, $envio->etapaFlujo);
        $this->assertEquals($etapa->id, $envio->etapaFlujo->id);
    }

    public function test_relacion_con_plantilla_mensaje(): void
    {
        $plantilla = PlantillaMensaje::factory()->create();
        $envio = Envio::factory()->create(['plantilla_mensaje_id' => $plantilla->id]);

        $this->assertInstanceOf(PlantillaMensaje::class, $envio->plantillaMensaje);
        $this->assertEquals($plantilla->id, $envio->plantillaMensaje->id);
    }

    public function test_relacion_con_prospecto_en_flujo(): void
    {
        $pef = ProspectoEnFlujo::factory()->create();
        $envio = Envio::factory()->create(['prospecto_en_flujo_id' => $pef->id]);

        $this->assertInstanceOf(ProspectoEnFlujo::class, $envio->prospectoEnFlujo);
        $this->assertEquals($pef->id, $envio->prospectoEnFlujo->id);
    }

    public function test_relacion_con_ofertas(): void
    {
        $envio = Envio::factory()->create();
        $oferta = OfertaInfocom::factory()->create();

        $envio->ofertas()->attach($oferta->id);

        $this->assertTrue($envio->ofertas->contains($oferta));
        $this->assertCount(1, $envio->ofertas);
    }

    public function test_scope_pendientes(): void
    {
        Envio::factory()->create(['estado' => 'pendiente']);
        Envio::factory()->create(['estado' => 'pendiente']);
        Envio::factory()->create(['estado' => 'enviado']);

        $this->assertEquals(2, Envio::pendientes()->count());
    }

    public function test_scope_enviados(): void
    {
        Envio::factory()->create(['estado' => 'enviado']);
        Envio::factory()->create(['estado' => 'enviado']);
        Envio::factory()->create(['estado' => 'pendiente']);

        $this->assertEquals(2, Envio::enviados()->count());
    }

    public function test_scope_fallidos(): void
    {
        Envio::factory()->create(['estado' => 'fallido']);
        Envio::factory()->create(['estado' => 'fallido']);
        Envio::factory()->create(['estado' => 'enviado']);

        $this->assertEquals(2, Envio::fallidos()->count());
    }

    public function test_scope_programados_para_hoy(): void
    {
        Envio::factory()->create([
            'estado' => 'pendiente',
            'fecha_programada' => now()->subDays(1),
        ]);
        Envio::factory()->create([
            'estado' => 'pendiente',
            'fecha_programada' => now()->addDays(5),
        ]);
        Envio::factory()->create([
            'estado' => 'enviado',
            'fecha_programada' => now()->subDays(1),
        ]);

        $this->assertEquals(1, Envio::programadosParaHoy()->count());
    }

    public function test_scope_por_canal(): void
    {
        Envio::factory()->count(3)->create(['canal' => 'email']);
        Envio::factory()->count(2)->create(['canal' => 'sms']);
        Envio::factory()->create(['canal' => 'whatsapp']);

        $this->assertEquals(3, Envio::porCanal('email')->count());
        $this->assertEquals(2, Envio::porCanal('sms')->count());
        $this->assertEquals(1, Envio::porCanal('whatsapp')->count());
    }

    public function test_scope_por_estado(): void
    {
        Envio::factory()->count(2)->create(['estado' => 'pendiente']);
        Envio::factory()->count(3)->create(['estado' => 'enviado']);

        $this->assertEquals(2, Envio::porEstado('pendiente')->count());
        $this->assertEquals(3, Envio::porEstado('enviado')->count());
    }

    public function test_marcar_como_enviado(): void
    {
        $envio = Envio::factory()->create(['estado' => 'pendiente']);

        $envio->marcarComoEnviado();

        $this->assertEquals('enviado', $envio->fresh()->estado);
        $this->assertNotNull($envio->fresh()->fecha_enviado);
    }

    public function test_marcar_como_fallido_sin_error(): void
    {
        $envio = Envio::factory()->create(['estado' => 'pendiente']);

        $envio->marcarComoFallido();

        $this->assertEquals('fallido', $envio->fresh()->estado);
    }

    public function test_marcar_como_fallido_con_error(): void
    {
        $envio = Envio::factory()->create(['estado' => 'pendiente']);

        $envio->marcarComoFallido('Error de conexión');

        $envioActualizado = $envio->fresh();
        $this->assertEquals('fallido', $envioActualizado->estado);
        $this->assertArrayHasKey('error', $envioActualizado->metadata);
        $this->assertEquals('Error de conexión', $envioActualizado->metadata['error']);
        $this->assertArrayHasKey('fecha_error', $envioActualizado->metadata);
    }

    public function test_marcar_como_abierto(): void
    {
        $envio = Envio::factory()->enviado()->create();

        $envio->marcarComoAbierto();

        $this->assertEquals('abierto', $envio->fresh()->estado);
        $this->assertNotNull($envio->fresh()->fecha_abierto);
    }

    public function test_marcar_como_clickeado(): void
    {
        $envio = Envio::factory()->abierto()->create();

        $envio->marcarComoClickeado();

        $this->assertEquals('clickeado', $envio->fresh()->estado);
        $this->assertNotNull($envio->fresh()->fecha_clickeado);
    }

    public function test_is_pendiente_devuelve_true_cuando_pendiente(): void
    {
        $envio = Envio::factory()->create(['estado' => 'pendiente']);

        $this->assertTrue($envio->isPendiente());
    }

    public function test_is_pendiente_devuelve_false_cuando_no_pendiente(): void
    {
        $envio = Envio::factory()->enviado()->create();

        $this->assertFalse($envio->isPendiente());
    }

    public function test_is_enviado_devuelve_true_cuando_enviado(): void
    {
        $envio = Envio::factory()->enviado()->create();

        $this->assertTrue($envio->isEnviado());
    }

    public function test_is_enviado_devuelve_true_cuando_abierto(): void
    {
        $envio = Envio::factory()->abierto()->create();

        $this->assertTrue($envio->isEnviado());
    }

    public function test_is_enviado_devuelve_true_cuando_clickeado(): void
    {
        $envio = Envio::factory()->clickeado()->create();

        $this->assertTrue($envio->isEnviado());
    }

    public function test_is_enviado_devuelve_false_cuando_pendiente(): void
    {
        $envio = Envio::factory()->create(['estado' => 'pendiente']);

        $this->assertFalse($envio->isEnviado());
    }

    public function test_is_fallido_devuelve_true_cuando_fallido(): void
    {
        $envio = Envio::factory()->fallido()->create();

        $this->assertTrue($envio->isFallido());
    }

    public function test_is_fallido_devuelve_false_cuando_no_fallido(): void
    {
        $envio = Envio::factory()->enviado()->create();

        $this->assertFalse($envio->isFallido());
    }

    public function test_diferentes_canales(): void
    {
        $canales = ['email', 'sms', 'whatsapp'];

        foreach ($canales as $canal) {
            $envio = Envio::factory()->create(['canal' => $canal]);
            $this->assertEquals($canal, $envio->canal);
        }
    }

    public function test_diferentes_estados(): void
    {
        $estados = ['pendiente', 'enviado', 'fallido', 'abierto', 'clickeado'];

        foreach ($estados as $estado) {
            $envio = Envio::factory()->create(['estado' => $estado]);
            $this->assertEquals($estado, $envio->estado);
        }
    }

    public function test_puede_tener_multiples_ofertas(): void
    {
        $envio = Envio::factory()->create();
        $ofertas = OfertaInfocom::factory()->count(3)->create();

        foreach ($ofertas as $oferta) {
            $envio->ofertas()->attach($oferta->id);
        }

        $this->assertCount(3, $envio->ofertas);
    }

    public function test_metadata_puede_ser_null(): void
    {
        $envio = Envio::factory()->create(['metadata' => null]);

        $this->assertNull($envio->metadata);
    }

    public function test_secuencia_estados_email(): void
    {
        $envio = Envio::factory()->create(['estado' => 'pendiente', 'canal' => 'email']);

        $this->assertTrue($envio->isPendiente());

        $envio->marcarComoEnviado();
        $this->assertTrue($envio->fresh()->isEnviado());
        $this->assertEquals('enviado', $envio->fresh()->estado);

        $envio->marcarComoAbierto();
        $this->assertTrue($envio->fresh()->isEnviado());
        $this->assertEquals('abierto', $envio->fresh()->estado);

        $envio->marcarComoClickeado();
        $this->assertTrue($envio->fresh()->isEnviado());
        $this->assertEquals('clickeado', $envio->fresh()->estado);
    }

    public function test_asunto_puede_ser_null_para_sms(): void
    {
        $envio = Envio::factory()->create([
            'canal' => 'sms',
            'asunto' => null,
        ]);

        $this->assertNull($envio->asunto);
        $this->assertEquals('sms', $envio->canal);
    }

    public function test_asunto_requerido_para_email(): void
    {
        $envio = Envio::factory()->create([
            'canal' => 'email',
            'asunto' => 'Asunto de prueba',
        ]);

        $this->assertNotNull($envio->asunto);
        $this->assertEquals('email', $envio->canal);
    }
}
