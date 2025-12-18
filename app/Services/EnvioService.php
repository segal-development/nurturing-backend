<?php

namespace App\Services;

use App\Models\Envio;
use App\Models\Prospecto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EnvioService
{
    public function __construct(
        private AthenaCampaignService $athenaService
    ) {}

    /**
     * Genera un token único para tracking de emails
     */
    private function generarTrackingToken(): string
    {
        return Str::random(64);
    }

    /**
     * Genera la URL del pixel de tracking
     */
    private function generarUrlPixelTracking(string $token): string
    {
        $baseUrl = config('app.url', 'http://localhost');
        return "{$baseUrl}/track/open/{$token}";
    }

    /**
     * Inyecta el pixel de tracking en el HTML del email
     */
    private function inyectarPixelTracking(string $html, string $trackingToken): string
    {
        $pixelUrl = $this->generarUrlPixelTracking($trackingToken);
        
        // Pixel invisible 1x1
        $pixelHtml = sprintf(
            '<img src="%s" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />',
            htmlspecialchars($pixelUrl)
        );

        // Insertar antes del cierre de </body> si existe, o al final
        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $pixelHtml . '</body>', $html);
        }

        // Si no hay </body>, agregar al final
        return $html . $pixelHtml;
    }

    /**
     * Reemplaza las URLs en el HTML con URLs de tracking
     * 
     * @param string $html HTML del email
     * @param int $envioId ID del envío
     * @return string HTML con URLs reemplazadas
     */
    private function reemplazarUrlsConTracking(string $html, int $envioId): string
    {
        $baseUrl = config('app.url', 'http://localhost');
        
        // Patrón para encontrar enlaces <a href="...">
        $pattern = '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*)>/i';
        
        $html = preg_replace_callback($pattern, function ($matches) use ($envioId, $baseUrl) {
            $beforeHref = $matches[1];
            $url = $matches[2];
            $afterHref = $matches[3];
            
            // No reemplazar:
            // - URLs que ya son de tracking
            // - mailto: links
            // - tel: links  
            // - URLs internas del sistema
            // - Anchors (#)
            if (
                str_contains($url, '/track/') ||
                str_starts_with($url, 'mailto:') ||
                str_starts_with($url, 'tel:') ||
                str_starts_with($url, '#') ||
                str_contains($url, $baseUrl)
            ) {
                return $matches[0]; // Devolver sin cambios
            }
            
            // Solo procesar URLs http/https válidas
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                return $matches[0]; // Devolver sin cambios
            }
            
            // Generar URL de tracking
            $urlId = substr(md5($url), 0, 8);
            $token = base64_encode("{$envioId}_{$urlId}");
            $urlEncoded = base64_encode($url);
            $trackingUrl = "{$baseUrl}/track/click/{$token}?url={$urlEncoded}";
            
            return "<a {$beforeHref}href=\"{$trackingUrl}\"{$afterHref}>";
        }, $html);
        
        return $html;
    }

    /**
     * Envía un mensaje (email o SMS) a múltiples prospectos
     *
     * @param  string  $tipoMensaje  'email' | 'sms'
     * @param  \Illuminate\Support\Collection  $prospectosEnFlujo  Colección de ProspectoEnFlujo
     * @param  string  $contenido  Contenido del mensaje
     * @param  array|null  $template  Plantilla opcional (asunto, etc)
     * @param  \App\Models\Flujo|null  $flujo  Flujo asociado (opcional)
     * @param  int|null  $etapaEjecucionId  ID de etapa de ejecución (opcional)
     * @param  bool  $esHtml  Si el contenido es HTML (para emails)
     * @return array Respuesta de AthenaCampaign con messageID
     */
    public function enviar(
        string $tipoMensaje,
        \Illuminate\Support\Collection $prospectosEnFlujo,
        string $contenido,
        ?array $template = null,
        ?\App\Models\Flujo $flujo = null,
        ?int $etapaEjecucionId = null,
        bool $esHtml = false
    ): array {
        if ($prospectosEnFlujo->isEmpty()) {
            throw new \Exception('No se encontraron prospectos para enviar');
        }

        if ($tipoMensaje === 'email') {
            return $this->enviarEmail($prospectosEnFlujo, $contenido, $template, $flujo, $etapaEjecucionId, $esHtml);
        } elseif ($tipoMensaje === 'sms') {
            return $this->enviarSms($prospectosEnFlujo, $contenido, $flujo, $etapaEjecucionId);
        } else {
            throw new \Exception("Tipo de mensaje no soportado: {$tipoMensaje}");
        }
    }

    /**
     * Envía emails a prospectos
     * 
     * @param bool $esHtml Si es true, envía como HTML; si false, como texto plano
     */
    private function enviarEmail(
        \Illuminate\Support\Collection $prospectosEnFlujo,
        string $contenido,
        ?array $template = null,
        ?\App\Models\Flujo $flujo = null,
        ?int $etapaEjecucionId = null,
        bool $esHtml = false
    ): array {
        // Filtrar solo prospectos con email válido
        $prospectosConEmail = $prospectosEnFlujo->filter(function ($pef) {
            return ! empty($pef->prospecto->email);
        });

        if ($prospectosConEmail->isEmpty()) {
            throw new \Exception('Ningún prospecto tiene email válido');
        }

        Log::info('EnvioService: Enviando emails via SMTP', [
            'cantidad' => $prospectosConEmail->count(),
            'es_html' => $esHtml,
        ]);

        $exitosos = 0;
        $errores = [];

        // Enviar email a cada prospecto usando SMTP de Laravel
        foreach ($prospectosConEmail as $prospectoEnFlujo) {
            $prospecto = $prospectoEnFlujo->prospecto;
            $envio = null;

            try {
                $asunto = $template['asunto'] ?? 'Mensaje de Grupo Segal';
                $contenidoPersonalizado = $this->personalizarContenido($contenido, $prospecto);

                // Generar token de tracking único para este envío
                $trackingToken = $this->generarTrackingToken();

                // Si es HTML, inyectar el pixel de tracking
                $contenidoFinal = $contenidoPersonalizado;
                if ($esHtml) {
                    $contenidoFinal = $this->inyectarPixelTracking($contenidoPersonalizado, $trackingToken);
                }

                // Crear registro de envío como pendiente (necesitamos el ID para tracking de clicks)
                $envio = Envio::create([
                    'prospecto_id' => $prospecto->id,
                    'prospecto_en_flujo_id' => $prospectoEnFlujo->id,
                    'flujo_id' => $flujo?->id,
                    'etapa_flujo_id' => null,
                    'flujo_ejecucion_etapa_id' => $etapaEjecucionId,
                    'asunto' => $asunto,
                    'contenido_enviado' => $contenidoFinal,
                    'canal' => 'email',
                    'destinatario' => $prospecto->email,
                    'tracking_token' => $trackingToken,
                    'estado' => 'pendiente',
                    'fecha_programada' => now(),
                ]);

                // Si es HTML, reemplazar URLs con URLs de tracking (ahora que tenemos el envio_id)
                if ($esHtml) {
                    $contenidoFinal = $this->reemplazarUrlsConTracking($contenidoFinal, $envio->id);
                    // Actualizar el contenido guardado
                    $envio->update(['contenido_enviado' => $contenidoFinal]);
                }

                // Enviar como HTML o texto plano según el tipo de contenido
                if ($esHtml) {
                    \Mail::html($contenidoFinal, function ($message) use ($prospecto, $asunto) {
                        $message->to($prospecto->email, $prospecto->nombre)
                            ->subject($asunto);
                    });
                } else {
                    \Mail::raw($contenidoFinal, function ($message) use ($prospecto, $asunto) {
                        $message->to($prospecto->email, $prospecto->nombre)
                            ->subject($asunto);
                    });
                }

                // Marcar como enviado
                $envio->marcarComoEnviado();
                $exitosos++;

                Log::info('EnvioService: Email enviado', [
                    'email' => $prospecto->email,
                    'envio_id' => $envio->id,
                ]);
            } catch (\Exception $e) {
                // Marcar como fallido si existe el registro
                if ($envio) {
                    $envio->marcarComoFallido($e->getMessage());
                }

                $errores[] = [
                    'email' => $prospecto->email,
                    'error' => $e->getMessage(),
                ];

                Log::error('EnvioService: Error al enviar email', [
                    'email' => $prospecto->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Generar un messageID simulado
        $messageId = rand(10000, 99999);

        Log::info('EnvioService: Resumen de envío', [
            'exitosos' => $exitosos,
            'errores' => count($errores),
        ]);

        return [
            'error' => false,
            'codigo' => 200,
            'mensaje' => [
                'messageID' => $messageId,
                'Recipients' => $exitosos,
                'Errores' => count($errores),
            ],
        ];
    }

    /**
     * Envía SMS a prospectos
     */
    private function enviarSms($prospectos, string $contenido): array
    {
        // Filtrar solo prospectos con teléfono
        $prospectosConTelefono = $prospectos->filter(fn ($p) => ! empty($p->telefono));

        if ($prospectosConTelefono->isEmpty()) {
            throw new \Exception('Ningún prospecto tiene teléfono válido');
        }

        // Preparar destinatarios
        $destinatarios = $prospectosConTelefono->map(function ($prospecto) {
            return [
                'telefono' => $prospecto->telefono,
                'nombre' => $prospecto->nombre,
            ];
        })->toArray();

        // Configurar mensaje para AthenaCampaign
        $config = [
            'tipo' => 'sms',
            'destinatarios' => $destinatarios,
            'contenido' => $contenido,
        ];

        Log::info('EnvioService: Enviando SMS', [
            'cantidad' => count($destinatarios),
        ]);

        return $this->athenaService->enviarMensaje($config);
    }

    /**
     * Personaliza el contenido con variables del prospecto
     */
    private function personalizarContenido(string $contenido, Prospecto $prospecto): string
    {
        $variables = [
            '{{nombre}}' => $prospecto->nombre,
            '{{email}}' => $prospecto->email,
            '{{telefono}}' => $prospecto->telefono,
            // Agregar más variables según necesidad
        ];

        return str_replace(
            array_keys($variables),
            array_values($variables),
            $contenido
        );
    }
}
