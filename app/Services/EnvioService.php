<?php

namespace App\Services;

use App\Models\Envio;
use App\Models\Prospecto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\DesuscripcionService;

class EnvioService
{
    public function __construct(
        private AthenaCampaignService $athenaService,
        private DesuscripcionService $desuscripcionService
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
            return str_ireplace('</body>', $pixelHtml.'</body>', $html);
        }

        // Si no hay </body>, agregar al final
        return $html.$pixelHtml;
    }

    /**
     * Reemplaza las URLs en el HTML con URLs de tracking
     *
     * @param  string  $html  HTML del email
     * @param  int  $envioId  ID del envío
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
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
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
     * Envía emails a prospectos (modo síncrono - usar solo para volúmenes pequeños)
     *
     * @deprecated Use enviarEmailAProspecto() con jobs para volúmenes grandes
     *
     * @param  bool  $esHtml  Si es true, envía como HTML; si false, como texto plano
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
            try {
                $result = $this->enviarEmailAProspecto(
                    prospectoEnFlujo: $prospectoEnFlujo,
                    contenido: $contenido,
                    asunto: $template['asunto'] ?? 'Mensaje de Grupo Segal',
                    flujoId: $flujo?->id,
                    etapaEjecucionId: $etapaEjecucionId,
                    esHtml: $esHtml
                );

                if ($result['success']) {
                    $exitosos++;
                } else {
                    $errores[] = [
                        'email' => $prospectoEnFlujo->prospecto->email,
                        'error' => $result['error'],
                    ];
                }
            } catch (\Exception $e) {
                $errores[] = [
                    'email' => $prospectoEnFlujo->prospecto->email ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
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
     * Envía un email a UN SOLO prospecto.
     *
     * Este método es el building block para procesamiento en batch.
     * Incluye: personalización, tracking de aperturas, tracking de clicks.
     *
     * @param  \App\Models\ProspectoEnFlujo  $prospectoEnFlujo  El prospecto en flujo
     * @param  string  $contenido  Contenido del email (puede tener variables)
     * @param  string  $asunto  Asunto del email
     * @param  int|null  $flujoId  ID del flujo
     * @param  int|null  $etapaEjecucionId  ID de la etapa de ejecución
     * @param  bool  $esHtml  Si el contenido es HTML
     * @return array{success: bool, envio_id: int|null, error: string|null}
     */
    public function enviarEmailAProspecto(
        \App\Models\ProspectoEnFlujo $prospectoEnFlujo,
        string $contenido,
        string $asunto,
        ?int $flujoId = null,
        ?int $etapaEjecucionId = null,
        bool $esHtml = false
    ): array {
        $prospecto = $prospectoEnFlujo->prospecto;

        if (empty($prospecto->email)) {
            return [
                'success' => false,
                'envio_id' => null,
                'error' => 'Prospecto no tiene email válido',
            ];
        }

        // Verificar si ya existe un envío para este prospecto en esta etapa (evitar duplicados)
        if ($etapaEjecucionId) {
            $envioExistente = Envio::where('prospecto_id', $prospecto->id)
                ->where('flujo_ejecucion_etapa_id', $etapaEjecucionId)
                ->whereIn('estado', ['enviado', 'abierto', 'clickeado', 'pendiente'])
                ->first();

            if ($envioExistente) {
                Log::debug('EnvioService: Email ya enviado para este prospecto en esta etapa, omitiendo', [
                    'prospecto_id' => $prospecto->id,
                    'etapa_ejecucion_id' => $etapaEjecucionId,
                    'envio_existente_id' => $envioExistente->id,
                    'estado' => $envioExistente->estado,
                ]);
                return [
                    'success' => true, // Consideramos éxito porque ya se envió
                    'envio_id' => $envioExistente->id,
                    'error' => null,
                    'skipped' => true, // Flag para indicar que se omitió por duplicado
                ];
            }
        }

        // Verificar si el prospecto puede recibir emails (no desuscrito)
        if (!$prospecto->puedeRecibirComunicacion('email')) {
            Log::info('EnvioService: Prospecto desuscrito de emails', [
                'prospecto_id' => $prospecto->id,
                'email' => $prospecto->email,
            ]);
            return [
                'success' => false,
                'envio_id' => null,
                'error' => 'Prospecto desuscrito de comunicaciones por email',
            ];
        }

        $envio = null;

        try {
            $contenidoPersonalizado = $this->personalizarContenido($contenido, $prospecto);
            $trackingToken = $this->generarTrackingToken();
            $contenidoFinal = $this->prepararContenidoConTracking(
                $contenidoPersonalizado,
                $trackingToken,
                $esHtml,
                $prospecto->id,
                $flujoId
            );

            $envio = $this->crearRegistroEnvio(
                prospecto: $prospecto,
                prospectoEnFlujo: $prospectoEnFlujo,
                asunto: $asunto,
                contenido: $contenidoFinal,
                trackingToken: $trackingToken,
                flujoId: $flujoId,
                etapaEjecucionId: $etapaEjecucionId
            );

            // Si es HTML, agregar tracking de clicks (necesita envio_id)
            if ($esHtml) {
                $contenidoFinal = $this->reemplazarUrlsConTracking($contenidoFinal, $envio->id);
                $envio->update(['contenido_enviado' => $contenidoFinal]);
            }

            $this->enviarPorSmtp($prospecto, $asunto, $contenidoFinal, $esHtml);
            $envio->marcarComoEnviado();

            Log::info('EnvioService: Email enviado', [
                'email' => $prospecto->email,
                'envio_id' => $envio->id,
            ]);

            return [
                'success' => true,
                'envio_id' => $envio->id,
                'error' => null,
            ];

        } catch (\Exception $e) {
            if ($envio) {
                $envio->marcarComoFallido($e->getMessage());
            }

            Log::error('EnvioService: Error al enviar email', [
                'email' => $prospecto->email ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'envio_id' => $envio?->id,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prepara el contenido del email con tracking de aperturas y footer de desuscripción
     */
    private function prepararContenidoConTracking(
        string $contenido,
        string $trackingToken,
        bool $esHtml,
        int $prospectoId,
        ?int $flujoId = null
    ): string {
        if (! $esHtml) {
            return $contenido;
        }

        // Inyectar pixel de tracking
        $contenido = $this->inyectarPixelTracking($contenido, $trackingToken);

        // Agregar footer de desuscripción
        $contenido = $this->inyectarFooterDesuscripcion($contenido, $prospectoId, $flujoId);

        return $contenido;
    }

    /**
     * Inyecta el footer de desuscripción en el HTML del email
     */
    private function inyectarFooterDesuscripcion(string $html, int $prospectoId, ?int $flujoId = null): string
    {
        $footer = $this->desuscripcionService->generarFooterDesuscripcion($prospectoId, null, $flujoId);

        // Insertar antes del cierre de </body> si existe
        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $footer . '</body>', $html);
        }

        // Si no hay </body>, agregar al final
        return $html . $footer;
    }

    /**
     * Crea el registro de Envio en la base de datos
     */
    private function crearRegistroEnvio(
        Prospecto $prospecto,
        \App\Models\ProspectoEnFlujo $prospectoEnFlujo,
        string $asunto,
        string $contenido,
        string $trackingToken,
        ?int $flujoId,
        ?int $etapaEjecucionId
    ): Envio {
        return Envio::create([
            'prospecto_id' => $prospecto->id,
            'prospecto_en_flujo_id' => $prospectoEnFlujo->id,
            'flujo_id' => $flujoId,
            'etapa_flujo_id' => null,
            'flujo_ejecucion_etapa_id' => $etapaEjecucionId,
            'asunto' => $asunto,
            'contenido_enviado' => $contenido,
            'canal' => 'email',
            'destinatario' => $prospecto->email,
            'tracking_token' => $trackingToken,
            'estado' => 'pendiente',
            'fecha_programada' => now(),
        ]);
    }

    /**
     * Envía el email usando SMTP de Laravel
     */
    private function enviarPorSmtp(
        Prospecto $prospecto,
        string $asunto,
        string $contenido,
        bool $esHtml
    ): void {
        if ($esHtml) {
            \Mail::html($contenido, function ($message) use ($prospecto, $asunto) {
                $message->to($prospecto->email, $prospecto->nombre)
                    ->subject($asunto);
            });
        } else {
            \Mail::raw($contenido, function ($message) use ($prospecto, $asunto) {
                $message->to($prospecto->email, $prospecto->nombre)
                    ->subject($asunto);
            });
        }
    }

    /**
     * Envía SMS a prospectos (modo síncrono - usar solo para volúmenes pequeños)
     *
     * @deprecated Use enviarSmsAProspecto() con jobs para volúmenes grandes
     */
    private function enviarSms(
        \Illuminate\Support\Collection $prospectosEnFlujo,
        string $contenido,
        ?\App\Models\Flujo $flujo = null,
        ?int $etapaEjecucionId = null
    ): array {
        // Filtrar solo prospectos con teléfono
        $prospectosConTelefono = $prospectosEnFlujo->filter(function ($pef) {
            return ! empty($pef->prospecto->telefono);
        });

        if ($prospectosConTelefono->isEmpty()) {
            throw new \Exception('Ningún prospecto tiene teléfono válido');
        }

        Log::info('EnvioService: Enviando SMS', [
            'cantidad' => $prospectosConTelefono->count(),
        ]);

        $exitosos = 0;
        $errores = [];

        foreach ($prospectosConTelefono as $prospectoEnFlujo) {
            try {
                $result = $this->enviarSmsAProspecto(
                    prospectoEnFlujo: $prospectoEnFlujo,
                    contenido: $contenido,
                    flujoId: $flujo?->id,
                    etapaEjecucionId: $etapaEjecucionId
                );

                if ($result['success']) {
                    $exitosos++;
                } else {
                    $errores[] = [
                        'telefono' => $prospectoEnFlujo->prospecto->telefono,
                        'error' => $result['error'],
                    ];
                }
            } catch (\Exception $e) {
                $errores[] = [
                    'telefono' => $prospectoEnFlujo->prospecto->telefono ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $messageId = rand(10000, 99999);

        Log::info('EnvioService: Resumen de envío SMS', [
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
     * Envía un SMS a UN SOLO prospecto.
     *
     * Este método es el building block para procesamiento en batch.
     *
     * @param  \App\Models\ProspectoEnFlujo  $prospectoEnFlujo  El prospecto en flujo
     * @param  string  $contenido  Contenido del SMS (puede tener variables)
     * @param  int|null  $flujoId  ID del flujo
     * @param  int|null  $etapaEjecucionId  ID de la etapa de ejecución
     * @return array{success: bool, envio_id: int|null, error: string|null}
     */
    public function enviarSmsAProspecto(
        \App\Models\ProspectoEnFlujo $prospectoEnFlujo,
        string $contenido,
        ?int $flujoId = null,
        ?int $etapaEjecucionId = null
    ): array {
        $prospecto = $prospectoEnFlujo->prospecto;

        if (empty($prospecto->telefono)) {
            return [
                'success' => false,
                'envio_id' => null,
                'error' => 'Prospecto no tiene teléfono válido',
            ];
        }

        $envio = null;

        try {
            $contenidoPersonalizado = $this->personalizarContenido($contenido, $prospecto);

            $envio = Envio::create([
                'prospecto_id' => $prospecto->id,
                'prospecto_en_flujo_id' => $prospectoEnFlujo->id,
                'flujo_id' => $flujoId,
                'etapa_flujo_id' => null,
                'flujo_ejecucion_etapa_id' => $etapaEjecucionId,
                'asunto' => null,
                'contenido_enviado' => $contenidoPersonalizado,
                'canal' => 'sms',
                'destinatario' => $prospecto->telefono,
                'estado' => 'pendiente',
                'fecha_programada' => now(),
            ]);

            // Enviar SMS via AthenaCampaign
            $response = $this->athenaService->enviarMensaje([
                'tipo' => 'sms',
                'destinatarios' => [[
                    'telefono' => $prospecto->telefono,
                    'nombre' => $prospecto->nombre,
                ]],
                'contenido' => $contenidoPersonalizado,
            ]);

            $envio->marcarComoEnviado();

            Log::info('EnvioService: SMS enviado', [
                'telefono' => $prospecto->telefono,
                'envio_id' => $envio->id,
            ]);

            return [
                'success' => true,
                'envio_id' => $envio->id,
                'error' => null,
            ];

        } catch (\Exception $e) {
            if ($envio) {
                $envio->marcarComoFallido($e->getMessage());
            }

            Log::error('EnvioService: Error al enviar SMS', [
                'telefono' => $prospecto->telefono ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'envio_id' => $envio?->id,
                'error' => $e->getMessage(),
            ];
        }
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
