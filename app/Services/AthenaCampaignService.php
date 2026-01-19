<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AthenaCampaignService
{
    private string $baseUrlEmail;

    private string $baseUrlSms;

    private string $apiKey;

    private ?string $smsApiToken;

    public function __construct()
    {
        $this->baseUrlEmail = config('services.athenacampaign.base_url', 'https://apimail.athenacampaign.com');
        $this->baseUrlSms = 'https://api.athenacampaign.com/v1';
        $this->apiKey = config('services.athenacampaign.api_key') ?? '';
        $this->smsApiToken = config('services.sms.api_token');
    }

    /**
     * Obtiene estadísticas de un mensaje enviado
     *
     * GET /getstatisticsbyid?messageID={messageID}
     *
     * Respuesta esperada:
     * {
     *   "error": false,
     *   "codigo": 200,
     *   "mensaje": {
     *     "messageID": 3320,
     *     "Recipients": 15,
     *     "Views": 1,        // Emails abiertos
     *     "Clicks": 0,       // Links clickeados
     *     "Bounces": 0,      // Rechazados
     *     "Unsubscribes": 0  // Bajas (si disponible)
     *   }
     * }
     * 
     * También soporta estadísticas mockeadas para testing:
     * Si existe cache key "mock_stats_{messageId}", usa esos datos en lugar de llamar a la API
     */
    public function getStatistics(int $messageId): array
    {
        try {
            Log::info('AthenaCampaign: Obteniendo estadísticas', [
                'message_id' => $messageId,
            ]);

            // Verificar si hay estadísticas mockeadas (para testing)
            $cacheKey = "mock_stats_{$messageId}";
            $mockedStats = cache()->get($cacheKey);

            if ($mockedStats !== null) {
                Log::info('AthenaCampaign: Usando estadísticas MOCKEADAS', [
                    'message_id' => $messageId,
                    'stats' => $mockedStats,
                ]);

                return [
                    'error' => false,
                    'codigo' => 200,
                    'mensaje' => $mockedStats,
                    '_mocked' => true,
                ];
            }

            // Llamar a la API real de AthenaCampaign
            $response = Http::timeout(30)->get(
                "{$this->baseUrlEmail}/getstatisticsbyid",
                [
                    'messageID' => $messageId,
                    'apikey' => $this->apiKey,
                ]
            );

            $data = $response->json();

            Log::debug('AthenaCampaign: Respuesta recibida', [
                'message_id' => $messageId,
                'status' => $response->status(),
                'data' => $data,
            ]);

            if (! $response->successful()) {
                throw new \Exception("API returned status {$response->status()}");
            }

            return $data;

        } catch (\Exception $e) {
            Log::error('AthenaCampaign: Error en getStatistics', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Envía email/SMS a través de AthenaCampaign
     *
     * @param  array  $config  Configuración del mensaje a enviar
     * @return array Respuesta con messageID
     */
    public function enviarMensaje(array $config): array
    {
        $tipo = $config['tipo'] ?? 'email';

        if ($tipo === 'sms') {
            return $this->enviarSms($config);
        } else {
            return $this->enviarEmail($config);
        }
    }

    /**
     * Envía SMS a través de Athena Campaign SMS API
     *
     * GET https://api.athenacampaign.com/v1/sendmessage
     *
     * @param  array  $config  Configuración del SMS
     * @return array Respuesta normalizada
     */
    private function enviarSms(array $config): array
    {
        try {
            $destinatarios = $config['destinatarios'] ?? [];
            $mensaje = $config['contenido'] ?? '';

            Log::info('AthenaCampaign SMS: Enviando SMS', [
                'cantidad' => count($destinatarios),
            ]);

            $resultados = [];
            $exitosos = 0;

            // Enviar SMS a cada destinatario (la API solo acepta 1 por request)
            foreach ($destinatarios as $destinatario) {
                $phone = $destinatario['telefono'] ?? '';

                if (empty($phone)) {
                    continue;
                }

                $response = Http::timeout(30)->get("{$this->baseUrlSms}/sendmessage", [
                    'TOKEN' => $this->smsApiToken,
                    'PHONE' => $phone,
                    'MESSAGE' => $mensaje,
                ]);

                if (! $response->successful()) {
                    Log::error('AthenaCampaign SMS: Error al enviar', [
                        'phone' => $phone,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    continue;
                }

                $data = $response->json();
                $resultados[] = $data;

                if ($data['STATUS'] === 'statusOK') {
                    $exitosos++;
                }
            }

            // Normalizar respuesta al formato esperado
            $primerResultado = $resultados[0] ?? null;

            return [
                'error' => false,
                'codigo' => 200,
                'mensaje' => [
                    'messageID' => $primerResultado['IDMSG'] ?? rand(10000, 99999),
                    'Recipients' => $exitosos,
                    'STATUS' => 'SMS_SENT',
                    'resultados' => $resultados,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('AthenaCampaign SMS: Error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Envía un SMS directo a un número (para alertas del sistema)
     *
     * @param string $telefono Número con código país (+56...)
     * @param string $mensaje Texto del SMS (máx 160 caracteres)
     * @return array{success: bool, message_id: string|null, error: string|null}
     */
    public function enviarSmsDirecto(string $telefono, string $mensaje): array
    {
        try {
            Log::info('AthenaCampaign SMS Directo: Enviando', [
                'telefono' => $telefono,
                'mensaje_length' => strlen($mensaje),
            ]);

            $response = Http::timeout(30)->get("{$this->baseUrlSms}/sendmessage", [
                'TOKEN' => $this->smsApiToken,
                'PHONE' => $telefono,
                'MESSAGE' => $mensaje,
            ]);

            if (!$response->successful()) {
                Log::error('AthenaCampaign SMS Directo: Error HTTP', [
                    'telefono' => $telefono,
                    'status' => $response->status(),
                ]);

                return [
                    'success' => false,
                    'message_id' => null,
                    'error' => "HTTP {$response->status()}",
                ];
            }

            $data = $response->json();

            if (($data['STATUS'] ?? '') === 'statusOK') {
                return [
                    'success' => true,
                    'message_id' => $data['IDMSG'] ?? null,
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'message_id' => null,
                'error' => $data['STATUS'] ?? 'Unknown error',
            ];

        } catch (\Exception $e) {
            Log::error('AthenaCampaign SMS Directo: Excepción', [
                'telefono' => $telefono,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Envía email a través de Athena Campaign Email API
     *
     * POST https://apimail.athenacampaign.com/sendmail
     *
     * @param  array  $config  Configuración del email
     * @return array Respuesta normalizada
     */
    private function enviarEmail(array $config): array
    {
        try {
            $destinatarios = $config['destinatarios'] ?? [];
            $asunto = $config['asunto'] ?? 'Sin asunto';
            $mensaje = $config['contenido'] ?? '';

            Log::info('AthenaCampaign Email: Enviando email', [
                'cantidad' => count($destinatarios),
                'asunto' => $asunto,
            ]);

            $resultados = [];
            $exitosos = 0;

            // Enviar email a cada destinatario
            foreach ($destinatarios as $destinatario) {
                $email = $destinatario['email'] ?? '';

                if (empty($email)) {
                    continue;
                }

                $response = Http::timeout(60)->post("{$this->baseUrlEmail}/sendmail", [
                    'TOKEN' => $this->apiKey,
                    'TO' => $email,
                    'SUBJECT' => $asunto,
                    'MESSAGE' => $mensaje,
                ]);

                if (! $response->successful()) {
                    Log::error('AthenaCampaign Email: Error al enviar', [
                        'email' => $email,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    continue;
                }

                $data = $response->json();
                $resultados[] = $data;

                if ($data['error'] === false) {
                    $exitosos++;
                }
            }

            // Normalizar respuesta al formato esperado
            return [
                'error' => false,
                'codigo' => 200,
                'mensaje' => [
                    'messageID' => rand(10000, 99999), // Email API no devuelve messageID
                    'Recipients' => $exitosos,
                    'STATUS' => 'EMAIL_SENT',
                    'resultados' => $resultados,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('AthenaCampaign Email: Error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Verifica si la API está disponible
     */
    public function healthCheck(): bool
    {
        try {
            $response = Http::timeout(10)->get($this->baseUrlEmail);

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('AthenaCampaign: Health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ========================================
    // MÉTODOS PARA EMAIL MARKETING API
    // ========================================

    /**
     * Envía un email a través de la API de Email Marketing
     *
     * POST /sendmail
     *
     * @param  string  $to  Dirección de correo destinatario
     * @param  string  $subject  Asunto del correo
     * @param  string  $message  Mensaje a enviar (texto plano o HTML)
     * @return array Respuesta de la API
     *
     * Respuesta esperada:
     * {
     *   "error": false,
     *   "codigo": 200,
     *   "mensaje": "Sent Message Success"
     * }
     */
    public function sendEmail(string $to, string $subject, string $message): array
    {
        try {
            Log::info('AthenaCampaign Email: Enviando email', [
                'to' => $to,
                'subject' => $subject,
            ]);

            $response = Http::timeout(60)->post(
                "{$this->baseUrl}/sendmail",
                [
                    'TO' => $to,
                    'SUBJECT' => $subject,
                    'MESSAGE' => $message,
                    'TOKEN' => $this->apiKey,
                ]
            );

            if (! $response->successful()) {
                throw new \Exception("API returned status {$response->status()}: ".$response->body());
            }

            $data = $response->json();

            Log::info('AthenaCampaign Email: Email enviado exitosamente', [
                'to' => $to,
                'response' => $data,
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('AthenaCampaign Email: Error en sendEmail', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtiene lista de envíos agrupados por Asunto del mes en curso
     *
     * GET /getlistsubjectbyid
     *
     * @return array Lista de mensajes enviados
     *
     * Respuesta esperada:
     * {
     *   "error": false,
     *   "codigo": 200,
     *   "mensaje": "[{
     *     \"CreationDate\": \"2023-11-08 19:07:19Z\",
     *     \"Notes\": \"\",
     *     \"Subject\": \"Smtp+ test369\",
     *     \"idList\": 8,
     *     \"idMessage\": 3330,
     *     \"ServiceType\": 1
     *   }]"
     * }
     */
    public function getListSubjectById(): array
    {
        try {
            Log::info('AthenaCampaign Email: Obteniendo lista de asuntos');

            $response = Http::timeout(30)->get(
                "{$this->baseUrl}/getlistsubjectbyid",
                [
                    'TOKEN' => $this->apiKey,
                ]
            );

            if (! $response->successful()) {
                throw new \Exception("API returned status {$response->status()}: ".$response->body());
            }

            $data = $response->json();

            // El mensaje viene como string JSON, necesitamos decodificarlo
            if (isset($data['mensaje']) && is_string($data['mensaje'])) {
                $data['mensaje'] = json_decode($data['mensaje'], true);
            }

            Log::debug('AthenaCampaign Email: Lista de asuntos obtenida', [
                'count' => count($data['mensaje'] ?? []),
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('AthenaCampaign Email: Error en getListSubjectById', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtiene estadísticas de envíos por idMessage (API de Email)
     *
     * GET /getstatiscticsbyid (nota: API tiene typo en el nombre)
     *
     * @param  int  $idMessage  ID del mensaje obtenido de getListSubjectById
     * @return array Estadísticas del mensaje
     *
     * Respuesta esperada:
     * {
     *   "error": false,
     *   "codigo": 200,
     *   "mensaje": {
     *     "messageID": 3320,
     *     "Recipients": 15,
     *     "Views": 1,
     *     "Clicks": 0,
     *     "Bounces": 0
     *   }
     * }
     */
    public function getStatisticsByIdEmail(int $idMessage): array
    {
        try {
            Log::info('AthenaCampaign Email: Obteniendo estadísticas de email', [
                'id_message' => $idMessage,
            ]);

            $response = Http::timeout(30)->get(
                "{$this->baseUrl}/getstatiscticsbyid", // Nota: API tiene typo
                [
                    'IDMESSAGE' => $idMessage,
                    'TOKEN' => $this->apiKey,
                ]
            );

            if (! $response->successful()) {
                throw new \Exception("API returned status {$response->status()}: ".$response->body());
            }

            $data = $response->json();

            Log::debug('AthenaCampaign Email: Estadísticas obtenidas', [
                'id_message' => $idMessage,
                'data' => $data,
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('AthenaCampaign Email: Error en getStatisticsByIdEmail', [
                'id_message' => $idMessage,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtiene el contador de mensajes enviados y cuota de mensajes
     *
     * GET /countsentmessages
     *
     * @return array Contador y cuota
     *
     * Respuesta esperada:
     * {
     *   "error": false,
     *   "codigo": 200,
     *   "mensaje": {
     *     "sent_messages": 1,
     *     "quote": 10000
     *   }
     * }
     */
    public function countSentMessages(): array
    {
        try {
            Log::info('AthenaCampaign Email: Obteniendo contador de mensajes');

            $response = Http::timeout(30)->get(
                "{$this->baseUrl}/countsentmessages",
                [
                    'TOKEN' => $this->apiKey,
                ]
            );

            if (! $response->successful()) {
                throw new \Exception("API returned status {$response->status()}: ".$response->body());
            }

            $data = $response->json();

            Log::debug('AthenaCampaign Email: Contador obtenido', [
                'sent' => $data['mensaje']['sent_messages'] ?? null,
                'quote' => $data['mensaje']['quote'] ?? null,
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('AthenaCampaign Email: Error en countSentMessages', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Helper: Verifica si quedan mensajes disponibles en la cuota
     *
     * @return bool True si hay mensajes disponibles
     */
    public function hasAvailableQuota(): bool
    {
        try {
            $counter = $this->countSentMessages();
            $sent = $counter['mensaje']['sent_messages'] ?? 0;
            $quote = $counter['mensaje']['quote'] ?? 0;

            return $sent < $quote;
        } catch (\Exception $e) {
            Log::warning('AthenaCampaign Email: No se pudo verificar cuota', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Helper: Obtiene el porcentaje de cuota utilizada
     *
     * @return float Porcentaje (0-100)
     */
    public function getQuotaUsagePercentage(): float
    {
        try {
            $counter = $this->countSentMessages();
            $sent = $counter['mensaje']['sent_messages'] ?? 0;
            $quote = $counter['mensaje']['quote'] ?? 1; // Evitar división por 0

            return ($sent / $quote) * 100;
        } catch (\Exception $e) {
            Log::warning('AthenaCampaign Email: No se pudo calcular porcentaje de cuota', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
