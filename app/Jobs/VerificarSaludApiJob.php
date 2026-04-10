<?php

namespace App\Jobs;

use App\Events\CircuitBreakerClosed;
use App\Models\FlujoEjecucionEtapa;
use App\Services\AthenaCampaignService;
use App\Services\Email\EmailProviderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Job que verifica la salud de las APIs de email y SMS.
 *
 * Este job:
 * 1. Verifica si hay circuit breakers abiertos
 * 2. Hace un health check a las APIs afectadas
 * 3. Si la API responde OK, cierra el circuit breaker
 * 4. Dispara evento CircuitBreakerClosed para reanudar etapas
 *
 * Se ejecuta cada 2 minutos desde el scheduler.
 */
class VerificarSaludApiJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    public function handle(AthenaCampaignService $athenaCampaignService): void
    {
        Log::debug('VerificarSaludApiJob: Iniciando verificación de salud de APIs');

        // Verificar cada canal
        foreach (['email', 'sms'] as $channel) {
            $this->verificarCanal($channel, $athenaCampaignService);
        }

        // Verificar Certificada (para prospectos de Grupo Deudas e IC)
        $this->verificarCertificada();

        // También verificar etapas que deberían reanudarse por timeout
        $this->verificarEtapasConTimeoutExpirado();
    }

    /**
     * Verifica un canal específico.
     */
    private function verificarCanal(string $channel, AthenaCampaignService $athenaCampaignService): void
    {
        $circuitKey = "envio-circuit:{$channel}";
        $isOpen = Cache::get($circuitKey) === 'open';

        if (! $isOpen) {
            // Circuit breaker cerrado, nada que hacer
            return;
        }

        Log::info("VerificarSaludApiJob: Circuit breaker abierto para {$channel}, verificando API");

        // Hacer health check según el canal
        $apiOk = match ($channel) {
            'email' => $this->verificarApiEmail($athenaCampaignService),
            'sms' => $this->verificarApiSms($athenaCampaignService),
            default => false,
        };

        if ($apiOk) {
            Log::info("VerificarSaludApiJob: API {$channel} respondió OK, cerrando circuit breaker");

            // Cerrar circuit breaker
            Cache::forget($circuitKey);
            Cache::forget("envio-failures:{$channel}");

            // Disparar evento para reanudar etapas
            CircuitBreakerClosed::dispatch($channel, 'health_check_passed');
        } else {
            Log::warning("VerificarSaludApiJob: API {$channel} sigue sin responder");
        }
    }

    /**
     * Verifica la API de email.
     */
    private function verificarApiEmail(AthenaCampaignService $athenaCampaignService): bool
    {
        try {
            // Usar el health check existente del servicio
            return $athenaCampaignService->healthCheck();
        } catch (\Exception $e) {
            Log::warning('VerificarSaludApiJob: Error en health check de email', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verifica la API de SMS.
     */
    private function verificarApiSms(AthenaCampaignService $athenaCampaignService): bool
    {
        try {
            // Hacer un request simple a la API de SMS para verificar conectividad
            // No enviamos SMS real, solo verificamos que responda
            $baseUrl = 'https://api.athenacampaign.com/v1';
            $token = config('services.sms.api_token');

            if (empty($token)) {
                Log::warning('VerificarSaludApiJob: No hay token de SMS configurado');

                return false;
            }

            // Hacer request con timeout corto
            $response = Http::timeout(10)->get("{$baseUrl}/status", [
                'TOKEN' => $token,
            ]);

            // Si responde (aunque sea error), la API está disponible
            // Un 403 significa que la API responde pero el endpoint no existe (normal)
            // Lo importante es que no sea timeout/connection error
            return $response->status() !== 0;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('VerificarSaludApiJob: API SMS no responde (connection error)', [
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Exception $e) {
            // Otros errores (como 404, 403) significan que la API SÍ responde
            Log::info('VerificarSaludApiJob: API SMS responde (aunque con error)', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Verifica la API de Certificada (para emails de Grupo Deudas e IC).
     */
    private function verificarCertificada(): void
    {
        try {
            $baseUrl = config('services.certificada.base_url', 'https://sistema.certificada.cl/api');
            $apiKey = config('services.certificada.api_key');
            $enabled = config('services.certificada.enabled', true);

            if (! $enabled || empty($apiKey)) {
                Log::debug('VerificarSaludApiJob: Certificada no está configurado o está deshabilitado');
                return;
            }

            // Hacer un health check simple - request con timeout corto
            // Usamos el endpoint de enviar con datos vacíos para verificar que responde
            $response = Http::timeout(10)
                ->post("{$baseUrl}/transaccional/enviar_html", [
                    'IdApi' => $apiKey,
                    'From' => ['Email' => 'test@test.com', 'Nombre' => 'Test'],
                    'To' => ['Email' => 'test@test.com', 'Nombre' => 'Test'],
                    'Despacho' => ['Html' => '', 'Texto' => '', 'Asunto' => ''],
                ]);

            // Si la API responde (aunque sea con error de validación), está healthy
            // Un error de validación (Estado != 1) es esperado y significa que la API funciona
            if ($response->successful() || $response->status() < 500) {
                EmailProviderResolver::markCertificadaHealthy();
                Log::debug('VerificarSaludApiJob: Certificada respondió OK');
            } else {
                EmailProviderResolver::markCertificadaUnhealthy(
                    'HTTP ' . $response->status() . ': ' . ($response->json()['Mensaje'] ?? 'Unknown error'),
                    300 // 5 minutes
                );
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('VerificarSaludApiJob: Certificada no responde (connection error)', [
                'error' => $e->getMessage(),
            ]);
            EmailProviderResolver::markCertificadaUnhealthy('Connection error: ' . $e->getMessage(), 300);
        } catch (\Exception $e) {
            Log::warning('VerificarSaludApiJob: Error verificando Certificada', [
                'error' => $e->getMessage(),
            ]);
            // No marcamos unhealthy por errores inesperados, solo por connection errors
        }
    }

    /**
     * Verifica etapas pausadas cuyo auto_resume_at ya pasó.
     *
     * Esto es un failsafe: si el circuit breaker expiró pero el evento
     * no se disparó correctamente, igual reanudamos las etapas.
     */
    private function verificarEtapasConTimeoutExpirado(): void
    {
        $etapasExpiradas = FlujoEjecucionEtapa::where('estado', 'paused')
            ->whereNotNull('auto_resume_at')
            ->where('auto_resume_at', '<=', now())
            ->get();

        if ($etapasExpiradas->isEmpty()) {
            return;
        }

        Log::info('VerificarSaludApiJob: Encontradas etapas con timeout expirado', [
            'cantidad' => $etapasExpiradas->count(),
        ]);

        foreach ($etapasExpiradas as $etapa) {
            $pauseReason = $etapa->pause_reason;
            $channel = $pauseReason['channel'] ?? 'unknown';

            // Verificar si el circuit breaker ya está cerrado
            $circuitKey = "envio-circuit:{$channel}";
            $isOpen = Cache::get($circuitKey) === 'open';

            if (! $isOpen) {
                // Circuit breaker cerrado, reanudar la etapa
                $etapa->reanudar();

                Log::info('VerificarSaludApiJob: Etapa reanudada por timeout expirado', [
                    'etapa_id' => $etapa->id,
                    'channel' => $channel,
                ]);
            } else {
                // Circuit breaker sigue abierto, extender el timeout
                $recoveryTime = config('envios.circuit_breaker.recovery_time', 60);
                $etapa->update([
                    'auto_resume_at' => now()->addSeconds($recoveryTime),
                ]);

                Log::info('VerificarSaludApiJob: Timeout extendido, circuit breaker sigue abierto', [
                    'etapa_id' => $etapa->id,
                    'channel' => $channel,
                    'nuevo_auto_resume_at' => $etapa->fresh()->auto_resume_at,
                ]);
            }
        }
    }
}
