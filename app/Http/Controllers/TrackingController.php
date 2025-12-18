<?php

namespace App\Http\Controllers;

use App\Models\EmailApertura;
use App\Models\EmailClick;
use App\Models\Envio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TrackingController extends Controller
{
    /**
     * Pixel transparente 1x1 en formato GIF
     * Es el formato más ligero y compatible con todos los clientes de email
     */
    private const TRANSPARENT_PIXEL = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    /**
     * Registra la apertura de un email y devuelve un pixel transparente
     * 
     * GET /track/open/{token}
     */
    public function open(Request $request, string $token)
    {
        try {
            // Buscar el envío por token
            $envio = Envio::where('tracking_token', $token)->first();

            if (!$envio) {
                Log::warning('TrackingController: Token de tracking no encontrado', [
                    'token' => $token,
                ]);
                return $this->pixelResponse();
            }

            // Obtener información del request
            $ipAddress = $request->ip();
            $userAgent = $request->userAgent();
            $dispositivo = EmailApertura::detectarDispositivo($userAgent);
            $clienteEmail = EmailApertura::detectarClienteEmail($userAgent);

            // Registrar la apertura
            EmailApertura::create([
                'envio_id' => $envio->id,
                'prospecto_id' => $envio->prospecto_id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'dispositivo' => $dispositivo,
                'cliente_email' => $clienteEmail,
                'fecha_apertura' => now(),
            ]);

            // Incrementar contador de aperturas
            $envio->increment('total_aperturas');

            // Si es la primera apertura, actualizar estado y fecha
            if ($envio->estado === 'enviado' && !$envio->fecha_abierto) {
                $envio->update([
                    'estado' => 'abierto',
                    'fecha_abierto' => now(),
                ]);
            }

            Log::info('TrackingController: Apertura registrada', [
                'envio_id' => $envio->id,
                'prospecto_id' => $envio->prospecto_id,
                'dispositivo' => $dispositivo,
                'cliente_email' => $clienteEmail,
                'total_aperturas' => $envio->total_aperturas + 1,
            ]);

        } catch (\Exception $e) {
            Log::error('TrackingController: Error al registrar apertura', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);
        }

        // Siempre devolver el pixel, incluso si hay error
        return $this->pixelResponse();
    }

    /**
     * Registra el click en un enlace y redirecciona a la URL original
     * 
     * GET /track/click/{token}
     * 
     * El token contiene: envio_id + url_id encriptados
     * Query param 'url' contiene la URL original codificada en base64
     */
    public function click(Request $request, string $token): RedirectResponse
    {
        // URL de fallback si algo falla
        $fallbackUrl = config('app.url', 'https://gruposegal.cl');

        try {
            // Decodificar el token (formato: envioId_urlId)
            $tokenData = $this->decodeClickToken($token);
            
            if (!$tokenData) {
                Log::warning('TrackingController: Token de click inválido', [
                    'token' => $token,
                ]);
                return redirect($fallbackUrl);
            }

            $envioId = $tokenData['envio_id'];
            $urlId = $tokenData['url_id'];

            // Obtener URL original del query param
            $urlEncoded = $request->query('url');
            if (!$urlEncoded) {
                Log::warning('TrackingController: URL no proporcionada en click', [
                    'token' => $token,
                ]);
                return redirect($fallbackUrl);
            }

            $urlOriginal = base64_decode($urlEncoded);
            if (!$urlOriginal || !filter_var($urlOriginal, FILTER_VALIDATE_URL)) {
                Log::warning('TrackingController: URL inválida en click', [
                    'token' => $token,
                    'url_encoded' => $urlEncoded,
                ]);
                return redirect($fallbackUrl);
            }

            // Buscar el envío
            $envio = Envio::find($envioId);

            if (!$envio) {
                Log::warning('TrackingController: Envío no encontrado para click', [
                    'envio_id' => $envioId,
                ]);
                return redirect($urlOriginal);
            }

            // Obtener información del request
            $ipAddress = $request->ip();
            $userAgent = $request->userAgent();
            $dispositivo = EmailClick::detectarDispositivo($userAgent);
            $navegador = EmailClick::detectarNavegador($userAgent);

            // Registrar el click
            EmailClick::create([
                'envio_id' => $envio->id,
                'prospecto_id' => $envio->prospecto_id,
                'url_original' => $urlOriginal,
                'url_id' => $urlId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'dispositivo' => $dispositivo,
                'navegador' => $navegador,
                'fecha_click' => now(),
            ]);

            // Incrementar contador de clicks
            $envio->increment('total_clicks');

            // Si es el primer click, actualizar estado y fecha
            if (in_array($envio->estado, ['enviado', 'abierto']) && !$envio->fecha_clickeado) {
                $envio->update([
                    'estado' => 'clickeado',
                    'fecha_clickeado' => now(),
                ]);
            }

            Log::info('TrackingController: Click registrado', [
                'envio_id' => $envio->id,
                'prospecto_id' => $envio->prospecto_id,
                'url_original' => $urlOriginal,
                'dispositivo' => $dispositivo,
                'navegador' => $navegador,
                'total_clicks' => $envio->total_clicks + 1,
            ]);

            // Redireccionar a la URL original
            return redirect($urlOriginal);

        } catch (\Exception $e) {
            Log::error('TrackingController: Error al registrar click', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            // Intentar extraer la URL del query param para redireccionar
            $urlEncoded = $request->query('url');
            if ($urlEncoded) {
                $urlOriginal = base64_decode($urlEncoded);
                if ($urlOriginal && filter_var($urlOriginal, FILTER_VALIDATE_URL)) {
                    return redirect($urlOriginal);
                }
            }

            return redirect($fallbackUrl);
        }
    }

    /**
     * Decodifica el token de click
     * Formato: base64(envioId_urlId)
     */
    private function decodeClickToken(string $token): ?array
    {
        try {
            $decoded = base64_decode($token);
            if (!$decoded) {
                return null;
            }

            $parts = explode('_', $decoded);
            if (count($parts) < 2) {
                return null;
            }

            return [
                'envio_id' => (int) $parts[0],
                'url_id' => $parts[1],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Genera una URL de tracking para un enlace
     * 
     * @param int $envioId ID del envío
     * @param string $urlOriginal URL original del enlace
     * @param string|null $urlId ID opcional del enlace
     * @return string URL de tracking
     */
    public static function generarUrlTracking(int $envioId, string $urlOriginal, ?string $urlId = null): string
    {
        $urlId = $urlId ?? substr(md5($urlOriginal), 0, 8);
        $token = base64_encode("{$envioId}_{$urlId}");
        $urlEncoded = base64_encode($urlOriginal);
        
        $baseUrl = config('app.url', 'http://localhost');
        
        return "{$baseUrl}/track/click/{$token}?url={$urlEncoded}";
    }

    /**
     * Devuelve una respuesta con el pixel transparente
     */
    private function pixelResponse(): Response
    {
        return response(self::TRANSPARENT_PIXEL, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => strlen(self::TRANSPARENT_PIXEL),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]);
    }

    /**
     * Obtiene estadísticas de aperturas para un envío específico
     * 
     * GET /api/envios/{envioId}/aperturas
     */
    public function estadisticasEnvio(int $envioId)
    {
        $envio = Envio::with('prospecto')->findOrFail($envioId);
        
        $aperturas = EmailApertura::where('envio_id', $envioId)
            ->orderBy('fecha_apertura', 'desc')
            ->get();

        return response()->json([
            'envio_id' => $envioId,
            'prospecto' => [
                'id' => $envio->prospecto->id,
                'nombre' => $envio->prospecto->nombre,
                'email' => $envio->prospecto->email,
            ],
            'total_aperturas' => $envio->total_aperturas,
            'primera_apertura' => $envio->fecha_abierto,
            'aperturas' => $aperturas->map(function ($apertura) {
                return [
                    'fecha' => $apertura->fecha_apertura,
                    'dispositivo' => $apertura->dispositivo,
                    'cliente_email' => $apertura->cliente_email,
                    'ip_address' => $apertura->ip_address,
                ];
            }),
        ]);
    }

    /**
     * Obtiene estadísticas generales de aperturas para un flujo
     * 
     * GET /api/flujos/{flujoId}/estadisticas-aperturas
     */
    public function estadisticasFlujo(int $flujoId)
    {
        $envios = Envio::where('flujo_id', $flujoId)
            ->where('canal', 'email')
            ->with('prospecto')
            ->get();

        $totalEnviados = $envios->count();
        $totalAbiertos = $envios->where('estado', 'abierto')->count();
        $totalAbiertos += $envios->where('estado', 'clickeado')->count();
        
        $tasaApertura = $totalEnviados > 0 
            ? round(($totalAbiertos / $totalEnviados) * 100, 2) 
            : 0;

        // Agrupar por dispositivo
        $porDispositivo = EmailApertura::whereIn('envio_id', $envios->pluck('id'))
            ->selectRaw('dispositivo, COUNT(*) as total')
            ->groupBy('dispositivo')
            ->pluck('total', 'dispositivo')
            ->toArray();

        // Agrupar por cliente de email
        $porCliente = EmailApertura::whereIn('envio_id', $envios->pluck('id'))
            ->selectRaw('cliente_email, COUNT(*) as total')
            ->groupBy('cliente_email')
            ->pluck('total', 'cliente_email')
            ->toArray();

        // Lista de prospectos que abrieron
        $prospectosQueAbrieron = $envios
            ->filter(fn($e) => in_array($e->estado, ['abierto', 'clickeado']))
            ->map(function ($envio) {
                return [
                    'prospecto_id' => $envio->prospecto_id,
                    'nombre' => $envio->prospecto->nombre,
                    'email' => $envio->prospecto->email,
                    'fecha_apertura' => $envio->fecha_abierto,
                    'total_aperturas' => $envio->total_aperturas,
                ];
            })
            ->values();

        return response()->json([
            'flujo_id' => $flujoId,
            'resumen' => [
                'total_enviados' => $totalEnviados,
                'total_abiertos' => $totalAbiertos,
                'tasa_apertura' => $tasaApertura,
            ],
            'por_dispositivo' => $porDispositivo,
            'por_cliente_email' => $porCliente,
            'prospectos_que_abrieron' => $prospectosQueAbrieron,
        ]);
    }

    /**
     * Obtiene estadísticas de clicks para un envío específico
     * 
     * GET /api/envios/{envioId}/clicks
     */
    public function estadisticasClicksEnvio(int $envioId)
    {
        $envio = Envio::with('prospecto')->findOrFail($envioId);
        
        $clicks = EmailClick::where('envio_id', $envioId)
            ->orderBy('fecha_click', 'desc')
            ->get();

        // Agrupar por URL
        $clicksPorUrl = $clicks->groupBy('url_original')->map(function ($grupo) {
            return [
                'total_clicks' => $grupo->count(),
                'primer_click' => $grupo->min('fecha_click'),
                'ultimo_click' => $grupo->max('fecha_click'),
            ];
        });

        return response()->json([
            'envio_id' => $envioId,
            'prospecto' => [
                'id' => $envio->prospecto->id,
                'nombre' => $envio->prospecto->nombre,
                'email' => $envio->prospecto->email,
            ],
            'total_clicks' => $envio->total_clicks,
            'primer_click' => $envio->fecha_clickeado,
            'clicks_por_url' => $clicksPorUrl,
            'clicks' => $clicks->map(function ($click) {
                return [
                    'fecha' => $click->fecha_click,
                    'url' => $click->url_original,
                    'dispositivo' => $click->dispositivo,
                    'navegador' => $click->navegador,
                    'ip_address' => $click->ip_address,
                ];
            }),
        ]);
    }

    /**
     * Obtiene estadísticas generales de clicks para un flujo
     * 
     * GET /api/flujos/{flujoId}/estadisticas-clicks
     */
    public function estadisticasClicksFlujo(int $flujoId)
    {
        $envios = Envio::where('flujo_id', $flujoId)
            ->where('canal', 'email')
            ->with('prospecto')
            ->get();

        $totalEnviados = $envios->count();
        $totalConClicks = $envios->where('estado', 'clickeado')->count();
        
        $tasaClicks = $totalEnviados > 0 
            ? round(($totalConClicks / $totalEnviados) * 100, 2) 
            : 0;

        // Total de clicks (no únicos)
        $totalClicks = $envios->sum('total_clicks');

        // Agrupar por dispositivo
        $porDispositivo = EmailClick::whereIn('envio_id', $envios->pluck('id'))
            ->selectRaw('dispositivo, COUNT(*) as total')
            ->groupBy('dispositivo')
            ->pluck('total', 'dispositivo')
            ->toArray();

        // Agrupar por navegador
        $porNavegador = EmailClick::whereIn('envio_id', $envios->pluck('id'))
            ->selectRaw('navegador, COUNT(*) as total')
            ->groupBy('navegador')
            ->pluck('total', 'navegador')
            ->toArray();

        // Top URLs más clickeadas
        $topUrls = EmailClick::whereIn('envio_id', $envios->pluck('id'))
            ->selectRaw('url_original, COUNT(*) as total')
            ->groupBy('url_original')
            ->orderByDesc('total')
            ->limit(10)
            ->pluck('total', 'url_original')
            ->toArray();

        // Lista de prospectos que clickearon
        $prospectosQueClickearon = $envios
            ->filter(fn($e) => $e->estado === 'clickeado')
            ->map(function ($envio) {
                return [
                    'prospecto_id' => $envio->prospecto_id,
                    'nombre' => $envio->prospecto->nombre,
                    'email' => $envio->prospecto->email,
                    'fecha_primer_click' => $envio->fecha_clickeado,
                    'total_clicks' => $envio->total_clicks,
                ];
            })
            ->values();

        return response()->json([
            'flujo_id' => $flujoId,
            'resumen' => [
                'total_enviados' => $totalEnviados,
                'total_con_clicks' => $totalConClicks,
                'total_clicks' => $totalClicks,
                'tasa_clicks' => $tasaClicks,
            ],
            'por_dispositivo' => $porDispositivo,
            'por_navegador' => $porNavegador,
            'top_urls' => $topUrls,
            'prospectos_que_clickearon' => $prospectosQueClickearon,
        ]);
    }
}
