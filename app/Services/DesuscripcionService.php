<?php

namespace App\Services;

use App\Models\Desuscripcion;
use App\Models\Envio;
use App\Models\Prospecto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Servicio para gestión de desuscripciones.
 * 
 * Maneja todo el flujo de opt-out:
 * - Generación de tokens seguros
 * - Procesamiento de desuscripciones
 * - Verificación de estado de suscripción
 * - Generación de links de desuscripción para emails
 */
class DesuscripcionService
{
    /**
     * Genera un token seguro para desuscripción.
     * 
     * El token contiene información encriptada del prospecto y envío
     * para poder procesar la desuscripción sin autenticación.
     */
    public function generarToken(int $prospectoId, ?int $envioId = null, ?int $flujoId = null): string
    {
        $data = [
            'p' => $prospectoId,
            'e' => $envioId,
            'f' => $flujoId,
            't' => now()->timestamp,
            'r' => Str::random(8),
        ];

        // Codificar y firmar
        $payload = base64_encode(json_encode($data));
        $signature = hash_hmac('sha256', $payload, config('app.key'));
        
        return $payload . '.' . substr($signature, 0, 16);
    }

    /**
     * Decodifica y valida un token de desuscripción.
     * 
     * @return array|null Datos del token o null si es inválido
     */
    public function decodificarToken(string $token): ?array
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;
        
