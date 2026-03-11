<?php

namespace App\Listeners;

use App\Events\CircuitBreakerClosed;
use App\Models\FlujoEjecucionEtapa;
use App\Services\AthenaCampaignService;
use Illuminate\Support\Facades\Log;

/**
 * Listener que reanuda automáticamente las etapas pausadas
 * cuando el circuit breaker se cierra (API vuelve a funcionar).
 */
class ResumeEtapasOnCircuitClose
{
    public function __construct(
        private AthenaCampaignService $athenaCampaignService
    ) {}

    public function handle(CircuitBreakerClosed $event): void
    {
        Log::info('ResumeEtapasOnCircuitClose: Circuit breaker cerrado, reanudando etapas', [
            'channel' => $event->channel,
            'reason' => $event->closedReason,
        ]);

        // Buscar etapas pausadas por circuit breaker de este canal
        $etapasPausadas = FlujoEjecucionEtapa::where('estado', 'paused')
            ->whereNotNull('pause_reason')
            ->get()
            ->filter(function ($etapa) use ($event) {
                $pauseReason = $etapa->pause_reason;

                return isset($pauseReason['reason'])
                    && $pauseReason['reason'] === 'circuit_breaker_opened'
                    && isset($pauseReason['channel'])
                    && $pauseReason['channel'] === $event->channel;
            });

        if ($etapasPausadas->isEmpty()) {
            Log::info('ResumeEtapasOnCircuitClose: No hay etapas pausadas para reanudar', [
                'channel' => $event->channel,
            ]);

            return;
        }

        // Reanudar cada etapa
        foreach ($etapasPausadas as $etapa) {
            $etapa->reanudar();

            Log::info('ResumeEtapasOnCircuitClose: Etapa reanudada', [
                'etapa_id' => $etapa->id,
                'flujo_ejecucion_id' => $etapa->flujo_ejecucion_id,
                'node_id' => $etapa->node_id,
            ]);
        }

        // Enviar notificación de recuperación
        $this->enviarNotificacionRecuperacion($event, $etapasPausadas->count());
    }

    /**
     * Envía notificación de que el sistema se recuperó.
     */
    private function enviarNotificacionRecuperacion(CircuitBreakerClosed $event, int $etapasReanudadas): void
    {
        if (! config('envios.alerts.enabled.info', true)) {
            return;
        }

        $smsNumbers = config('envios.alerts.sms_numbers');
        if (empty($smsNumbers)) {
            return;
        }

        try {
            $mensaje = sprintf(
                '✅ NURTURING OK: API %s recuperada. %d etapas reanudadas automáticamente.',
                strtoupper($event->channel),
                $etapasReanudadas
            );

            foreach (explode(',', $smsNumbers) as $numero) {
                $numero = trim($numero);
                if (! empty($numero)) {
                    $this->athenaCampaignService->enviarSmsDirecto($numero, $mensaje);
                }
            }

            Log::info('ResumeEtapasOnCircuitClose: Notificación de recuperación enviada');
        } catch (\Exception $e) {
            // No fallar si la notificación no se puede enviar
            Log::warning('ResumeEtapasOnCircuitClose: No se pudo enviar notificación', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
