<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncExternalApiJob;
use App\Models\ExternalApiSource;
use App\Services\ExternalApiSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Controller para gestionar fuentes de APIs externas.
 * 
 * Endpoints:
 * - GET    /api/admin/external-sources           - Listar fuentes
 * - POST   /api/admin/external-sources           - Crear fuente
 * - GET    /api/admin/external-sources/{id}      - Ver fuente
 * - PUT    /api/admin/external-sources/{id}      - Actualizar fuente
 * - DELETE /api/admin/external-sources/{id}      - Eliminar fuente
 * - POST   /api/admin/external-sources/{id}/sync - Sincronizar manualmente
 * - POST   /api/admin/external-sources/{id}/test - Probar conexión
 */
class ExternalApiSourceController extends Controller
{
    public function __construct(
        private readonly ExternalApiSyncService $syncService
    ) {}

    /**
     * Listar todas las fuentes externas.
     * 
     * GET /api/admin/external-sources
     */
    public function index(Request $request): JsonResponse
    {
        $query = ExternalApiSource::query();

        // Filtro por estado activo
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Ordenar por última sincronización
        $query->orderBy('last_synced_at', 'desc');

        $sources = $query->get()->map(function ($source) {
            return $this->formatSource($source);
        });

        return response()->json([
            'data' => $sources,
            'total' => $sources->count(),
        ]);
    }

    /**
     * Crear una nueva fuente externa.
     * 
     * POST /api/admin/external-sources
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:external_api_sources,name|regex:/^[a-z0-9_]+$/',
            'display_name' => 'required|string|max:255',
            'endpoint_url' => 'required|url|max:500',
            'auth_type' => 'required|in:bearer,api_key,basic,none',
            'auth_token' => 'nullable|string',
            'headers' => 'nullable|array',
            'field_mapping' => 'nullable|array',
            'is_active' => 'boolean',
        ], [
            'name.regex' => 'El nombre solo puede contener letras minúsculas, números y guiones bajos.',
            'name.unique' => 'Ya existe una fuente con ese nombre.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $source = ExternalApiSource::create([
            'name' => $request->name,
            'display_name' => $request->display_name,
            'endpoint_url' => $request->endpoint_url,
            'auth_type' => $request->auth_type,
            'auth_token' => $request->auth_token,
            'headers' => $request->headers,
            'field_mapping' => $request->field_mapping,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Fuente externa creada exitosamente',
            'data' => $this->formatSource($source),
        ], 201);
    }

    /**
     * Ver una fuente externa específica.
     * 
     * GET /api/admin/external-sources/{id}
     */
    public function show(int $id): JsonResponse
    {
        $source = ExternalApiSource::find($id);

        if (!$source) {
            return response()->json([
                'message' => 'Fuente no encontrada',
            ], 404);
        }

        // Incluir importaciones recientes
        $source->load(['importaciones' => function ($query) {
            $query->orderBy('created_at', 'desc')->limit(10);
        }]);

        return response()->json([
            'data' => $this->formatSource($source, true),
        ]);
    }

