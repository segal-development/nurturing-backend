<?php

namespace App\Listeners;

use App\Events\CircuitBreakerOpened;
use App\Models\FlujoEjecucionEtapa;
use App\Services\AthenaCampaignService;
use Illuminate\Support\Facades\Cache;
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
     * Busca etapas afectadas por el canal del breaker.
     *
     * Incluye etapas en estados:
     * - 'executing': actualmente procesando (las pausamos para detener mid-batch)
     * - 'pending': próximas a ejecutarse y van a fallar también
     *
     * Acepta ejecuciones en 'in_progress' Y 'waiting' (flujos perpetuos).
     *
     * Antes solo buscaba 'executing' + 'in_progress', que es una ventana muy chica;
     * en la práctica el breaker se abre cuando los workers están rate-limited y las
     * etapas están en 'pending', no 'executing' → nunca pausaba nada.
     */
    private function buscarEtapasAfectadas(string $channel)
    {
        return FlujoEjecucionEtapa::whereIn('estado', ['executing', 'pending'])
            ->whereHas('ejecucion', function ($query) {
                $query->whereIn('estado', ['in_progress', 'waiting']);
            })
            ->get()
            ->filter(function ($etapa) use ($channel) {
                return $this->etapaUsaCanal($etapa, $channel);
            });
    }

    /**
     * Verifica si una etapa usa un canal específico (email/sms).
     * `tipo_mensaje='ambos'` (email + SMS) matchea cualquier canal.
     */
    private function etapaUsaCanal(FlujoEjecucionEtapa $etapa, string $channel): bool
    {
        $ejecucion = $etapa->ejecucion;
        if (! $ejecucion || ! $ejecucion->flujo) {
            return false;
        }

        $configStructure = $ejecucion->flujo->config_structure;
        $stages = $configStructure['stages'] ?? [];

        $stageData = collect($stages)->firstWhere('id', $etapa->node_id);

        if (! $stageData) {
            return false;
        }

        $tipoMensaje = $stageData['tipo_mensaje'] ?? 'email';

        // 'ambos' = email + SMS, matchea contra cualquier breaker
        return $tipoMensaje === $channel || $tipoMensaje === 'ambos';
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

        // Dedup por canal: el circuit breaker puede flapear (abrir/cerrar) decenas de veces
        // mientras la API está caída (recovery_time es de solo 60s). Sin throttle, cada
        // apertura mandaba un SMS — 314 aperturas el 2026-06-01 generaron 37 SMS al mismo
        // número. Limitamos a 1 SMS por canal por hora, igual que NurturingHealthCheckCommand.
        $dedupKey = "cb-alert-sms:{$event->channel}";
        if (Cache::has($dedupKey)) {
            Log::info('PauseEtapasOnCircuitBreaker: alerta SMS deduplicada (ya se avisó en la última hora)', [
                'channel' => $event->channel,
            ]);

            return;
        }
        Cache::put($dedupKey, true, now()->addHour());

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
