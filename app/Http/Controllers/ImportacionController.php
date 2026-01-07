<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportarProspectosRequest;
use App\Http\Requests\StoreImportacionRequest;
use App\Http\Resources\ImportacionResource;
use App\Http\Resources\LoteResource;
use App\Imports\ProspectosImport;
use App\Jobs\ProcesarLoteJob;
use App\Models\Importacion;
use App\Models\Lote;
use App\Services\Import\ImportacionRecoveryService;
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
     * 
     * Soporta lotes: Si se envía lote_id, agrega el archivo a ese lote.
     * Si no, crea un nuevo lote con el nombre de origen.
     */
    public function store(ImportarProspectosRequest $request): JsonResponse
    {
        try {
            $archivo = $request->file('archivo');
            $nombreArchivo = $archivo->getClientOriginalName();
            $tamanoArchivo = $archivo->getSize();
            
            // Obtener o crear el lote
            $lote = $this->obtenerOCrearLote($request);
            
            // Si el archivo es mayor a 5MB, procesar en background
            $procesarEnBackground = $tamanoArchivo > 5 * 1024 * 1024;

            if ($procesarEnBackground) {
                return $this->procesarEnBackground($request, $archivo, $nombreArchivo, $lote);
            }

            return $this->procesarDirecto($request, $archivo, $nombreArchivo, $lote);

        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => 'Error al procesar la importación',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtiene un lote existente o crea uno nuevo.
     */
    private function obtenerOCrearLote($request): Lote
    {
        // Si viene lote_id, usar ese lote
        if ($request->filled('lote_id')) {
            $lote = Lote::findOrFail($request->input('lote_id'));
            
            // Verificar que el lote permite agregar más archivos
            if ($lote->estado === 'completado') {
                throw new \Exception('No se pueden agregar archivos a un lote completado');
            }
            
            return $lote;
        }

        // Crear nuevo lote con el nombre de origen
        return Lote::create([
            'nombre' => $request->input('origen'),
            'user_id' => $request->user()->id,
            'estado' => 'abierto',
        ]);
    }

    /**
     * Procesa archivos pequeños directamente (método original).
     */
    private function procesarDirecto($request, $archivo, string $nombreArchivo, Lote $lote): JsonResponse
    {
        try {
            DB::beginTransaction();

            $importacion = Importacion::create([
                'lote_id' => $lote->id,
                'nombre_archivo' => $nombreArchivo,
                'ruta_archivo' => null,
                'origen' => $lote->nombre,
                'user_id' => $request->user()->id,
                'estado' => 'procesando',
                'fecha_importacion' => now(),
                'metadata' => ['modo' => 'directo'],
            ]);

            $import = new ProspectosImport($importacion->id);
            Excel::import($import, $archivo);
            $import->actualizarImportacion();

            // Actualizar totales del lote
            $lote->recalcularTotales();

            DB::commit();

            return response()->json([
                'mensaje' => 'Importación completada exitosamente',
                'data' => new ImportacionResource($importacion->fresh()),
                'lote' => new LoteResource($lote->fresh(['importaciones'])),
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
     * Sube a Cloud Storage y encola job del LOTE (no de la importación individual).
     * 
     * ESTRATEGIA: Un solo ProcesarLoteJob procesa TODOS los archivos del lote secuencialmente.
     * Esto elimina problemas de concurrencia entre múltiples jobs.
     */
    private function procesarEnBackground($request, $archivo, string $nombreArchivo, Lote $lote): JsonResponse
    {
        // Generar nombre único para el archivo
        $extension = $archivo->getClientOriginalExtension();
        $rutaArchivo = 'imports/' . now()->format('Y/m/d') . '/' . uniqid() . '_' . time() . '.' . $extension;

        // Determinar disk a usar (gcs en producción/qa, local en desarrollo)
        $disk = app()->environment('local') ? 'local' : 'gcs';

        // Subir archivo a storage
        $contenido = file_get_contents($archivo->getRealPath());
        $uploaded = Storage::disk($disk)->put($rutaArchivo, $contenido);
        
        // VERIFICAR que el archivo se subió correctamente
        if (!$uploaded) {
            throw new \Exception("Error al subir archivo a {$disk}: {$rutaArchivo}");
        }
        
        // Verificar que el archivo existe en storage
        if (!Storage::disk($disk)->exists($rutaArchivo)) {
            throw new \Exception("Archivo subido pero no encontrado en {$disk}: {$rutaArchivo}");
        }
        
        $sizeInStorage = Storage::disk($disk)->size($rutaArchivo);
        
        \Illuminate\Support\Facades\Log::info('ImportacionController: Archivo subido a storage', [
            'nombre_archivo' => $nombreArchivo,
            'ruta_archivo' => $rutaArchivo,
            'disk' => $disk,
            'size_original' => strlen($contenido),
            'size_in_storage' => $sizeInStorage,
            'lote_id' => $lote->id,
        ]);

        // Crear registro de importación en estado PENDIENTE
        $importacion = Importacion::create([
            'lote_id' => $lote->id,
            'nombre_archivo' => $nombreArchivo,
            'ruta_archivo' => $rutaArchivo,
            'origen' => $lote->nombre,
            'user_id' => $request->user()->id,
            'estado' => 'pendiente',
            'fecha_importacion' => now(),
            'metadata' => [
                'modo' => 'background',
                'tamano_archivo' => $archivo->getSize(),
                'tamano_en_storage' => $sizeInStorage,
                'disk' => $disk,
                'subido_en' => now()->toISOString(),
            ],
        ]);

        // Actualizar totales del lote
        $lote->total_archivos = $lote->importaciones()->count();
        $lote->save();

        // Verificar si ya hay un job del lote en cola O si el lote está procesando
        $hayJobEnCola = $this->loteYaTieneJobEnCola($lote->id);
        $loteYaProcesando = $lote->estado === 'procesando';

        if (!$hayJobEnCola && !$loteYaProcesando) {
            // Encolar UN SOLO job para procesar TODO el lote
            $lote->update(['estado' => 'procesando']);
            ProcesarLoteJob::dispatch($lote->id);
            
            \Illuminate\Support\Facades\Log::info('ImportacionController: Job de lote encolado', [
                'lote_id' => $lote->id,
            ]);
        }

        return response()->json([
            'mensaje' => 'Archivo recibido. El lote se está procesando en segundo plano.',
            'data' => new ImportacionResource($importacion),
            'lote' => new LoteResource($lote->fresh(['importaciones'])),
            'procesamiento' => 'background',
            'job_encolado' => !$hayJobEnCola,
            'instrucciones' => 'Consulte el estado del lote usando GET /api/lotes/' . $lote->id,
        ], 202);
    }

    /**
     * Verifica si ya existe un job de lote en cola.
     */
    private function loteYaTieneJobEnCola(int $loteId): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%ProcesarLoteJob%')
            ->where('payload', 'like', '%"loteId";i:' . $loteId . ';%')
            ->exists();
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

    /**
     * Health check y estadísticas del sistema de importaciones.
     * Útil para monitoreo y debugging.
     */
    public function health(): JsonResponse
    {
        $service = new ImportacionRecoveryService();
        $stats = $service->getHealthStats();

        $status = $stats['stuck_count'] > 0 ? 'warning' : 'healthy';

        return response()->json([
            'status' => $status,
            'data' => $stats,
            'message' => $stats['stuck_count'] > 0 
                ? "Hay {$stats['stuck_count']} importación(es) stuck que requieren atención"
                : 'Sistema de importaciones funcionando correctamente',
        ]);
    }

    /**
     * Fuerza el recovery de importaciones stuck.
     * Endpoint manual para intervención cuando el auto-recovery no es suficiente.
     */
    public function forceRecovery(): JsonResponse
    {
        $service = new ImportacionRecoveryService();
        $result = $service->recoverStuckImportations();

        if ($result['recovered'] === 0) {
            return response()->json([
                'mensaje' => 'No se encontraron importaciones stuck para recuperar',
                'recovered' => 0,
            ]);
        }

        return response()->json([
            'mensaje' => "Se recuperaron {$result['recovered']} importación(es)",
            'recovered' => $result['recovered'],
            'importaciones' => $result['importaciones'],
        ]);
    }

    /**
     * Fuerza la finalización de importaciones que terminaron de procesar
     * pero quedaron en estado "procesando" (el proceso murió antes de markAsCompleted).
     * 
     * Criterio: Si procesó >95% de registros y el archivo ya no existe, marcar como completado.
     */
    public function forceComplete(): JsonResponse
    {
        $threshold = 0.95; // 95%
        $completed = [];
        $skipped = [];

        $importaciones = Importacion::where('estado', 'procesando')->get();

        foreach ($importaciones as $importacion) {
            $total = $importacion->total_registros ?? 0;
            $exitosos = $importacion->registros_exitosos ?? 0;
            $fallidos = $importacion->registros_fallidos ?? 0;
            $procesados = $exitosos + $fallidos;

            // Calcular porcentaje
            $porcentaje = $total > 0 ? ($procesados / $total) : 0;

            // Verificar si el archivo existe
            $archivoExiste = false;
            if (!empty($importacion->ruta_archivo)) {
                try {
                    $disk = $importacion->metadata['disk'] ?? 'gcs';
                    $archivoExiste = Storage::disk($disk)->exists($importacion->ruta_archivo);
                } catch (\Exception $e) {
                    // Asumir que no existe si hay error
                }
            }

            // Si procesó >95% y el archivo no existe, marcar como completado
            if ($porcentaje >= $threshold && !$archivoExiste) {
                $importacion->update([
                    'estado' => 'completado',
                    'metadata' => array_merge($importacion->metadata ?? [], [
                        'completado_en' => now()->toISOString(),
                        'completado_por' => 'force_complete_api',
                        'nota' => 'Forzado via API - proceso terminó pero no guardó estado',
                    ]),
                ]);

                // Actualizar el lote
                $this->updateLoteAfterForceComplete($importacion);

                $completed[] = [
                    'id' => $importacion->id,
                    'nombre_archivo' => $importacion->nombre_archivo,
                    'porcentaje_procesado' => round($porcentaje * 100, 2),
                ];
            } else {
                $skipped[] = [
                    'id' => $importacion->id,
                    'nombre_archivo' => $importacion->nombre_archivo,
                    'porcentaje_procesado' => round($porcentaje * 100, 2),
                    'archivo_existe' => $archivoExiste,
                    'razon' => $archivoExiste 
                        ? 'El archivo aún existe (puede estar procesando)'
                        : 'Porcentaje procesado menor al threshold (' . round($threshold * 100) . '%)' ,
                ];
            }
        }

        return response()->json([
            'mensaje' => count($completed) > 0 
                ? 'Se forzó la finalización de ' . count($completed) . ' importación(es)'
                : 'No se encontraron importaciones que cumplan los criterios',
            'completadas' => $completed,
            'omitidas' => $skipped,
        ]);
    }

    /**
     * Actualiza el lote después de forzar una importación como completada.
     */
    private function updateLoteAfterForceComplete(Importacion $importacion): void
    {
        $importacion->refresh();
        
        if (!$importacion->lote_id) {
            return;
        }

        $lote = Lote::find($importacion->lote_id);
        if (!$lote) {
            return;
        }

        $importaciones = $lote->importaciones()->get();
        
        $totalRegistros = $importaciones->sum('total_registros');
        $registrosExitosos = $importaciones->sum('registros_exitosos');
        $registrosFallidos = $importaciones->sum('registros_fallidos');
        
        $todasCompletadas = $importaciones->every(fn ($imp) => in_array($imp->estado, ['completado', 'fallido']));
        $algunaFallida = $importaciones->contains(fn ($imp) => $imp->estado === 'fallido');
        
        $estadoLote = $todasCompletadas 
            ? ($algunaFallida ? 'fallido' : 'completado')
            : 'procesando';

        $lote->update([
            'total_registros' => $totalRegistros,
            'registros_exitosos' => $registrosExitosos,
            'registros_fallidos' => $registrosFallidos,
            'estado' => $estadoLote,
            'cerrado_en' => $todasCompletadas ? now() : null,
        ]);
    }

    /**
     * Re-encola manualmente una importación específica.
     * Útil cuando una importación quedó stuck y necesita ser retomada.
     */
    public function retry(Importacion $importacion): JsonResponse
    {
        // Validar que la importación puede ser reintentada
        if ($importacion->estado === 'completado') {
            return response()->json([
                'mensaje' => 'Esta importación ya fue completada',
            ], 422);
        }

        // Validar que la importación tiene un lote
        if (!$importacion->lote_id) {
            return response()->json([
                'mensaje' => 'La importación no tiene un lote asociado',
            ], 422);
        }

        // Verificar si ya hay un job del lote en cola
        $hasJob = $this->loteYaTieneJobEnCola($importacion->lote_id);

        if ($hasJob) {
            return response()->json([
                'mensaje' => 'Ya existe un job en cola para este lote',
            ], 422);
        }

        // Validar que el archivo existe
        if (empty($importacion->ruta_archivo)) {
            return response()->json([
                'mensaje' => 'La importación no tiene un archivo asociado',
            ], 422);
        }

        $disk = $importacion->metadata['disk'] ?? 'gcs';
        
        try {
            if (!Storage::disk($disk)->exists($importacion->ruta_archivo)) {
                return response()->json([
                    'mensaje' => 'El archivo de la importación ya no existe en storage',
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => 'Error verificando el archivo: ' . $e->getMessage(),
            ], 500);
        }

        // Si estaba en "fallido", volver a "pendiente" para que el job del lote la procese
        if ($importacion->estado === 'fallido') {
            $importacion->update([
                'estado' => 'pendiente',
                'metadata' => array_merge($importacion->metadata ?? [], [
                    'manual_retry_at' => now()->toISOString(),
                ]),
            ]);
        }

        // Encolar job del lote
        $lote = $importacion->lote;
        $lote->update(['estado' => 'procesando']);
        ProcesarLoteJob::dispatch($lote->id);

        return response()->json([
            'mensaje' => 'Lote re-encolado exitosamente',
            'data' => [
                'importacion_id' => $importacion->id,
                'lote_id' => $lote->id,
                'estado' => $importacion->fresh()->estado,
            ],
        ]);
    }
}
