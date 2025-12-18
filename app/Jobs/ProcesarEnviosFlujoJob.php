<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\ProspectoEnFlujo;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcesarEnviosFlujoJob implements ShouldQueue
{
    use Queueable;

    /**
     * Number of prospects to process per chunk.
     */
    public const CHUNK_SIZE = 100;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $flujoId,
        public ?string $plantillaAsunto = null,
        public ?string $plantillaContenido = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $flujo = Flujo::find($this->flujoId);

        if (! $flujo) {
            Log::error("ProcesarEnviosFlujoJob: Flujo {$this->flujoId} no encontrado");

            return;
        }

        if (! $flujo->activo) {
            Log::warning("ProcesarEnviosFlujoJob: Flujo {$this->flujoId} está inactivo, omitiendo procesamiento");

            return;
        }

        Log::info("ProcesarEnviosFlujoJob: Iniciando procesamiento del flujo {$flujo->id} - {$flujo->nombre}");

        $jobs = [];

        // Process prospects in chunks
        ProspectoEnFlujo::query()
            ->where('flujo_id', $this->flujoId)
            ->where('estado', 'pendiente')
            ->with('prospecto')
            ->chunkById(self::CHUNK_SIZE, function ($prospectosEnFlujo) use (&$jobs) {
                foreach ($prospectosEnFlujo as $prospectoEnFlujo) {
                    $jobs[] = $this->createJobForProspecto($prospectoEnFlujo);
                }
            });

        if (empty($jobs)) {
            Log::info("ProcesarEnviosFlujoJob: No hay prospectos pendientes en el flujo {$this->flujoId}");

            return;
        }

        // Dispatch jobs as a batch for better tracking
        $batch = Bus::batch($jobs)
            ->name("Flujo {$this->flujoId}: {$flujo->nombre}")
            ->onQueue('envios')
            ->allowFailures()
            ->dispatch();

        Log::info("ProcesarEnviosFlujoJob: Batch {$batch->id} creado con ".count($jobs).' jobs para flujo '.$this->flujoId);

        // Update flujo metadata with batch info
        $metadata = $flujo->metadata ?? [];
        $metadata['ultimo_procesamiento'] = [
            'batch_id' => $batch->id,
            'total_jobs' => count($jobs),
            'fecha' => now()->toISOString(),
        ];
        $flujo->update(['metadata' => $metadata]);
    }

    /**
     * Create the appropriate job for the prospecto based on canal_asignado.
     */
    protected function createJobForProspecto(ProspectoEnFlujo $prospectoEnFlujo): ShouldQueue
    {
        if ($prospectoEnFlujo->canal_asignado === 'sms') {
            return new EnviarSmsProspectoJob(
                $prospectoEnFlujo->id,
                $this->plantillaAsunto,
                $this->plantillaContenido
            );
        }

        return new EnviarEmailProspectoJob(
            $prospectoEnFlujo->id,
            $this->plantillaAsunto,
            $this->plantillaContenido
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ProcesarEnviosFlujoJob: Error procesando flujo {$this->flujoId}: ".$exception->getMessage());
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['flujo:'.$this->flujoId, 'procesar-envios'];
    }
}
