<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportarProspectosRequest;
use App\Http\Requests\StoreImportacionRequest;
use App\Http\Resources\ImportacionResource;
use App\Imports\ProspectosImport;
use App\Jobs\ProcesarImportacionJob;
use App\Models\Importacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportacionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(StoreImportacionRequest $request): JsonResponse
    {
        $query = Importacion::query()->with('user');

        if ($request->filled('origen')) {
            $query->where('origen', $request->input('origen'));
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->input('estado'));
        }

        if ($request->filled('fecha_desde')) {
            $query->where('fecha_importacion', '>=', $request->input('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->where('fecha_importacion', '<=', $request->input('fecha_hasta'));
        }

        $importaciones = $query->latest('fecha_importacion')->paginate(15);

        return response()->json([
            'data' => ImportacionResource::collection($importaciones),
            'meta' => [
                'current_page' => $importaciones->currentPage(),
                'total' => $importaciones->total(),
                'per_page' => $importaciones->perPage(),
                'last_page' => $importaciones->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage (importar prospectos).
     * Para archivos grandes, sube a Cloud Storage y procesa en background.
     * Para archivos pequeños (<5000 registros aprox), procesa directamente.
     */
    public function store(ImportarProspectosRequest $request): JsonResponse
    {
        try {
            $archivo = $request->file('archivo');
            $nombreArchivo = $archivo->getClientOriginalName();
            $tamanoArchivo = $archivo->getSize();
            
            // Si el archivo es mayor a 5MB, procesar en background
            $procesarEnBackground = $tamanoArchivo > 5 * 1024 * 1024;

            if ($procesarEnBackground) {
                return $this->procesarEnBackground($request, $archivo, $nombreArchivo);
            }

            return $this->procesarDirecto($request, $archivo, $nombreArchivo);

        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => 'Error al procesar la importación',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Procesa archivos pequeños directamente (método original).
     */
    private function procesarDirecto($request, $archivo, string $nombreArchivo): JsonResponse
    {
        try {
            DB::beginTransaction();

            $importacion = Importacion::create([
                'nombre_archivo' => $nombreArchivo,
                'ruta_archivo' => null,
                'origen' => $request->input('origen'),
                'user_id' => $request->user()->id,
                'estado' => 'procesando',
                'fecha_importacion' => now(),
                'metadata' => ['modo' => 'directo'],
            ]);

            $import = new ProspectosImport($importacion->id);
            Excel::import($import, $archivo);
            $import->actualizarImportacion();

            DB::commit();

            return response()->json([
                'mensaje' => 'Importación completada exitosamente',
                'data' => new ImportacionResource($importacion->fresh()),
                'procesamiento' => 'directo',
                'resumen' => [
                    'total_registros' => $import->getRegistrosExitosos() + $import->getRegistrosFallidos(),
                    'registros_exitosos' => $import->getRegistrosExitosos(),
                    'registros_fallidos' => $import->getRegistrosFallidos(),
                    'registros_sin_email' => $import->getSinEmail(),
                    'registros_sin_telefono' => $import->getSinTelefono(),
                    'errores' => $import->getErrores(),
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Procesa archivos grandes en background.
     * Sube a Cloud Storage y encola job para procesamiento.
     */
    private function procesarEnBackground($request, $archivo, string $nombreArchivo): JsonResponse
    {
        // Generar nombre único para el archivo
        $extension = $archivo->getClientOriginalExtension();
        $rutaArchivo = 'imports/' . now()->format('Y/m/d') . '/' . uniqid() . '_' . time() . '.' . $extension;

        // Determinar disk a usar (gcs en producción/qa, local en desarrollo)
        $disk = app()->environment('local') ? 'local' : 'gcs';

        // Subir archivo a storage
        Storage::disk($disk)->put($rutaArchivo, file_get_contents($archivo->getRealPath()));

        // Crear registro de importación
        $importacion = Importacion::create([
            'nombre_archivo' => $nombreArchivo,
            'ruta_archivo' => $rutaArchivo,
            'origen' => $request->input('origen'),
            'user_id' => $request->user()->id,
            'estado' => 'pendiente',
            'fecha_importacion' => now(),
            'metadata' => [
                'modo' => 'background',
                'tamano_archivo' => $archivo->getSize(),
                'disk' => $disk,
                'encolado_en' => now()->toISOString(),
            ],
        ]);

        // Encolar job para procesamiento
        ProcesarImportacionJob::dispatch(
            $importacion->id,
            $rutaArchivo,
            $disk
        );

        return response()->json([
            'mensaje' => 'Archivo recibido. La importación se está procesando en segundo plano.',
            'data' => new ImportacionResource($importacion),
            'procesamiento' => 'background',
            'instrucciones' => 'Consulte el estado de la importación usando GET /api/importaciones/' . $importacion->id,
        ], 202);
    }

    /**
     * Get import progress/status.
     */
    public function progreso(Importacion $importacion): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $importacion->id,
                'estado' => $importacion->estado,
                'nombre_archivo' => $importacion->nombre_archivo,
                'total_registros' => $importacion->total_registros,
                'registros_exitosos' => $importacion->registros_exitosos,
                'registros_fallidos' => $importacion->registros_fallidos,
                'progreso_porcentaje' => $this->calcularProgreso($importacion),
                'metadata' => $importacion->metadata,
                'created_at' => $importacion->created_at,
                'updated_at' => $importacion->updated_at,
            ],
        ]);
    }

    /**
     * Calcula el porcentaje de progreso de la importación.
     */
    private function calcularProgreso(Importacion $importacion): int
    {
        if ($importacion->estado === 'pendiente') {
            return 0;
        }

        if ($importacion->estado === 'completado' || $importacion->estado === 'fallido') {
            return 100;
        }

        // Para estado 'procesando', calcular basado en registros procesados vs estimados
        $totalEstimado = $importacion->metadata['total_estimado'] ?? 0;
        $procesados = $importacion->total_registros ?? 0;

        if ($totalEstimado <= 0) {
            return 50; // Fallback si no hay estimación
        }

        // Calcular porcentaje real, con máximo de 99% mientras procesa
        $porcentaje = (int) round(($procesados / $totalEstimado) * 100);
        
        // Si superó el estimado, mostrar 99% hasta que termine
        return min($porcentaje, 99);
    }

    /**
     * Display the specified resource.
     */
    public function show(Importacion $importacion): JsonResponse
    {
        $importacion->load(['user', 'prospectos' => function ($query) {
            $query->latest()->limit(100);
        }]);

        return response()->json([
            'data' => new ImportacionResource($importacion),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(): JsonResponse
    {
        return response()->json([
            'mensaje' => 'Las importaciones no se pueden modificar',
        ], 403);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Importacion $importacion): JsonResponse
    {
        if ($importacion->prospectos()->count() > 0) {
            return response()->json([
                'mensaje' => 'No se puede eliminar una importación con prospectos asociados',
            ], 422);
        }

        try {
            if ($importacion->ruta_archivo) {
                Storage::disk('local')->delete($importacion->ruta_archivo);
            }
        } catch (\Exception $e) {
            // Log error but continue with deletion
        }

        $importacion->delete();

        return response()->json([
            'mensaje' => 'Importación eliminada exitosamente',
        ]);
    }
}