    /**
     * Actualizar una fuente externa.
     * 
     * PUT /api/admin/external-sources/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $source = ExternalApiSource::find($id);

        if (!$source) {
            return response()->json([
                'message' => 'Fuente no encontrada',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => "sometimes|string|max:100|unique:external_api_sources,name,{$id}|regex:/^[a-z0-9_]+$/",
            'display_name' => 'sometimes|string|max:255',
            'endpoint_url' => 'sometimes|url|max:500',
            'auth_type' => 'sometimes|in:bearer,api_key,basic,none',
            'auth_token' => 'nullable|string',
            'headers' => 'nullable|array',
            'field_mapping' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $source->update($request->only([
            'name',
            'display_name',
            'endpoint_url',
            'auth_type',
            'auth_token',
            'headers',
            'field_mapping',
            'is_active',
        ]));

        return response()->json([
            'message' => 'Fuente externa actualizada exitosamente',
            'data' => $this->formatSource($source->fresh()),
        ]);
    }

    /**
     * Eliminar una fuente externa.
     * 
     * DELETE /api/admin/external-sources/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $source = ExternalApiSource::find($id);

        if (!$source) {
            return response()->json([
                'message' => 'Fuente no encontrada',
            ], 404);
        }

        // Verificar si tiene importaciones asociadas
        $importacionesCount = $source->importaciones()->count();

        if ($importacionesCount > 0) {
            return response()->json([
                'message' => 'No se puede eliminar la fuente porque tiene importaciones asociadas',
                'importaciones_count' => $importacionesCount,
            ], 409);
        }

        $source->delete();

        return response()->json([
            'message' => 'Fuente externa eliminada exitosamente',
        ]);
    }

    /**
     * Sincronizar una fuente externa manualmente.
     * 
     * POST /api/admin/external-sources/{id}/sync
     */
    public function sync(Request $request, int $id): JsonResponse
    {
        $source = ExternalApiSource::find($id);

        if (!$source) {
            return response()->json([
                'message' => 'Fuente no encontrada',
            ], 404);
        }

        if (!$source->is_active) {
            return response()->json([
                'message' => 'La fuente está inactiva',
            ], 400);
        }

        $async = $request->boolean('async', true);

        if ($async) {
            // Ejecutar en segundo plano
            SyncExternalApiJob::dispatch($source->id, $request->user()?->id);

            return response()->json([
                'message' => 'Sincronización iniciada en segundo plano',
                'job_dispatched' => true,
            ]);
        }

        // Ejecutar sincrónicamente
        try {
            $importacion = $this->syncService->sync($source, $request->user()?->id);

            return response()->json([
                'message' => 'Sincronización completada',
                'data' => [
                    'importacion_id' => $importacion->id,
                    'total_registros' => $importacion->total_registros,
                    'registros_exitosos' => $importacion->registros_exitosos,
                    'registros_fallidos' => $importacion->registros_fallidos,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error en la sincronización',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Probar la conexión a una fuente externa.
     * 
     * POST /api/admin/external-sources/{id}/test
     */
    public function test(int $id): JsonResponse
    {
        $source = ExternalApiSource::find($id);

        if (!$source) {
            return response()->json([
                'message' => 'Fuente no encontrada',
            ], 404);
        }

        $result = $this->syncService->testConnection($source);

        return response()->json([
            'data' => $result,
        ], $result['success'] ? 200 : 400);
    }

    /**
     * Probar conexión con datos temporales (para crear nueva fuente).
     * 
     * POST /api/admin/external-sources/test-new
     */
    public function testNew(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'endpoint_url' => 'required|url',
            'auth_type' => 'required|in:bearer,api_key,basic,none',
            'auth_token' => 'nullable|string',
            'headers' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Crear fuente temporal para probar
        $tempSource = new ExternalApiSource([
            'name' => 'test_temp',
            'display_name' => 'Test',
            'endpoint_url' => $request->endpoint_url,
            'auth_type' => $request->auth_type,
            'auth_token' => $request->auth_token,
            'headers' => $request->headers,
        ]);

        $result = $this->syncService->testConnection($tempSource);

        return response()->json([
            'data' => $result,
        ], $result['success'] ? 200 : 400);
    }

    /**
     * Formatea una fuente para la respuesta JSON.
     */
    private function formatSource(ExternalApiSource $source, bool $includeImportaciones = false): array
    {
        $data = [
            'id' => $source->id,
            'name' => $source->name,
            'display_name' => $source->display_name,
            'endpoint_url' => $source->endpoint_url,
            'auth_type' => $source->auth_type,
            'has_auth_token' => !empty($source->auth_token),
            'headers' => $source->headers,
            'field_mapping' => $source->field_mapping,
            'is_active' => $source->is_active,
            'last_synced_at' => $source->last_synced_at?->toISOString(),
            'last_sync_count' => $source->last_sync_count,
            'last_sync_error' => $source->last_sync_error,
            'created_at' => $source->created_at->toISOString(),
            'updated_at' => $source->updated_at->toISOString(),
        ];

        if ($includeImportaciones && $source->relationLoaded('importaciones')) {
            $data['importaciones'] = $source->importaciones->map(function ($imp) {
                return [
                    'id' => $imp->id,
                    'nombre_archivo' => $imp->nombre_archivo,
                    'total_registros' => $imp->total_registros,
                    'registros_exitosos' => $imp->registros_exitosos,
                    'registros_fallidos' => $imp->registros_fallidos,
                    'estado' => $imp->estado,
                    'fecha_importacion' => $imp->fecha_importacion->toISOString(),
                ];
            });
        }

        return $data;
    }
}
