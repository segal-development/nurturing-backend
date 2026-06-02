<?php

namespace Tests\Feature\Commands;

use App\Jobs\EnviarEmailEtapaProspectoJob;
use App\Models\Envio;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Plantilla;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResendHuerfanosCommandTest extends TestCase
{
    use RefreshDatabase;

    private function escenario(): array
    {
        // Plantilla de email con contenido renderizable (modo html_personalizado para que
        // generarPreview devuelva algo sin acoplarse al schema de componentes).
        $plantilla = Plantilla::factory()->create([
            'tipo' => 'email',
            'modo' => 'html_personalizado',
            'contenido' => '<p>Hola, este es el email de la etapa.</p>',
            'asunto' => 'Asunto de prueba',
            'componentes' => null,
        ]);

        $flujo = Flujo::factory()->create([
            'config_structure' => [
                'stages' => [['id' => 'stage-1', 'label' => 'dia 60', 'tipo_mensaje' => 'email', 'plantilla_id' => $plantilla->id, 'plantilla_type' => 'reference']],
                'branches' => [],
            ],
        ]);
        $ejec = FlujoEjecucion::factory()->create(['flujo_id' => $flujo->id, 'prospectos_ids' => []]);
        $fee = FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $ejec->id,
            'node_id' => 'stage-1',
            'estado' => 'executing',
            'prospectos_ids' => [],
            'prospectos_count' => 0,
            'fecha_programada' => now(),
        ]);

        // 2 prospectos con envío HUÉRFANO (pendiente, sin provider ni message_id)
        $ids = [];
        foreach (range(1, 2) as $i) {
            $p = Prospecto::factory()->create(['email' => "h{$i}".uniqid().'@gmail.com', 'email_invalido' => false]);
            $pef = ProspectoEnFlujo::create([
                'flujo_id' => $flujo->id, 'prospecto_id' => $p->id, 'canal_asignado' => 'email',
                'estado' => 'pendiente', 'fecha_inicio' => now(), 'completado' => false, 'cancelado' => false,
            ]);
            Envio::create([
                'flujo_id' => $flujo->id, 'prospecto_id' => $p->id, 'prospecto_en_flujo_id' => $pef->id,
                'flujo_ejecucion_etapa_id' => $fee->id, 'canal' => 'email', 'estado' => 'pendiente',
                'destinatario' => $p->email, 'contenido_enviado' => 'x', 'fecha_programada' => now(),
                'external_message_id' => null, 'email_provider' => null,
            ]);
            $ids[] = $p->id;
        }

        return [$flujo, $ids];
    }

    #[Test]
    public function reenvia_huerfanos_borra_placeholder_y_despacha(): void
    {
        Bus::fake();
        [$flujo, $ids] = $this->escenario();

        $this->artisan('nurturing:resend-huerfanos', ['--flujo' => $flujo->id])->assertSuccessful();

        // Placeholders borrados
        $this->assertSame(0, Envio::where('flujo_id', $flujo->id)->where('estado', 'pendiente')->count());
        // Despachó 1 leaf job por huérfano (2), directo a la cola emails
        Bus::assertDispatchedTimes(EnviarEmailEtapaProspectoJob::class, 2);
    }

    #[Test]
    public function dry_run_no_borra_ni_despacha(): void
    {
        Bus::fake();
        [$flujo] = $this->escenario();

        $this->artisan('nurturing:resend-huerfanos', ['--flujo' => $flujo->id, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(2, Envio::where('flujo_id', $flujo->id)->where('estado', 'pendiente')->count(), 'dry-run no debe borrar');
        Bus::assertNotDispatched(EnviarEmailEtapaProspectoJob::class);
    }

    #[Test]
    public function limit_acota_la_cantidad(): void
    {
        Bus::fake();
        [$flujo] = $this->escenario();

        $this->artisan('nurturing:resend-huerfanos', ['--flujo' => $flujo->id, '--limit' => 1])->assertSuccessful();

        // Con limit=1 borra 1 placeholder, queda 1
        $this->assertSame(1, Envio::where('flujo_id', $flujo->id)->where('estado', 'pendiente')->count());
    }
}
