<?php

namespace Tests\Feature\Jobs;

use App\Contracts\EmailServiceInterface;
use App\Jobs\EnviarEmailProspectoJob;
use App\Models\Envio;
use App\Models\Flujo;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EnviarEmailProspectoJobTest extends TestCase
{
    use RefreshDatabase;

    protected TipoProspecto $tipoProspecto;

    protected User $user;

    protected Flujo $flujo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipoProspecto = TipoProspecto::factory()->create();
        $this->user = User::factory()->create();
        $this->flujo = Flujo::factory()->porEmail()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'user_id' => $this->user->id,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_does_nothing_when_prospecto_en_flujo_not_found(): void
    {
        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldNotReceive('send');

        $job = new EnviarEmailProspectoJob(999);
        $job->handle($emailService);

        $this->assertDatabaseCount('envios', 0);
    }

    public function test_job_skips_when_prospecto_en_flujo_not_pending(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'estado' => 'completado',
        ]);

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldNotReceive('send');

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id);
        $job->handle($emailService);

        $this->assertDatabaseCount('envios', 0);
    }

    public function test_job_cancels_when_prospecto_has_no_email(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => null,
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldNotReceive('send');

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id);
        $job->handle($emailService);

        $prospectoEnFlujo->refresh();
        $this->assertEquals('cancelado', $prospectoEnFlujo->estado);
        $this->assertDatabaseCount('envios', 0);
    }

    public function test_job_creates_envio_record(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => true,
                'message_id' => 'test-message-id',
                'error' => null,
            ]);

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id);
        $job->handle($emailService);

        $this->assertDatabaseHas('envios', [
            'prospecto_id' => $prospecto->id,
            'flujo_id' => $this->flujo->id,
            'prospecto_en_flujo_id' => $prospectoEnFlujo->id,
            'canal' => 'email',
            'destinatario' => 'test@example.com',
            'estado' => 'enviado',
        ]);
    }

    public function test_job_marks_prospecto_en_flujo_as_completed_on_success(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => true,
                'message_id' => 'test-message-id',
                'error' => null,
            ]);

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id);
        $job->handle($emailService);

        $prospectoEnFlujo->refresh();
        $this->assertEquals('completado', $prospectoEnFlujo->estado);
    }

    public function test_job_marks_envio_as_failed_on_error(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'message_id' => null,
                'error' => 'SMTP connection failed',
            ]);

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id);

        // Job should throw exception for retry
        $this->expectException(\Exception::class);
        $job->handle($emailService);
    }

    public function test_job_stores_message_id_in_envio_metadata(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => true,
                'message_id' => 'unique-message-123',
                'error' => null,
            ]);

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id);
        $job->handle($emailService);

        $envio = Envio::where('prospecto_en_flujo_id', $prospectoEnFlujo->id)->first();
        $this->assertEquals('unique-message-123', $envio->metadata['message_id']);
    }

    public function test_job_uses_custom_asunto_and_contenido(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => true,
                'message_id' => 'test-id',
                'error' => null,
            ]);

        $customAsunto = 'Custom Subject';
        $customContenido = 'Custom Content';

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id, $customAsunto, $customContenido);
        $job->handle($emailService);

        $this->assertDatabaseHas('envios', [
            'prospecto_en_flujo_id' => $prospectoEnFlujo->id,
            'asunto' => $customAsunto,
            'contenido_enviado' => $customContenido,
        ]);
    }

    public function test_job_generates_default_asunto_and_contenido(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
            'nombre' => 'Juan Perez',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => true,
                'message_id' => 'test-id',
                'error' => null,
            ]);

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id);
        $job->handle($emailService);

        $envio = Envio::where('prospecto_en_flujo_id', $prospectoEnFlujo->id)->first();

        $this->assertStringContainsString($this->flujo->nombre, $envio->asunto);
        $this->assertStringContainsString('Juan Perez', $envio->contenido_enviado);
    }

    public function test_job_marks_en_proceso_before_sending(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $estadoDuranteEnvio = null;

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andReturnUsing(function () use ($prospectoEnFlujo, &$estadoDuranteEnvio) {
                $prospectoEnFlujo->refresh();
                $estadoDuranteEnvio = $prospectoEnFlujo->estado;

                return [
                    'success' => true,
                    'message_id' => 'test-id',
                    'error' => null,
                ];
            });

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id);
        $job->handle($emailService);

        $this->assertEquals('en_proceso', $estadoDuranteEnvio);
    }

    public function test_job_has_correct_tags(): void
    {
        $job = new EnviarEmailProspectoJob(456);

        $tags = $job->tags();

        $this->assertContains('prospecto-en-flujo:456', $tags);
        $this->assertContains('enviar-email', $tags);
    }

    public function test_job_reverts_to_pending_on_failure_for_retry(): void
    {
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $this->tipoProspecto->id,
            'email' => 'test@example.com',
        ]);

        $prospectoEnFlujo = ProspectoEnFlujo::factory()->porEmail()->pendiente()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
        ]);

        $emailService = Mockery::mock(EmailServiceInterface::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'message_id' => null,
                'error' => 'Temporary failure',
            ]);

        $job = new EnviarEmailProspectoJob($prospectoEnFlujo->id);

        try {
            $job->handle($emailService);
        } catch (\Exception $e) {
            // Expected
        }

        $prospectoEnFlujo->refresh();
        $this->assertEquals('pendiente', $prospectoEnFlujo->estado);

        // Envio should be marked as failed
        $envio = Envio::where('prospecto_en_flujo_id', $prospectoEnFlujo->id)->first();
        $this->assertEquals('fallido', $envio->estado);
    }
}
