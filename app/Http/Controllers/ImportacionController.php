<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportarProspectosRequest;
use App\Http\Requests\StoreImportacionRequest;
use App\Http\Resources\ImportacionResource;
use App\Imports\ProspectosImport;
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
     * El archivo se procesa directamente desde memoria, sin guardarlo.
     */
    public function store(ImportarProspectosRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $archivo = $request->file('archivo');
            $nombreArchivo = $archivo->getClientOriginalName();

            // Crear registro de importación (sin guardar archivo físico)
            $importacion = Importacion::create([
                'nombre_archivo' => $nombreArchivo,
                'ruta_archivo' => null, // No guardamos el archivo
                'origen' => $request->input('origen'),
                'user_id' => $request->user()->id,
                'estado' => 'procesando',
                'fecha_importacion' => now(),
                'metadata' => [],
            ]);

            // Procesar archivo directamente desde memoria
            $import = new ProspectosImport($importacion->id);
            Excel::import($import, $archivo);

            // Actualizar estado de importación
            $import->actualizarImportacion();

            DB::commit();

            return response()->json([
                'mensaje' => 'Importación completada exitosamente',
                'data' => new ImportacionResource($importacion->fresh()),
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

            if (isset($importacion)) {
                $importacion->update(['estado' => 'fallido']);
            }

            return response()->json([
                'mensaje' => 'Error al procesar la importación',
                'error' => $e->getMessage(),
            ], 500);
        }
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
