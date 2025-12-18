<?php

namespace App\Jobs;

use App\Contracts\SmsServiceInterface;
use App\Models\Envio;
use App\Models\ProspectoEnFlujo;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EnviarSmsProspectoJob implements ShouldQueue
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
    public function handle(SmsServiceInterface $smsService): void
    {
        // Check if batch was cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        $prospectoEnFlujo = ProspectoEnFlujo::with(['prospecto', 'flujo'])->find($this->prospectoEnFlujoId);

        if (! $prospectoEnFlujo) {
            Log::error("EnviarSmsProspectoJob: ProspectoEnFlujo {$this->prospectoEnFlujoId} no encontrado");

            return;
        }

        // Skip if not pending
        if ($prospectoEnFlujo->estado !== 'pendiente') {
            Log::info("EnviarSmsProspectoJob: ProspectoEnFlujo {$this->prospectoEnFlujoId} no está pendiente, estado actual: {$prospectoEnFlujo->estado}");

            return;
        }

        $prospecto = $prospectoEnFlujo->prospecto;

        // Check if prospecto has phone
        if (empty($prospecto->telefono)) {
            Log::warning("EnviarSmsProspectoJob: Prospecto {$prospecto->id} no tiene teléfono");
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
            'asunto' => null, // SMS doesn't have subject
            'contenido_enviado' => $this->contenido ?? $this->generateContenido($prospectoEnFlujo),
            'canal' => 'sms',
            'destinatario' => $prospecto->telefono,
            'estado' => 'pendiente',
            'fecha_programada' => now(),
        ]);

        try {
            $result = $smsService->send($envio);

            if ($result['success']) {
                $envio->marcarComoEnviado();
                $prospectoEnFlujo->marcarCompletado();

                Log::info("EnviarSmsProspectoJob: SMS enviado exitosamente a {$prospecto->telefono}, message_id: {$result['message_id']}");

                // Store message_id in metadata
                $envio->update([
                    'metadata' => array_merge($envio->metadata ?? [], [
                        'message_id' => $result['message_id'],
                    ]),
                ]);
            } else {
                throw new \Exception($result['error'] ?? 'Error desconocido al enviar SMS');
            }
        } catch (\Exception $e) {
            Log::error("EnviarSmsProspectoJob: Error enviando SMS a {$prospecto->telefono}: ".$e->getMessage());

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
     * Generate default content for the SMS.
     */
    protected function generateContenido(ProspectoEnFlujo $prospectoEnFlujo): string
    {
        $prospecto = $prospectoEnFlujo->prospecto;

        return "Hola {$prospecto->nombre}, mensaje de {$prospectoEnFlujo->flujo->nombre}.";
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("EnviarSmsProspectoJob: Falló definitivamente para ProspectoEnFlujo {$this->prospectoEnFlujoId}: ".$exception->getMessage());

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
        return ['prospecto-en-flujo:'.$this->prospectoEnFlujoId, 'enviar-sms'];
    }
}
