<?php

namespace App\Http\Controllers;

use App\Models\Desuscripcion;
use App\Services\DesuscripcionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller para gestión de desuscripciones.
 * 
 * Rutas públicas (sin autenticación):
 * - GET /desuscribir/{token} - Muestra página de confirmación
 * - POST /desuscribir/{token} - Procesa la desuscripción
 * 
 * Rutas protegidas (con autenticación):
 * - GET /api/desuscripciones/estadisticas - Estadísticas de desuscripciones
 */
class DesuscripcionController extends Controller
{
    public function __construct(
        private DesuscripcionService $desuscripcionService
    ) {}

    /**
     * Muestra la página de confirmación de desuscripción.
     * 
     * GET /desuscribir/{token}
     * 
     * Esta es una página pública accesible sin autenticación
     * que permite al usuario confirmar su desuscripción.
     */
    public function mostrarFormulario(string $token)
    {
        $tokenData = $this->desuscripcionService->decodificarToken($token);
        
        if (!$tokenData) {
            return response()->view('desuscripcion.error', [
                'mensaje' => 'El enlace de desuscripción es inválido o ha expirado.',
            ], 400);
        }

        return response()->view('desuscripcion.formulario', [
            'token' => $token,
            'motivos' => Desuscripcion::MOTIVOS,
        ]);
    }

    /**
     * Procesa la solicitud de desuscripción.
     * 
     * POST /desuscribir/{token}
     */
    public function procesar(Request $request, string $token)
    {
        $validated = $request->validate([
            'canal' => 'sometimes|string|in:email,sms,todos',
            'motivo' => 'sometimes|string|max:100',
        ]);

        $resultado = $this->desuscripcionService->procesarDesuscripcion(
            token: $token,
            canal: $validated['canal'] ?? Desuscripcion::CANAL_TODOS,
            motivo: $validated['motivo'] ?? null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        if ($request->expectsJson()) {
            return response()->json($resultado, $resultado['success'] ? 200 : 400);
        }

        if ($resultado['success']) {
            return response()->view('desuscripcion.exito', [
                'mensaje' => $resultado['message'],
            ]);
        }

        return response()->view('desuscripcion.error', [
            'mensaje' => $resultado['message'],
        ], 400);
    }

    /**
     * Obtiene estadísticas de desuscripciones (requiere autenticación).
     * 
     * GET /api/desuscripciones/estadisticas
     */
    public function estadisticas(Request $request): JsonResponse
    {
        $dias = $request->input('dias', 30);
        $estadisticas = $this->desuscripcionService->obtenerEstadisticas($dias);

        return response()->json([
            'success' => true,
            'data' => $estadisticas,
        ]);
    }

    /**
     * Lista las desuscripciones recientes (requiere autenticación).
     * 
     * GET /api/desuscripciones
     */
    public function index(Request $request): JsonResponse
    {
        $query = Desuscripcion::with(['prospecto:id,nombre,email,telefono', 'flujo:id,nombre'])
            ->orderBy('created_at', 'desc');

        // Filtros opcionales
        if ($request->has('canal')) {
            $query->where('canal', $request->input('canal'));
        }

        if ($request->has('desde')) {
            $query->where('created_at', '>=', $request->input('desde'));
        }

        if ($request->has('hasta')) {
            $query->where('created_at', '<=', $request->input('hasta'));
        }

        $perPage = $request->input('per_page', 20);
        $desuscripciones = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $desuscripciones,
        ]);
    }
}
