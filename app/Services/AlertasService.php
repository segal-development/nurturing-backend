<?php

namespace App\Services;

use App\Mail\AlertaCriticaMail;
use App\Mail\AlertaWarningMail;
use App\Mail\ResumenDiarioMail;
use App\Models\Envio;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Servicio centralizado de alertas del sistema.
 *
 * Niveles de alerta:
 * - CRÍTICO (SMS + Email): Sistema caído, circuit breaker abierto
 * - WARNING (Email): Tasa de error alta, cola saturada
 * - INFO (Email): Resumen diario, estadísticas
 */
class AlertasService
{
    /**
     * Envía una alerta crítica (SMS + Email)
     *
     * Usado para: circuit breaker abierto, sistema caído, errores masivos
     */
    public function alertaCritica(string $titulo, string $mensaje, array $contexto = []): void
    {
        if (! config('envios.alerts.enabled.critical', true)) {
            return;
        }

        // Verificar cooldown para evitar spam
        $cacheKey = 'alerta_critica_'.md5($titulo);
        if ($this->estaDentroDeCooldown($cacheKey)) {
            Log::info('[Alertas] Alerta crítica en cooldown, omitiendo', ['titulo' => $titulo]);

            return;
        }

        Log::critical('[Alertas] Enviando alerta CRÍTICA', [
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'contexto' => $contexto,
        ]);

        try {
            // Enviar SMS a todos los números configurados
            $this->enviarSmsCritico($titulo, $mensaje);

            // Enviar email a todos los destinatarios
            $this->enviarEmailCritico($titulo, $mensaje, $contexto);

            // Marcar cooldown
            $this->marcarCooldown($cacheKey);
        } catch (\Exception $e) {
            Log::error('[Alertas] Error enviando alerta crítica', [
                'error' => $e->getMessage(),
                'titulo' => $titulo,
            ]);
        }
    }

    /**
     * Envía una alerta de warning (solo Email)
     *
     * Usado para: tasa de error alta, cola saturada, warnings
     */
    public function alertaWarning(string $titulo, string $mensaje, array $contexto = []): void
    {
        if (! config('envios.alerts.enabled.warning', true)) {
            return;
        }

        // Verificar cooldown
        $cacheKey = 'alerta_warning_'.md5($titulo);
        if ($this->estaDentroDeCooldown($cacheKey)) {
            Log::info('[Alertas] Alerta warning en cooldown, omitiendo', ['titulo' => $titulo]);

            return;
        }

        Log::warning('[Alertas] Enviando alerta WARNING', [
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'contexto' => $contexto,
        ]);

        try {
            $this->enviarEmailWarning($titulo, $mensaje, $contexto);
            $this->marcarCooldown($cacheKey);
        } catch (\Exception $e) {
            Log::error('[Alertas] Error enviando alerta warning', [
                'error' => $e->getMessage(),
                'titulo' => $titulo,
            ]);
        }
    }

    /**
     * Envía una alerta informativa (solo Email)
     *
     * Usado para: resumen diario, notificaciones generales
     */
    public function alertaInfo(string $titulo, string $mensaje, array $contexto = []): void
    {
        if (! config('envios.alerts.enabled.info', true)) {
            return;
        }

        Log::info('[Alertas] Enviando alerta INFO', ['titulo' => $titulo]);

        try {
            $this->enviarEmailResumen($titulo, $mensaje, $contexto);
        } catch (\Exception $e) {
            Log::error('[Alertas] Error enviando alerta info', [
                'error' => $e->getMessage(),
                'titulo' => $titulo,
            ]);
        }
    }

    /**
     * Genera y envía el resumen diario de métricas
     */
    public function enviarResumenDiario(): void
    {
        $metricas = $this->obtenerMetricasResumen();

        $titulo = 'Resumen Diario - Nurturing Segal';
        $mensaje = $this->formatearResumenDiario($metricas);

        $this->alertaInfo($titulo, $mensaje, $metricas);

        Log::info('[Alertas] Resumen diario enviado', $metricas);
    }

