<?php

namespace Tests\Feature\Metricas;

use App\Models\Envio;
use App\Models\Flujo;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Services\MetricasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifica que getNoRecibieronDetalle clasifique correctamente a los prospectos que
 * ENTRARON al flujo pero NO recibieron el email (la respuesta para gerencia sobre
 * "a dónde se fueron"). Cubre cada razón.
 */
class NoRecibieronDetalleTest extends TestCase
{
    use RefreshDatabase;

    private function nuevoProspectoEnFlujo(Flujo $flujo, array $prospAttrs = []): Prospecto
    {
        $prospecto = Prospecto::factory()->create(array_merge([
            'rut' => substr(uniqid(), -8).'-'.random_int(0, 9), // rut único para no colisionar en el dedup
            'email' => 'valido'.uniqid().'@gmail.com',
            'email_invalido' => false,
        ], $prospAttrs));

        ProspectoEnFlujo::create([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'email',
            'estado' => 'pendiente',
            'fecha_inicio' => now(),
            'completado' => false,
            'cancelado' => false,
        ]);

        return $prospecto;
    }

    private function envio(Flujo $flujo, Prospecto $p, string $estado, array $extra = []): void
    {
        $pefId = ProspectoEnFlujo::where('flujo_id', $flujo->id)->where('prospecto_id', $p->id)->value('id');
        Envio::create(array_merge([
            'flujo_id' => $flujo->id,
            'prospecto_id' => $p->id,
            'prospecto_en_flujo_id' => $pefId,
            'canal' => 'email',
            'estado' => $estado,
            'destinatario' => $p->email ?: 'x@x.com',
            'contenido_enviado' => 'test',
            'fecha_programada' => now(),
        ], $extra));
    }

    #[Test]
    public function clasifica_cada_razon_de_no_recibido(): void
    {
        // Flujo NO-SYSGAL → cohorte por fecha_inicio (más simple de armar).
        $flujo = Flujo::factory()->create(['origen' => 'manual']);

        // recibió OK → NO debe aparecer en el detalle
        $ok = $this->nuevoProspectoEnFlujo($flujo);
        $this->envio($flujo, $ok, 'enviado');

        // falló el envío
        $fallo = $this->nuevoProspectoEnFlujo($flujo);
        $this->envio($flujo, $fallo, 'fallido');

        // pendiente huérfano (sin provider ni message_id)
        $huerfano = $this->nuevoProspectoEnFlujo($flujo);
        $this->envio($flujo, $huerfano, 'pendiente', ['external_message_id' => null, 'email_provider' => null]);

        // sin email
        $sinEmail = $this->nuevoProspectoEnFlujo($flujo, ['email' => null]);

        // email inválido
        $invalido = $this->nuevoProspectoEnFlujo($flujo, ['email_invalido' => true]);

        // sin envío (entró pero nunca se le creó envío)
        $sinEnvio = $this->nuevoProspectoEnFlujo($flujo);

        $svc = app(MetricasService::class);
        $detalle = collect($svc->getNoRecibieronDetalle(30, $flujo->id, null, null));

        $razones = $detalle->pluck('razon', 'rut'); // por si querés inspeccionar
        $porRazon = $detalle->groupBy('razon')->map->count();

        // El que recibió NO está
        $this->assertFalse($detalle->contains('rut', $ok->rut), 'El que recibió no debe aparecer');

        // Cada razón presente exactamente 1 vez
        $this->assertSame(1, $porRazon['fallido_envio'] ?? 0);
        $this->assertSame(1, $porRazon['pendiente_huerfano'] ?? 0);
        $this->assertSame(1, $porRazon['sin_email'] ?? 0);
        $this->assertSame(1, $porRazon['email_invalido'] ?? 0);
        $this->assertSame(1, $porRazon['sin_envio'] ?? 0);
        $this->assertCount(5, $detalle, 'Deben ser 5 no-recibidos (excluye al que recibió)');
    }
}