        // Verificar firma
        $expectedSignature = substr(hash_hmac('sha256', $payload, config('app.key')), 0, 16);
        
        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('DesuscripcionService: Token con firma inválida', [
                'token_preview' => substr($token, 0, 20) . '...',
            ]);
            return null;
        }

        $data = json_decode(base64_decode($payload), true);
        
        if (!$data || !isset($data['p'])) {
            return null;
        }

        // Verificar que el token no sea muy viejo (90 días máximo)
        $tokenAge = now()->timestamp - ($data['t'] ?? 0);
        $maxAge = 90 * 24 * 60 * 60; // 90 días en segundos
        
        if ($tokenAge > $maxAge) {
            Log::info('DesuscripcionService: Token expirado', [
                'prospecto_id' => $data['p'],
                'token_age_days' => round($tokenAge / 86400),
            ]);
            return null;
        }

        return [
            'prospecto_id' => $data['p'],
            'envio_id' => $data['e'] ?? null,
            'flujo_id' => $data['f'] ?? null,
            'timestamp' => $data['t'],
        ];
    }

    /**
     * Genera la URL completa de desuscripción para un email.
     */
    public function generarUrlDesuscripcion(int $prospectoId, ?int $envioId = null, ?int $flujoId = null): string
    {
        $token = $this->generarToken($prospectoId, $envioId, $flujoId);
        $baseUrl = config('app.url', 'http://localhost');
        
        return "{$baseUrl}/desuscribir/{$token}";
    }

    /**
     * Procesa una solicitud de desuscripción.
     * 
     * @param string $token Token de desuscripción
     * @param string $canal Canal a desuscribir (email, sms, todos)
     * @param string|null $motivo Motivo de desuscripción
     * @param string|null $ipAddress IP del solicitante
     * @param string|null $userAgent User agent del navegador
     * @return array{success: bool, message: string, prospecto?: Prospecto}
     */
    public function procesarDesuscripcion(
        string $token,
        string $canal = Desuscripcion::CANAL_TODOS,
        ?string $motivo = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $tokenData = $this->decodificarToken($token);
        
        if (!$tokenData) {
            return [
                'success' => false,
                'message' => 'El enlace de desuscripción es inválido o ha expirado.',
            ];
        }

        $prospecto = Prospecto::find($tokenData['prospecto_id']);
        
        if (!$prospecto) {
            return [
                'success' => false,
                'message' => 'No se encontró el registro asociado.',
            ];
        }

        // Verificar si ya está desuscrito
        if ($prospecto->estado === 'desuscrito') {
            return [
                'success' => true,
                'message' => 'Ya te habías desuscrito anteriormente.',
                'prospecto' => $prospecto,
            ];
        }

        try {
            DB::transaction(function () use ($prospecto, $canal, $motivo, $token, $ipAddress, $userAgent, $tokenData) {
                // 1. Actualizar estado del prospecto
                $prospecto->update([
                    'estado' => 'desuscrito',
                    'fecha_desuscripcion' => now(),
                    'preferencias_comunicacion' => [
                        'email' => $canal !== Desuscripcion::CANAL_EMAIL && $canal !== Desuscripcion::CANAL_TODOS,
                        'sms' => $canal !== Desuscripcion::CANAL_SMS && $canal !== Desuscripcion::CANAL_TODOS,
                        'desuscrito_at' => now()->toIso8601String(),
                        'canal_desuscripcion' => $canal,
                    ],
                ]);

                // 2. Cancelar participación en flujos activos
                $prospecto->prospectosEnFlujo()
                    ->where('completado', false)
                    ->where('cancelado', false)
                    ->update([
                        'cancelado' => true,
                        'fecha_cancelacion' => now(),
                        'motivo_cancelacion' => 'desuscripcion',
                    ]);

                // 3. Registrar en tabla de auditoría
                Desuscripcion::create([
                    'prospecto_id' => $prospecto->id,
                    'canal' => $canal,
                    'motivo' => $motivo,
                    'token' => substr($token, 0, 64),
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
                    'envio_id' => $tokenData['envio_id'],
                    'flujo_id' => $tokenData['flujo_id'],
                ]);
            });

            Log::info('DesuscripcionService: Prospecto desuscrito exitosamente', [
                'prospecto_id' => $prospecto->id,
                'canal' => $canal,
                'motivo' => $motivo,
                'envio_id' => $tokenData['envio_id'],
            ]);

            return [
                'success' => true,
                'message' => 'Te has desuscrito exitosamente. No recibirás más comunicaciones.',
                'prospecto' => $prospecto->fresh(),
            ];

        } catch (\Exception $e) {
            Log::error('DesuscripcionService: Error procesando desuscripción', [
                'prospecto_id' => $prospecto->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Ocurrió un error procesando tu solicitud. Por favor intenta de nuevo.',
            ];
        }
    }

    /**
     * Verifica si un prospecto puede recibir comunicaciones por un canal específico.
     */
    public function puedeRecibir(Prospecto $prospecto, string $canal): bool
    {
        // Si está desuscrito, no puede recibir nada
        if ($prospecto->estado === 'desuscrito') {
            return false;
        }

        // Verificar preferencias específicas
        $preferencias = $prospecto->preferencias_comunicacion;
        
        if (!$preferencias) {
            return true; // Sin preferencias = todo permitido
        }

        return $preferencias[$canal] ?? true;
    }

    /**
     * Genera el HTML del footer de desuscripción para emails.
     */
    public function generarFooterDesuscripcion(int $prospectoId, ?int $envioId = null, ?int $flujoId = null): string
    {
        $url = $this->generarUrlDesuscripcion($prospectoId, $envioId, $flujoId);
        
        return <<<HTML
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; text-align: center; font-size: 12px; color: #666666;">
            <p style="margin: 0 0 10px 0;">
                Si no deseas recibir más comunicaciones de nuestra parte, puedes
                <a href="{$url}" style="color: #666666; text-decoration: underline;">darte de baja aquí</a>.
            </p>
            <p style="margin: 0; color: #999999;">
                Grupo Segal - Todos los derechos reservados
            </p>
        </div>
        HTML;
    }

    /**
     * Obtiene estadísticas de desuscripciones.
     */
    public function obtenerEstadisticas(int $dias = 30): array
    {
        $desde = now()->subDays($dias);

        $total = Desuscripcion::where('created_at', '>=', $desde)->count();
        
        $porCanal = Desuscripcion::where('created_at', '>=', $desde)
            ->select('canal', DB::raw('COUNT(*) as total'))
            ->groupBy('canal')
            ->pluck('total', 'canal')
            ->toArray();

        $porMotivo = Desuscripcion::where('created_at', '>=', $desde)
            ->whereNotNull('motivo')
            ->select('motivo', DB::raw('COUNT(*) as total'))
            ->groupBy('motivo')
            ->pluck('total', 'motivo')
            ->toArray();

        $porDia = Desuscripcion::where('created_at', '>=', $desde)
            ->select(DB::raw('DATE(created_at) as fecha'), DB::raw('COUNT(*) as total'))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->pluck('total', 'fecha')
            ->toArray();

        return [
            'total' => $total,
            'por_canal' => $porCanal,
            'por_motivo' => $porMotivo,
            'por_dia' => $porDia,
            'periodo_dias' => $dias,
        ];
    }
}