    /**
     * Alerta cuando el circuit breaker se abre
     *
     * DISABLED: Con 20+ workers y alto volumen, el circuit breaker se abre/cierra
     * frecuentemente de forma normal. Solo se loguea, no se envía alerta.
     */
    public function alertaCircuitBreakerAbierto(string $canal, int $fallos): void
    {
        // Solo loguear, no enviar alerta por email/SMS
        Log::warning("[CircuitBreaker] Circuit breaker abierto para {$canal}", [
            'canal' => $canal,
            'fallos' => $fallos,
            'recovery_time' => config('envios.circuit_breaker.recovery_time'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Alerta cuando la tasa de error supera el umbral
     */
    public function alertaTasaErrorAlta(float $tasaError, int $enviosFallidos, int $enviosTotales): void
    {
        $umbral = config('envios.alerts.error_rate_threshold', 5);

        if ($tasaError < $umbral) {
            return;
        }

        $this->alertaWarning(
            "⚠️ Tasa de Error Alta: {$tasaError}%",
            "La tasa de error de envíos ha superado el umbral del {$umbral}%. ".
            "En la última hora: {$enviosFallidos} fallidos de {$enviosTotales} totales.",
            [
                'tasa_error' => $tasaError,
                'envios_fallidos' => $enviosFallidos,
                'envios_totales' => $enviosTotales,
                'umbral' => $umbral,
            ]
        );
    }

    /**
     * Alerta cuando la cola está saturada
     */
    public function alertaColaSaturada(int $jobsPendientes): void
    {
        $umbral = config('envios.alerts.queue_size_threshold', 1000);

        if ($jobsPendientes < $umbral) {
            return;
        }

        $this->alertaWarning(
            "⚠️ Cola Saturada: {$jobsPendientes} jobs pendientes",
            "La cola de envíos tiene {$jobsPendientes} trabajos pendientes, superando el umbral de {$umbral}. ".
            'Esto puede causar retrasos en los envíos.',
            [
                'jobs_pendientes' => $jobsPendientes,
                'umbral' => $umbral,
            ]
        );
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    /**
     * Envía SMS a todos los números configurados usando Athena Campaign
     */
    private function enviarSmsCritico(string $titulo, string $mensaje): void
    {
        $numeros = $this->getSmsNumbers();

        if (empty($numeros)) {
            Log::warning('[Alertas] No hay números SMS configurados para alertas críticas');

            return;
        }

        // Mensaje corto para SMS (máximo 160 caracteres)
        $smsTexto = mb_substr("[NURTURING] {$titulo}", 0, 160);

        /** @var AthenaCampaignService $athena */
        $athena = app(AthenaCampaignService::class);

        foreach ($numeros as $numero) {
            try {
                $resultado = $athena->enviarSmsDirecto($numero, $smsTexto);

                if ($resultado['success']) {
                    Log::info('[Alertas] SMS crítico enviado', ['numero' => $numero]);
                } else {
                    Log::error('[Alertas] Error enviando SMS crítico', [
                        'numero' => $numero,
                        'error' => $resultado['error'] ?? 'Unknown error',
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('[Alertas] Excepción enviando SMS crítico', [
                    'numero' => $numero,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Envía email de alerta crítica
     */
    private function enviarEmailCritico(string $titulo, string $mensaje, array $contexto): void
    {
        $emails = $this->getAlertEmails();

        if (empty($emails)) {
            Log::warning('[Alertas] No hay emails configurados para alertas');

            return;
        }

        Mail::to($emails)->send(new AlertaCriticaMail($titulo, $mensaje, $contexto));

        Log::info('[Alertas] Email crítico enviado', ['destinatarios' => $emails]);
    }

    /**
     * Envía email de alerta warning
     */
    private function enviarEmailWarning(string $titulo, string $mensaje, array $contexto): void
    {
        $emails = $this->getAlertEmails();

        if (empty($emails)) {
            return;
        }

        Mail::to($emails)->send(new AlertaWarningMail($titulo, $mensaje, $contexto));

        Log::info('[Alertas] Email warning enviado', ['destinatarios' => $emails]);
    }

    /**
     * Envía email de resumen/info
     */
    private function enviarEmailResumen(string $titulo, string $mensaje, array $contexto): void
    {
        $emails = $this->getAlertEmails();

        if (empty($emails)) {
            return;
        }

        Mail::to($emails)->send(new ResumenDiarioMail($titulo, $mensaje, $contexto));

        Log::info('[Alertas] Email resumen enviado', ['destinatarios' => $emails]);
    }

    /**
     * Obtiene las métricas para el resumen diario
     */
    private function obtenerMetricasResumen(): array
    {
        $ayer = now()->subDay();

        $totalEnvios = Envio::whereDate('created_at', $ayer->toDateString())->count();
        // Incluir 'abierto' y 'clickeado' como exitosos (son estados posteriores a 'enviado')
        $exitosos = Envio::whereDate('created_at', $ayer->toDateString())
            ->whereIn('estado', ['enviado', 'entregado', 'abierto', 'clickeado'])
            ->count();
        $fallidos = Envio::whereDate('created_at', $ayer->toDateString())
            ->where('estado', 'fallido')
            ->count();

        $tasaExito = $totalEnvios > 0 ? round(($exitosos / $totalEnvios) * 100, 2) : 0;

        $porCanal = [
            'email' => Envio::whereDate('created_at', $ayer->toDateString())->where('canal', 'email')->count(),
            'sms' => Envio::whereDate('created_at', $ayer->toDateString())->where('canal', 'sms')->count(),
        ];

        return [
            'fecha' => $ayer->format('d/m/Y'),
            'total_envios' => $totalEnvios,
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'tasa_exito' => $tasaExito,
            'por_canal' => $porCanal,
        ];
    }

    /**
     * Formatea el resumen diario como texto
     */
    private function formatearResumenDiario(array $metricas): string
    {
        return "📊 Resumen de Envíos - {$metricas['fecha']}\n\n".
            "Total de envíos: {$metricas['total_envios']}\n".
            "✅ Exitosos: {$metricas['exitosos']}\n".
            "❌ Fallidos: {$metricas['fallidos']}\n".
            "📈 Tasa de éxito: {$metricas['tasa_exito']}%\n\n".
            "Por canal:\n".
            "📧 Email: {$metricas['por_canal']['email']}\n".
            "📱 SMS: {$metricas['por_canal']['sms']}";
    }

    /**
     * Verifica si una alerta está en período de cooldown
     */
    private function estaDentroDeCooldown(string $cacheKey): bool
    {
        return Cache::has($cacheKey);
    }

    /**
     * Marca el inicio del período de cooldown
     */
    private function marcarCooldown(string $cacheKey): void
    {
        $minutos = config('envios.alerts.cooldown_minutes', 15);
        Cache::put($cacheKey, true, now()->addMinutes($minutos));
    }

    /**
     * Obtiene la lista de emails para alertas
     */
    private function getAlertEmails(): array
    {
        $emails = config('envios.alerts.emails', '');

        return array_filter(array_map('trim', explode(',', $emails)));
    }

    /**
     * Obtiene la lista de números SMS para alertas críticas
     */
    private function getSmsNumbers(): array
    {
        $numbers = config('envios.alerts.sms_numbers', '');

        return array_filter(array_map('trim', explode(',', $numbers)));
    }
}
