<?php

namespace App\Jobs;

use App\Contracts\EmailServiceInterface;
use App\Models\Envio;
use App\Models\ProspectoEnFlujo;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EnviarEmailProspectoJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $prospectoEnFlujoId,
        public ?string $asunto = null,
        public ?string $contenido = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmailServiceInterface $emailService): void
    {
        // Check if batch was cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        $prospectoEnFlujo = ProspectoEnFlujo::with(['prospecto', 'flujo'])->find($this->prospectoEnFlujoId);

        if (! $prospectoEnFlujo) {
            Log::error("EnviarEmailProspectoJob: ProspectoEnFlujo {$this->prospectoEnFlujoId} no encontrado");

            return;
        }

        // Skip if not pending
        if ($prospectoEnFlujo->estado !== 'pendiente') {
            Log::info("EnviarEmailProspectoJob: ProspectoEnFlujo {$this->prospectoEnFlujoId} no está pendiente, estado actual: {$prospectoEnFlujo->estado}");

            return;
        }

        $prospecto = $prospectoEnFlujo->prospecto;

        // Check if prospecto has email
        if (empty($prospecto->email)) {
            Log::warning("EnviarEmailProspectoJob: Prospecto {$prospecto->id} no tiene email");
            $prospectoEnFlujo->marcarCancelado();

            return;
        }

        // Mark as in process
        $prospectoEnFlujo->marcarEnProceso();

        // Create envio record
        $envio = Envio::create([
            'prospecto_id' => $prospecto->id,
            'flujo_id' => $prospectoEnFlujo->flujo_id,
            'prospecto_en_flujo_id' => $prospectoEnFlujo->id,
            'asunto' => $this->asunto ?? $this->generateAsunto($prospectoEnFlujo),
            'contenido_enviado' => $this->contenido ?? $this->generateContenido($prospectoEnFlujo),
            'canal' => 'email',
            'destinatario' => $prospecto->email,
            'estado' => 'pendiente',
            'fecha_programada' => now(),
        ]);

        try {
            $result = $emailService->send($envio);

            if ($result['success']) {
                $envio->marcarComoEnviado();
                $prospectoEnFlujo->marcarCompletado();

                Log::info("EnviarEmailProspectoJob: Email enviado exitosamente a {$prospecto->email}, message_id: {$result['message_id']}");

                // Store message_id in metadata
                $envio->update([
                    'metadata' => array_merge($envio->metadata ?? [], [
                        'message_id' => $result['message_id'],
                    ]),
                ]);
            } else {
                throw new \Exception($result['error'] ?? 'Error desconocido al enviar email');
            }
        } catch (\Exception $e) {
            Log::error("EnviarEmailProspectoJob: Error enviando email a {$prospecto->email}: ".$e->getMessage());

            $envio->marcarComoFallido($e->getMessage());

            // Only mark as cancelled if max retries reached
            if ($this->attempts() >= $this->tries) {
                $prospectoEnFlujo->marcarCancelado();
            } else {
                // Revert to pending for retry
                $prospectoEnFlujo->update(['estado' => 'pendiente']);
                throw $e; // Re-throw to trigger retry
            }
        }
    }

    /**
     * Generate default subject for the email.
     */
    protected function generateAsunto(ProspectoEnFlujo $prospectoEnFlujo): string
    {
        return "Mensaje de {$prospectoEnFlujo->flujo->nombre}";
    }

    /**
     * Generate default content for the email.
     */
    protected function generateContenido(ProspectoEnFlujo $prospectoEnFlujo): string
    {
        $prospecto = $prospectoEnFlujo->prospecto;

        return "Hola {$prospecto->nombre}, este es un mensaje del flujo {$prospectoEnFlujo->flujo->nombre}.";
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("EnviarEmailProspectoJob: Falló definitivamente para ProspectoEnFlujo {$this->prospectoEnFlujoId}: ".$exception->getMessage());

        $prospectoEnFlujo = ProspectoEnFlujo::find($this->prospectoEnFlujoId);
        $prospectoEnFlujo?->marcarCancelado();
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['prospecto-en-flujo:'.$this->prospectoEnFlujoId, 'enviar-email'];
    }
}
