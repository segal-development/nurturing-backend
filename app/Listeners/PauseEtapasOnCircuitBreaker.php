<?php

namespace App\Listeners;

use App\Events\CircuitBreakerOpened;
use App\Models\FlujoEjecucionEtapa;
use App\Services\AthenaCampaignService;
use Illuminate\Support\Facades\Log;

/**
 * Listener que pausa automáticamente las etapas en ejecución
 * cuando el circuit breaker se abre por errores de la API.
 *
 * Esto previene que se sigan despachando jobs que van a fallar,
 * y permite que se reanuden automáticamente cuando la API vuelve.
 */
class PauseEtapasOnCircuitBreaker
{
    public function __construct(
        private AthenaCampaignService $athenaCampaignService
    ) {}

    public function handle(CircuitBreakerOpened $event): void
    {
        Log::warning('PauseEtapasOnCircuitBreaker: Circuit breaker abierto, pausando etapas', [
            'channel' => $event->channel,
            'failures' => $event->failureCount,
            'threshold' => $event->threshold,
            'recovery_time' => $event->recoveryTimeSeconds,
        ]);

        // Buscar etapas en ejecución que usan este canal
        $etapasAfectadas = $this->buscarEtapasAfectadas($event->channel);

        if ($etapasAfectadas->isEmpty()) {
            Log::info('PauseEtapasOnCircuitBreaker: No hay etapas en ejecución para pausar', [
                'channel' => $event->channel,
            ]);

            return;
        }

        // Pausar cada etapa con información del error
        foreach ($etapasAfectadas as $etapa) {
            $etapa->pausarPorCircuitBreaker(
                channel: $event->channel,
                failures: $event->failureCount,
                recoverySeconds: $event->recoveryTimeSeconds,
                errorMessage: "API {$event->channel} no disponible después de {$event->failureCount} intentos fallidos"
            );

            Log::info('PauseEtapasOnCircuitBreaker: Etapa pausada', [
                'etapa_id' => $etapa->id,
                'flujo_ejecucion_id' => $etapa->flujo_ejecucion_id,
                'node_id' => $etapa->node_id,
                'auto_resume_at' => $etapa->auto_resume_at,
            ]);
        }

        // Enviar alerta (si está configurado)
        $this->enviarAlerta($event, $etapasAfectadas->count());
    }

    /**
     * Busca etapas en ejecución que usan el canal afectado.
     */
    private function buscarEtapasAfectadas(string $channel)
    {
        // Buscar etapas en 'executing' estado
        return FlujoEjecucionEtapa::where('estado', 'executing')
            ->whereHas('ejecucion', function ($query) {
                $query->where('estado', 'in_progress');
            })
            ->get()
            ->filter(function ($etapa) use ($channel) {
                // Verificar si esta etapa específica usa el canal afectado
                return $this->etapaUsaCanal($etapa, $channel);
            });
    }

    /**
     * Verifica si una etapa usa un canal específico (email/sms).
     */
    private function etapaUsaCanal(FlujoEjecucionEtapa $etapa, string $channel): bool
    {
        // Obtener el flujo y sus stages
        $ejecucion = $etapa->ejecucion;
        if (! $ejecucion || ! $ejecucion->flujo) {
            return false;
        }

        $configStructure = $ejecucion->flujo->config_structure;
        $stages = $configStructure['stages'] ?? [];

        // Buscar este stage por node_id
        $stageData = collect($stages)->firstWhere('id', $etapa->node_id);

        if (! $stageData) {
            return false;
        }

        $tipoMensaje = $stageData['tipo_mensaje'] ?? 'email';

        return $tipoMensaje === $channel;
    }

    /**
     * Envía alerta sobre las etapas pausadas.
     */
    private function enviarAlerta(CircuitBreakerOpened $event, int $etapasPausadas): void
    {
        if (! config('envios.alerts.enabled.critical', true)) {
            return;
        }

        $smsNumbers = config('envios.alerts.sms_numbers');
        if (empty($smsNumbers)) {
            return;
        }

        try {
            $mensaje = sprintf(
                '⚠️ ALERTA NURTURING: API %s caída. %d etapas pausadas. Se reintentará en %d min.',
                strtoupper($event->channel),
                $etapasPausadas,
                (int) ceil($event->recoveryTimeSeconds / 60)
            );

            foreach (explode(',', $smsNumbers) as $numero) {
                $numero = trim($numero);
                if (! empty($numero)) {
                    $this->athenaCampaignService->enviarSmsDirecto($numero, $mensaje);
                }
            }

            Log::info('PauseEtapasOnCircuitBreaker: Alerta SMS enviada', [
                'destinatarios' => $smsNumbers,
            ]);
        } catch (\Exception $e) {
            // No fallar si la alerta no se puede enviar
            Log::warning('PauseEtapasOnCircuitBreaker: No se pudo enviar alerta SMS', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
