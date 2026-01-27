<?php

namespace App\Jobs;

use App\Models\Desuscripcion;
use App\Models\Envio;
use App\Models\Prospecto;
use App\Services\AthenaCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job para sincronizar desuscripciones desde Athena Campaign API.
 * 
 * Este job consulta la API de Athena para obtener las estadísticas
 * de los envíos recientes y detectar nuevas desuscripciones.
 * 
 * Se ejecuta periódicamente (cada hora) vía scheduler.
 */
class SincronizarDesuscripcionesAthenaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutos

    public function __construct(
        private int $diasAtras = 7
    ) {}

    public function handle(AthenaCampaignService $athenaService): void
    {
        Log::info('SincronizarDesuscripcionesAthenaJob: Iniciando sincronización', [
            'dias_atras' => $this->diasAtras,
        ]);

        $desde = now()->subDays($this->diasAtras);
        $sincronizadas = 0;
        $errores = 0;

        // Obtener envíos recientes que tengan message_id de Athena
        // Incluir 'abierto' y 'clickeado' (son estados posteriores a 'enviado')
        $envios = Envio::where('created_at', '>=', $desde)
            ->where('canal', 'email')
            ->whereNotNull('athena_message_id')
            ->whereIn('estado', ['enviado', 'entregado', 'abierto', 'clickeado'])
            ->select('id', 'athena_message_id', 'prospecto_id', 'flujo_id')
            ->cursor();

        foreach ($envios as $envio) {
            try {
                $stats = $athenaService->getStatistics($envio->athena_message_id);

                if (isset($stats['_mocked'])) {
                    continue; // Saltar estadísticas mockeadas
                }

                $unsubscribes = $stats['mensaje']['Unsubscribes'] ?? 0;

                if ($unsubscribes > 0) {
                    $resultado = $this->procesarDesuscripcion($envio);
                    if ($resultado) {
                        $sincronizadas++;
                    }
                }

            } catch (\Exception $e) {
                $errores++;
                Log::warning('SincronizarDesuscripcionesAthenaJob: Error procesando envío', [
                    'envio_id' => $envio->id,
                    'message_id' => $envio->athena_message_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Rate limiting: esperar entre requests
            usleep(100000); // 100ms
        }

        Log::info('SincronizarDesuscripcionesAthenaJob: Sincronización completada', [
            'sincronizadas' => $sincronizadas,
            'errores' => $errores,
        ]);
    }

    /**
     * Procesa una desuscripción detectada desde Athena.
     */
    private function procesarDesuscripcion(Envio $envio): bool
    {
        $prospecto = Prospecto::find($envio->prospecto_id);

        if (!$prospecto) {
            return false;
        }

        // Verificar si ya está desuscrito
        if ($prospecto->estado === 'desuscrito') {
            return false;
        }

        // Verificar si ya existe registro de desuscripción para este envío
        $existente = Desuscripcion::where('envio_id', $envio->id)->exists();
        if ($existente) {
            return false;
        }

        try {
            DB::transaction(function () use ($prospecto, $envio) {
                // Actualizar prospecto
                $prospecto->update([
                    'estado' => 'desuscrito',
                    'fecha_desuscripcion' => now(),
                    'preferencias_comunicacion' => [
                        'email' => false,
                        'sms' => true, // Solo desuscribir de email (desde Athena)
                        'desuscrito_at' => now()->toIso8601String(),
                        'canal_desuscripcion' => 'email',
                        'origen' => 'athena_sync',
                    ],
                ]);

                // Cancelar participación en flujos activos
                $prospecto->prospectosEnFlujo()
                    ->where('completado', false)
                    ->where('cancelado', false)
                    ->update([
                        'cancelado' => true,
                        'fecha_cancelacion' => now(),
                        'motivo_cancelacion' => 'desuscripcion_athena',
                    ]);

                // Registrar desuscripción
                Desuscripcion::create([
                    'prospecto_id' => $prospecto->id,
                    'canal' => Desuscripcion::CANAL_EMAIL,
                    'motivo' => 'athena_unsubscribe',
                    'envio_id' => $envio->id,
                    'flujo_id' => $envio->flujo_id,
                ]);
            });

            Log::info('SincronizarDesuscripcionesAthenaJob: Prospecto desuscrito', [
                'prospecto_id' => $prospecto->id,
                'envio_id' => $envio->id,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('SincronizarDesuscripcionesAthenaJob: Error guardando desuscripción', [
                'prospecto_id' => $prospecto->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
