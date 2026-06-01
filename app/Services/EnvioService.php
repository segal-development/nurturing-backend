<?php

namespace App\Services;

use App\Models\Envio;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Services\Email\EmailProviderResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EnvioService
{
    /**
     * Estados que bloquean el re-envío de un prospecto para la misma etapa y canal.
     * 'fallido' NO está incluido — los envíos fallidos deben reintentarse.
     */
    public const ESTADOS_BLOQUEANTES = ['enviado', 'abierto', 'clickeado', 'pendiente'];

    public function __construct(
        private AthenaCampaignService $athenaService,
        private DesuscripcionService $desuscripcionService,
        private EmailValidationService $emailValidationService,
        private EmailProviderResolver $emailProviderResolver,
    ) {}

    /**
     * Carga el Set de pares (prospecto_id, canal) que ya tienen un envío en estado bloqueante
     * para la etapa dada. Usado por los orquestadores (EnviarEtapaJob / EnviarEtapaChunkJob)
     * para evitar encolar leaf jobs duplicados antes de despacharlos.
     *
     * @param  int       $etapaEjecucionId  ID de la FlujoEjecucionEtapa
     * @param  int[]|null $prospectoIds     Si se especifica, acota el DISTINCT a solo esos IDs (útil para chunks)
     * @return array<string, true>          Set con claves "{prospecto_id}:{canal}" => true
     */
    public function cargarEnviosBloqueantes(int $etapaEjecucionId, ?array $prospectoIds = null): array
    {
        $query = Envio::query()
            ->select(['prospecto_id', 'canal'])
            ->distinct()
            ->where('flujo_ejecucion_etapa_id', $etapaEjecucionId)
            ->whereIn('estado', self::ESTADOS_BLOQUEANTES);

        if ($prospectoIds !== null) {
            $query->whereIn('prospecto_id', $prospectoIds);
        }

        $set = [];
        foreach ($query->cursor() as $row) {
            $set["{$row->prospecto_id}:{$row->canal}"] = true;
        }

        return $set;
    }

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
    /**
     * @param string|null $providerName Pre-resolved provider name ('athena'|'certificada').
     *                                   When provided, skips provider resolution for N+1 optimization.
     */
    public function enviarEmailAProspecto(
        \App\Models\ProspectoEnFlujo $prospectoEnFlujo,
        string $contenido,
        string $asunto,
        ?int $flujoId = null,
        ?int $etapaEjecucionId = null,
        bool $esHtml = false,
        ?string $senderEmail = null,
        ?string $senderName = null,
        ?string $providerName = null
    ): array {
        $prospecto = $prospectoEnFlujo->prospecto;

        if (empty($prospecto->email)) {
            return [
                'success' => false,
                'envio_id' => null,
                'error' => 'Prospecto no tiene email válido',
            ];
        }

        // =====================================================================
        // VALIDACIÓN DE EMAIL - Detectar emails inválidos ANTES de enviar
        // =====================================================================

        // Si ya está marcado como inválido, no intentar enviar
        if ($prospecto->isEmailInvalido()) {
            Log::debug('EnvioService: Email ya marcado como inválido, omitiendo', [
                'prospecto_id' => $prospecto->id,
                'email' => $prospecto->email,
                'motivo' => $prospecto->email_invalido_motivo,
            ]);

            return [
                'success' => false,
                'envio_id' => null,
                'error' => 'Email marcado como inválido: '.$prospecto->email_invalido_motivo,
                'email_invalido' => true,
            ];
        }

        // Validar formato y dominio del email ANTES de intentar enviar
        $validacion = $this->emailValidationService->validar($prospecto->email);
        if (! $validacion['valid']) {
            $prospecto->marcarEmailInvalido($validacion['motivo']);

            Log::info('EnvioService: Email detectado como inválido en pre-validación', [
                'prospecto_id' => $prospecto->id,
                'email' => $prospecto->email,
                'motivo' => $validacion['motivo'],
                'sugerencia' => $validacion['sugerencia'],
            ]);

            return [
                'success' => false,
                'envio_id' => null,
                'error' => 'Email inválido: '.$validacion['motivo'],
                'email_invalido' => true,
                'sugerencia' => $validacion['sugerencia'],
            ];
        }

        // =====================================================================
        // VALIDACIONES EXISTENTES
        // =====================================================================

        // Verificar si ya existe un envío para este prospecto en esta etapa (evitar duplicados)
        if ($etapaEjecucionId) {
            $envioExistente = Envio::where('prospecto_id', $prospecto->id)
                ->where('flujo_ejecucion_etapa_id', $etapaEjecucionId)
                ->where('canal', 'email')
                ->whereIn('estado', self::ESTADOS_BLOQUEANTES)
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
        if (! $prospecto->puedeRecibirComunicacion('email')) {
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

        // =====================================================================
        // ENVÍO DEL EMAIL
        // =====================================================================

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

            // Resolve which email provider to use
            // If providerName was pre-resolved at batch level, use it to avoid N+1 queries
            if ($providerName !== null) {
                $emailService = $this->emailProviderResolver->getServiceByName($providerName);
                // providerName already set from parameter
            } else {
                // Fallback: resolve per-prospecto (used when job doesn't have pre-resolved provider)
                $emailService = $this->emailProviderResolver->resolve($prospecto);
                $providerName = $this->emailProviderResolver->getProviderName($prospecto);
            }

            // Determine sender: use passed params first, then flujo's sender config, then service defaults
            $effectiveSenderEmail = $senderEmail;
            $effectiveSenderName = $senderName;

            if (! $effectiveSenderEmail && $flujoId) {
                $flujo = \App\Models\Flujo::find($flujoId);
                if ($flujo) {
                    $effectiveSenderEmail = $flujo->sender_email;
                    $effectiveSenderName = $flujo->sender_name;
                }
            }

            $result = $emailService->send($prospecto, $asunto, $contenidoFinal, $esHtml, $effectiveSenderEmail, $effectiveSenderName);

            // Update the envio with provider info
            $envio->email_provider = $providerName;
            if ($result['message_id']) {
                $envio->external_message_id = $result['message_id'];
            }

            if (! $result['success']) {
                $envio->estado = 'fallido';
                $envio->metadata = array_merge($envio->metadata ?? [], [
                    'error' => $result['error'],
                ]);
                $envio->save();
                throw new \Exception("Failed to send email: {$result['error']}");
            }

            $envio->save();
            $envio->marcarComoEnviado();

            // Update prospect progress tracking after successful send
            $this->actualizarProgresoProspecto($prospectoEnFlujo, $etapaEjecucionId);

            Log::info('EnvioService: Email enviado', [
                'email' => $prospecto->email,
                'envio_id' => $envio->id,
                'provider' => $providerName,
                'message_id' => $result['message_id'] ?? null,
                'sender_email' => $effectiveSenderEmail ?? 'default',
            ]);

            return [
                'success' => true,
                'envio_id' => $envio->id,
                'error' => null,
            ];

        } catch (\Exception $e) {
            // Unique violation (SQLSTATE 23505) en envios = otro job concurrente ya registró el
            // envío para este (prospecto, etapa, canal). Es la idempotencia a nivel DB haciendo
            // su trabajo, NO un fallo de envío: no marcamos fallido, no analizamos como error SMTP
            // (evita el falso positivo que marcaba el email inválido y disparaba el circuit breaker),
            // no contamos como fallo. Se trata como skip idempotente.
            if ($this->esUniqueViolationEnvio($e)) {
                Log::info('EnvioService: Envío ya registrado por job concurrente (unique violation) — skip idempotente', [
                    'prospecto_id' => $prospecto->id,
                    'etapa_ejecucion_id' => $etapaEjecucionId,
                ]);

                return [
                    'success' => true,
                    'envio_id' => null,
                    'error' => null,
                    'skipped' => true,
                ];
            }

            if ($envio) {
                $envio->marcarComoFallido($e->getMessage());
            }

            // =====================================================================
            // DETECCIÓN DE EMAILS INVÁLIDOS POR ERROR DE ENVÍO
            // =====================================================================
            $emailMarcado = $this->emailValidationService->procesarErrorEnvio($prospecto, $e->getMessage());

            Log::error('EnvioService: Error al enviar email', [
                'email' => $prospecto->email ?? 'unknown',
                'error' => $e->getMessage(),
                'email_marcado_invalido' => $emailMarcado,
            ]);

            return [
                'success' => false,
                'envio_id' => $envio?->id,
                'error' => $e->getMessage(),
                'email_invalido' => $emailMarcado,
            ];
        }
    }

    /**
     * ¿La excepción es una unique violation (SQLSTATE 23505) de la tabla envios?
     * Indica que un job concurrente ya registró el envío (idempotencia a nivel DB),
     * no un fallo real de envío.
     */
    private function esUniqueViolationEnvio(\Throwable $e): bool
    {
        if (! $e instanceof \Illuminate\Database\QueryException) {
            return false;
        }

        // PDO SQLSTATE 23505 = unique_violation en PostgreSQL.
        return (string) $e->getCode() === '23505';
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
            return str_ireplace('</body>', $footer.'</body>', $html);
        }

        // Si no hay </body>, agregar al final
        return $html.$footer;
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

    // NOTE: enviarPorSmtp() removed - now using EmailProviderResolver to determine
    // the appropriate email service (SmtpEmailService or CertificadaEmailService)

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

        // =====================================================================
        // VERIFICAR DUPLICADOS - Evitar enviar SMS múltiples veces al mismo prospecto
        // =====================================================================

        // Verificar si ya existe un envío para este prospecto en esta etapa (evitar duplicados)
        if ($etapaEjecucionId) {
            $envioExistente = Envio::where('prospecto_id', $prospecto->id)
                ->where('flujo_ejecucion_etapa_id', $etapaEjecucionId)
                ->where('canal', 'sms')
                ->whereIn('estado', self::ESTADOS_BLOQUEANTES)
                ->first();

            if ($envioExistente) {
                Log::debug('EnvioService: SMS ya enviado para este prospecto en esta etapa, omitiendo', [
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

        $envio = null;

        try {
            $contenidoPersonalizado = $this->personalizarContenido($contenido, $prospecto);

            // Truncar SMS si excede 160 caracteres (después de personalización)
            $contenidoPersonalizado = $this->truncarSmsSimesNecesario($contenidoPersonalizado, $prospecto->id);

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

            // Update prospect progress tracking after successful send
            $this->actualizarProgresoProspecto($prospectoEnFlujo, $etapaEjecucionId);

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
        // Buscar todas las variables en el contenido: {{variable}} o {variable}
        // Soporta notación punto para metadata: {{abogado.Nombre}}, {{cuotas.0.Monto}}
        return preg_replace_callback(
            '/\{\{?([a-zA-Z0-9_.]+)\}?\}/',
            fn ($matches) => $this->resolverVariable($matches[1], $prospecto),
            $contenido
        );
    }

    /**
     * Resuelve el valor de una variable para un prospecto.
     *
     * Orden de resolución:
     * 1. Variables de sistema (fecha_hoy, etc.)
     * 2. Campos fijos del prospecto con formato especial (monto_deuda, monto)
     * 3. Campos fijos del prospecto (nombre, email, telefono, etc.)
     * 4. Variables de conveniencia para abogado y cuotas
     * 5. Metadata con notación punto (Abogado.Nombre, Cuotas.0.Monto)
     */
    private function resolverVariable(string $variable, Prospecto $prospecto): string
    {
        // 1. Variables de sistema
        $sistemVars = [
            'fecha_hoy' => now()->format('d/m/Y'),
            'fecha_hora' => now()->format('d/m/Y H:i'),
            'anio' => now()->format('Y'),
        ];

        if (isset($sistemVars[$variable])) {
            return $sistemVars[$variable];
        }

        // 2. Campos con formato especial
        if ($variable === 'monto_deuda' || $variable === 'monto') {
            return $prospecto->monto_deuda
                ? '$'.number_format($prospecto->monto_deuda, 0, ',', '.')
                : '';
        }

        // 3. Campos fijos del prospecto
        $camposFijos = ['nombre', 'email', 'telefono', 'rut', 'url_informe', 'estado'];

        if (in_array($variable, $camposFijos)) {
            return (string) ($prospecto->{$variable} ?? '');
        }

        // 4. Variables de conveniencia para datos de Grupo Deudas
        $metadata = $prospecto->metadata ?? [];
        
        // Variables de abogado (conveniencia)
        if ($variable === 'nombre_abogado') {
            return $this->resolverNombreAbogado($metadata);
        }
        if ($variable === 'email_abogado') {
            return (string) (data_get($metadata, 'Abogado.Email') ?? '');
        }
        if ($variable === 'telefono_abogado') {
            $telefono = data_get($metadata, 'Abogado.Telefono');
            return $telefono ? '+56' . ltrim($telefono, '+56') : '';
        }
        
        // Variables de cuota actual (primera cuota del array)
        if ($variable === 'numero_contrato') {
            return (string) (data_get($metadata, 'Cuotas.0.Contrato') ?? data_get($metadata, 'Id') ?? '');
        }
        if ($variable === 'numero_cuota') {
            return (string) (data_get($metadata, 'Cuotas.0.Cuota') ?? '');
        }
        if ($variable === 'monto_cuota') {
            $monto = data_get($metadata, 'Cuotas.0.Monto');
            return $monto ? '$' . number_format((int) $monto, 0, ',', '.') : '';
        }
        if ($variable === 'fecha_vencimiento') {
            $fecha = data_get($metadata, 'Cuotas.0.Vencimiento');
            return $fecha ? $this->formatearFecha($fecha) : '';
        }
        if ($variable === 'estado_cuota') {
            return (string) (data_get($metadata, 'Cuotas.0.Estado') ?? '');
        }
        
        // Tabla de cuotas renderizada (HTML) - CON estado (para confirmaciones de pago)
        if ($variable === 'tabla_cuotas') {
            return $this->renderizarTablaCuotas($metadata);
        }
        
        // Tabla de cuotas pendientes - SIN estado (para recordatorios)
        // Muestra solo cuotas VIGENTES y MOROSAS
        if ($variable === 'tabla_cuotas_pendientes') {
            return $this->renderizarTablaCuotasPendientes($metadata);
        }
        
        // Próxima cuota a vencer - SIN estado (para avisos puntuales)
        // Muestra solo la cuota más próxima a vencer
        if ($variable === 'tabla_proxima_cuota') {
            return $this->renderizarProximaCuota($metadata);
        }
        
        // Link de pago
        if ($variable === 'link_pago') {
            return 'https://system.segal.cl/';
        }

        // 5. Metadata con notación punto
        // Soporta: {{nivel_deuda}}, {{Abogado.Nombre}}, {{Cuotas.0.Monto}}
        if (empty($metadata)) {
            return ''; // No hay metadata, retornar vacío
        }

        return (string) (data_get($metadata, $variable) ?? '');
    }
    
    /**
     * Resuelve el nombre completo del abogado desde metadata.
     */
    private function resolverNombreAbogado(array $metadata): string
    {
        $abogado = data_get($metadata, 'Abogado');
        
        if (!$abogado || !is_array($abogado)) {
            return '';
        }
        
        // Si no hay ID o ID es 0, no hay abogado asignado
        if (empty($abogado['Id']) || $abogado['Id'] === '0') {
            return 'Sin abogado asignado';
        }
        
        $nombre = $abogado['Nombre'] ?? '';
        $apellidoPaterno = $abogado['Apellido_Paterno'] ?? '';
        $apellidoMaterno = $abogado['Apellido_Materno'] ?? '';
        
        return trim("{$nombre} {$apellidoPaterno} {$apellidoMaterno}");
    }
    
    /**
     * Formatea una fecha de Y-m-d a d/m/Y
     */
    private function formatearFecha(string $fecha): string
    {
        try {
            return \Carbon\Carbon::parse($fecha)->format('d/m/Y');
        } catch (\Exception $e) {
            return $fecha; // Devolver sin formato si falla
        }
    }
    
    /**
     * Renderiza la tabla de cuotas en HTML.
     */
    private function renderizarTablaCuotas(array $metadata): string
    {
        $cuotas = data_get($metadata, 'Cuotas');
        
        if (!$cuotas || !is_array($cuotas) || empty($cuotas)) {
            return '<p style="color: #666; font-style: italic;">No hay cuotas registradas.</p>';
        }
        
        $html = '
        <table style="width: 100%; border-collapse: collapse; margin: 16px 0; font-family: Arial, sans-serif;">
            <thead>
                <tr style="background-color: #1e3a5f; color: white;">
                    <th style="padding: 12px 8px; text-align: left; border: 1px solid #ddd;">Contrato</th>
                    <th style="padding: 12px 8px; text-align: center; border: 1px solid #ddd;">Nro Cuota</th>
                    <th style="padding: 12px 8px; text-align: right; border: 1px solid #ddd;">Monto</th>
                    <th style="padding: 12px 8px; text-align: center; border: 1px solid #ddd;">Vencimiento</th>
                    <th style="padding: 12px 8px; text-align: center; border: 1px solid #ddd;">Estado</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($cuotas as $index => $cuota) {
            $bgColor = $index % 2 === 0 ? '#f9f9f9' : '#ffffff';
            $contrato = $cuota['Contrato'] ?? '-';
            $numeroCuota = $cuota['Cuota'] ?? '-';
            $monto = isset($cuota['Monto']) ? '$' . number_format((int) $cuota['Monto'], 0, ',', '.') : '-';
            $vencimiento = isset($cuota['Vencimiento']) ? $this->formatearFecha($cuota['Vencimiento']) : '-';
            $estado = $cuota['Estado'] ?? '-';
            
            // Color del estado
            $estadoColor = match(strtoupper($estado)) {
                'MOROSO' => '#dc2626',
                'VIGENTE' => '#16a34a',
                'PAGADO' => '#2563eb',
                default => '#666666',
            };
            
            $html .= "
                <tr style=\"background-color: {$bgColor};\">
                    <td style=\"padding: 10px 8px; border: 1px solid #ddd;\">{$contrato}</td>
                    <td style=\"padding: 10px 8px; text-align: center; border: 1px solid #ddd;\">{$numeroCuota}</td>
                    <td style=\"padding: 10px 8px; text-align: right; border: 1px solid #ddd; font-weight: bold;\">{$monto}</td>
                    <td style=\"padding: 10px 8px; text-align: center; border: 1px solid #ddd;\">{$vencimiento}</td>
                    <td style=\"padding: 10px 8px; text-align: center; border: 1px solid #ddd; color: {$estadoColor}; font-weight: bold;\">{$estado}</td>
                </tr>";
        }
        
        $html .= '
            </tbody>
        </table>';
        
        return $html;
    }
    
    /**
     * Renderiza tabla de cuotas pendientes (VIGENTES + MOROSAS) SIN columna de estado.
     * Ideal para recordatorios de pago (Día 18, Día -4, Día vencimiento, etc.)
     */
    private function renderizarTablaCuotasPendientes(array $metadata): string
    {
        $cuotas = data_get($metadata, 'Cuotas');
        
        if (!$cuotas || !is_array($cuotas) || empty($cuotas)) {
            return '<p style="color: #666; font-style: italic;">No hay cuotas pendientes.</p>';
        }
        
        // Filtrar solo cuotas VIGENTES y MOROSAS
        $cuotasPendientes = array_filter($cuotas, function ($cuota) {
            $estado = strtoupper($cuota['Estado'] ?? '');
            return in_array($estado, ['VIGENTE', 'MOROSO', 'PENDIENTE']);
        });
        
        if (empty($cuotasPendientes)) {
            return '<p style="color: #16a34a; font-style: italic;">✓ Todas las cuotas están al día.</p>';
        }
        
        // Ordenar por fecha de vencimiento
        usort($cuotasPendientes, function ($a, $b) {
            return strtotime($a['Vencimiento'] ?? '9999-12-31') - strtotime($b['Vencimiento'] ?? '9999-12-31');
        });
        
        $html = '
        <table style="width: 100%; border-collapse: collapse; margin: 16px 0; font-family: Arial, sans-serif;">
            <thead>
                <tr style="background-color: #1e3a5f; color: white;">
                    <th style="padding: 12px 8px; text-align: left; border: 1px solid #ddd;">Contrato</th>
                    <th style="padding: 12px 8px; text-align: center; border: 1px solid #ddd;">Nro Cuota</th>
                    <th style="padding: 12px 8px; text-align: right; border: 1px solid #ddd;">Monto</th>
                    <th style="padding: 12px 8px; text-align: center; border: 1px solid #ddd;">Vencimiento</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach (array_values($cuotasPendientes) as $index => $cuota) {
            $bgColor = $index % 2 === 0 ? '#f9f9f9' : '#ffffff';
            $contrato = $cuota['Contrato'] ?? '-';
            $numeroCuota = $cuota['Cuota'] ?? '-';
            $monto = isset($cuota['Monto']) ? '$' . number_format((int) $cuota['Monto'], 0, ',', '.') : '-';
            $vencimiento = isset($cuota['Vencimiento']) ? $this->formatearFecha($cuota['Vencimiento']) : '-';
            
            $html .= "
                <tr style=\"background-color: {$bgColor};\">
                    <td style=\"padding: 10px 8px; border: 1px solid #ddd;\">{$contrato}</td>
                    <td style=\"padding: 10px 8px; text-align: center; border: 1px solid #ddd;\">{$numeroCuota}</td>
                    <td style=\"padding: 10px 8px; text-align: right; border: 1px solid #ddd; font-weight: bold;\">{$monto}</td>
                    <td style=\"padding: 10px 8px; text-align: center; border: 1px solid #ddd;\">{$vencimiento}</td>
                </tr>";
        }
        
        $html .= '
            </tbody>
        </table>';
        
        return $html;
    }
    
    /**
     * Renderiza solo la próxima cuota a vencer.
     * Ideal para avisos de vencimiento inminente.
     */
    private function renderizarProximaCuota(array $metadata): string
    {
        $cuotas = data_get($metadata, 'Cuotas');
        
        if (!$cuotas || !is_array($cuotas) || empty($cuotas)) {
            return '<p style="color: #666; font-style: italic;">No hay cuotas registradas.</p>';
        }
        
        // Filtrar solo cuotas VIGENTES (próximas a vencer, no morosas)
        $cuotasVigentes = array_filter($cuotas, function ($cuota) {
            $estado = strtoupper($cuota['Estado'] ?? '');
            return $estado === 'VIGENTE';
        });
        
        if (empty($cuotasVigentes)) {
            // Si no hay vigentes, buscar morosas
            $cuotasVigentes = array_filter($cuotas, function ($cuota) {
                $estado = strtoupper($cuota['Estado'] ?? '');
                return in_array($estado, ['MOROSO', 'PENDIENTE']);
            });
        }
        
        if (empty($cuotasVigentes)) {
            return '<p style="color: #16a34a; font-style: italic;">✓ No hay cuotas pendientes.</p>';
        }
        
        // Ordenar por fecha de vencimiento y tomar la primera
        usort($cuotasVigentes, function ($a, $b) {
            return strtotime($a['Vencimiento'] ?? '9999-12-31') - strtotime($b['Vencimiento'] ?? '9999-12-31');
        });
        
        $cuota = reset($cuotasVigentes);
        
        $contrato = $cuota['Contrato'] ?? '-';
        $numeroCuota = $cuota['Cuota'] ?? '-';
        $monto = isset($cuota['Monto']) ? '$' . number_format((int) $cuota['Monto'], 0, ',', '.') : '-';
        $vencimiento = isset($cuota['Vencimiento']) ? $this->formatearFecha($cuota['Vencimiento']) : '-';
        
        $html = '
        <table style="width: 100%; border-collapse: collapse; margin: 16px 0; font-family: Arial, sans-serif;">
            <thead>
                <tr style="background-color: #1e3a5f; color: white;">
                    <th style="padding: 12px 8px; text-align: left; border: 1px solid #ddd;">Contrato</th>
                    <th style="padding: 12px 8px; text-align: center; border: 1px solid #ddd;">Nro Cuota</th>
                    <th style="padding: 12px 8px; text-align: right; border: 1px solid #ddd;">Monto</th>
                    <th style="padding: 12px 8px; text-align: center; border: 1px solid #ddd;">Vencimiento</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background-color: #f9f9f9;">
                    <td style="padding: 10px 8px; border: 1px solid #ddd;">' . $contrato . '</td>
                    <td style="padding: 10px 8px; text-align: center; border: 1px solid #ddd;">' . $numeroCuota . '</td>
                    <td style="padding: 10px 8px; text-align: right; border: 1px solid #ddd; font-weight: bold;">' . $monto . '</td>
                    <td style="padding: 10px 8px; text-align: center; border: 1px solid #ddd;">' . $vencimiento . '</td>
                </tr>
            </tbody>
        </table>';
        
        return $html;
    }

    /**
     * Trunca un SMS a 160 caracteres si es necesario.
     * Los SMS estándar tienen límite de 160 caracteres GSM-7.
     *
     * @param  string  $contenido  Contenido del SMS
     * @param  int  $prospectoId  ID del prospecto (para logging)
     * @return string Contenido truncado si excede 160 caracteres
     */
    private function truncarSmsSimesNecesario(string $contenido, int $prospectoId): string
    {
        $maxLength = 160;
        $length = mb_strlen($contenido);

        if ($length <= $maxLength) {
            return $contenido;
        }

        Log::warning('EnvioService: SMS truncado por exceder 160 caracteres', [
            'prospecto_id' => $prospectoId,
            'longitud_original' => $length,
            'longitud_truncada' => $maxLength - 3, // -3 por "..."
            'contenido_original' => mb_substr($contenido, 0, 50).'...',
        ]);

        // Truncar y agregar "..." al final
        return mb_substr($contenido, 0, $maxLength - 3).'...';
    }

    /**
     * Updates the prospect's progress tracking after a successful send.
     *
     * This method is called after a successful email or SMS send to record
     * which stage the prospect has completed. This enables:
     * - Filtering in EnviarEtapaJob to only send to eligible prospects
     * - Catch-up jobs to advance behind-prospects through missed stages
     *
     * @param  ProspectoEnFlujo  $prospectoEnFlujo  The prospect in flow
     * @param  int|null  $etapaEjecucionId  The execution stage ID
     */
    private function actualizarProgresoProspecto(
        ProspectoEnFlujo $prospectoEnFlujo,
        ?int $etapaEjecucionId
    ): void {
        if (! $etapaEjecucionId) {
            return;
        }

        $etapaEjecucion = FlujoEjecucionEtapa::find($etapaEjecucionId);

        if (! $etapaEjecucion || ! $etapaEjecucion->node_id) {
            Log::debug('EnvioService: No se pudo actualizar progreso, etapa o node_id no encontrado', [
                'prospecto_en_flujo_id' => $prospectoEnFlujo->id,
                'etapa_ejecucion_id' => $etapaEjecucionId,
            ]);

            return;
        }

        // Update the prospect's progress to this stage
        $prospectoEnFlujo->update([
            'ultima_etapa_node_id' => $etapaEjecucion->node_id,
        ]);

        Log::debug('EnvioService: Progreso de prospecto actualizado', [
            'prospecto_id' => $prospectoEnFlujo->prospecto_id,
            'flujo_id' => $prospectoEnFlujo->flujo_id,
            'ultima_etapa_node_id' => $etapaEjecucion->node_id,
        ]);
    }
}
