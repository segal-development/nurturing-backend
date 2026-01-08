<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ImportarProspectosRequest;
use App\Http\Requests\StoreImportacionRequest;
use App\Http\Resources\ImportacionResource;
use App\Http\Resources\LoteResource;
use App\Imports\ProspectosImport;
use App\Jobs\ProcesarImportacionJob;
use App\Models\Importacion;
use App\Models\Lote;
use App\Services\Import\ImportacionRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Controller para gestión de importaciones de prospectos.
 * 
 * Soporta dos modos de procesamiento:
 * - Directo: archivos pequeños (<5MB) se procesan inmediatamente
 * - Background: archivos grandes se suben a Cloud Storage y procesan via Job
 */
class ImportacionController extends Controller
{
    // =========================================================================
    // CONFIGURACION
    // =========================================================================

    /** Tamaño máximo para procesamiento directo (5MB) */
    private const DIRECT_PROCESSING_THRESHOLD_BYTES = 5 * 1024 * 1024;

    /** Threshold para force complete (95% procesado) */
    private const FORCE_COMPLETE_THRESHOLD = 0.95;

    // =========================================================================
    // ENDPOINTS CRUD
    // =========================================================================

    /**
     * Lista importaciones con filtros opcionales.
     */
    public function index(StoreImportacionRequest $request): JsonResponse
    {
        $query = $this->buildIndexQuery($request);
        $importaciones = $query->latest('fecha_importacion')->paginate(15);

        return response()->json([
            'data' => ImportacionResource::collection($importaciones),
            'meta' => $this->buildPaginationMeta($importaciones),
        ]);
    }

    /**
     * Importa prospectos desde archivo Excel.
     * 
     * Soporta lotes: Si se envía lote_id, agrega el archivo a ese lote.
     * Si no, crea un nuevo lote con el nombre de origen.
     */
    public function store(ImportarProspectosRequest $request): JsonResponse
    {
        try {
            $archivo = $request->file('archivo');
            $nombreArchivo = $archivo->getClientOriginalName();
            $lote = $this->obtenerOCrearLote($request);
            
            $procesarEnBackground = $this->debeProceserEnBackground($archivo);

            return $procesarEnBackground
                ? $this->procesarEnBackground($request, $archivo, $nombreArchivo, $lote)
                : $this->procesarDirecto($request, $archivo, $nombreArchivo, $lote);

        } catch (\Exception $e) {
            return $this->errorResponse('Error al procesar la importación', $e);
        }
    }

    /**
     * Muestra detalle de una importación.
     */
    public function show(Importacion $importacion): JsonResponse
    {
        $importacion->load(['user', 'prospectos' => fn($q) => $q->latest()->limit(100)]);

        return response()->json([
            'data' => new ImportacionResource($importacion),
        ]);
    }

    /**
     * Las importaciones no se pueden modificar.
     */
    public function update(): JsonResponse
    {
        return response()->json([
            'mensaje' => 'Las importaciones no se pueden modificar',
        ], 403);
    }

    /**
     * Elimina una importación sin prospectos asociados.
     */
    public function destroy(Importacion $importacion): JsonResponse
    {
        if ($importacion->prospectos()->count() > 0) {
            return response()->json([
                'mensaje' => 'No se puede eliminar una importación con prospectos asociados',
            ], 422);
        }

        $this->eliminarArchivoSiExiste($importacion);
        $importacion->delete();

        return response()->json(['mensaje' => 'Importación eliminada exitosamente']);
    }

    // =========================================================================
    // ENDPOINTS DE PROGRESO
    // =========================================================================

    /**
     * Obtiene el progreso/estado de una importación.
     */
    public function progreso(Importacion $importacion): JsonResponse
    {
        return response()->json([
            'data' => $this->buildProgresoData($importacion),
        ]);
    }

    // =========================================================================
    // ENDPOINTS DE HEALTH Y RECOVERY
    // =========================================================================

    /**
     * Health check y estadísticas del sistema de importaciones.
     */
    public function health(): JsonResponse
    {
        $stats = $this->getRecoveryService()->getHealthStats();
        $hasStuck = $stats['stuck_count'] > 0;

        return response()->json([
            'status' => $hasStuck ? 'warning' : 'healthy',
            'data' => $stats,
            'message' => $hasStuck 
                ? "Hay {$stats['stuck_count']} importación(es) stuck que requieren atención"
                : 'Sistema de importaciones funcionando correctamente',
        ]);
    }

    /**
     * Fuerza el recovery de importaciones stuck.
     */
    public function forceRecovery(): JsonResponse
    {
        $result = $this->getRecoveryService()->recoverStuckImportations();

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
     * pero quedaron en estado "procesando".
     */
    public function forceComplete(): JsonResponse
    {
        $importaciones = Importacion::where('estado', 'procesando')->get();
        $completed = [];
        $skipped = [];

        foreach ($importaciones as $importacion) {
            $resultado = $this->evaluarParaForceComplete($importacion);
            
            if ($resultado['puede_completar']) {
                $this->forzarCompletado($importacion);
                $completed[] = $resultado['info'];
            } else {
                $skipped[] = $resultado['info'];
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
     * Re-encola manualmente una importación específica.
     */
    public function retry(Importacion $importacion): JsonResponse
    {
        $validacion = $this->validarParaRetry($importacion);
        
        if (!$validacion['valido']) {
            return response()->json(['mensaje' => $validacion['mensaje']], $validacion['codigo']);
        }

        $checkpoint = $this->ejecutarRetry($importacion);

        return response()->json([
            'mensaje' => 'Importación re-encolada exitosamente',
            'data' => [
                'importacion_id' => $importacion->id,
                'checkpoint' => $checkpoint,
                'estado' => $importacion->estado,
            ],
        ]);
    }

    // =========================================================================
    // PROCESAMIENTO DE ARCHIVOS
    // =========================================================================

    private function debeProceserEnBackground(UploadedFile $archivo): bool
    {
        return $archivo->getSize() > self::DIRECT_PROCESSING_THRESHOLD_BYTES;
    }

    /**
     * Procesa archivos pequeños directamente.
     */
    private function procesarDirecto(
        ImportarProspectosRequest $request,
        UploadedFile $archivo,
        string $nombreArchivo,
        Lote $lote
    ): JsonResponse {
        try {
            DB::beginTransaction();

            $importacion = $this->crearImportacionDirecta($request, $nombreArchivo, $lote);
            $import = $this->ejecutarImportacion($importacion, $archivo);
            $lote->recalcularTotales();

            DB::commit();

            return $this->buildRespuestaDirecta($importacion, $lote, $import);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Procesa archivos grandes en background.
     */
    private function procesarEnBackground(
        ImportarProspectosRequest $request,
        UploadedFile $archivo,
        string $nombreArchivo,
        Lote $lote
    ): JsonResponse {
        $disk = $this->determinarDisk();
        $rutaArchivo = $this->generarRutaArchivo($archivo);

        Storage::disk($disk)->put($rutaArchivo, file_get_contents($archivo->getRealPath()));

        $importacion = $this->crearImportacionBackground($request, $nombreArchivo, $rutaArchivo, $lote, $archivo, $disk);
        $this->actualizarLoteParaProcesamiento($lote);
        $this->encolarJob($importacion, $rutaArchivo, $disk);

        return $this->buildRespuestaBackground($importacion, $lote);
    }

    // =========================================================================
    // GESTION DE LOTES
    // =========================================================================

    private function obtenerOCrearLote(ImportarProspectosRequest $request): Lote
    {
        if ($request->filled('lote_id')) {
            return $this->obtenerLoteExistente($request->input('lote_id'));
        }

        return $this->crearNuevoLote($request);
    }

    private function obtenerLoteExistente(int $loteId): Lote
    {
        $lote = Lote::findOrFail($loteId);
        
        if ($lote->estado === 'completado') {
            throw new \Exception('No se pueden agregar archivos a un lote completado');
        }
        
        return $lote;
    }

    private function crearNuevoLote(ImportarProspectosRequest $request): Lote
    {
        return Lote::create([
            'nombre' => $request->input('origen'),
            'user_id' => $request->user()->id,
            'estado' => 'abierto',
        ]);
    }

    private function actualizarLoteParaProcesamiento(Lote $lote): void
    {
        $lote->estado = 'procesando';
        $lote->total_archivos = $lote->importaciones()->count();
        $lote->save();
    }

    // =========================================================================
    // CREACION DE IMPORTACIONES
    // =========================================================================

    private function crearImportacionDirecta(
        ImportarProspectosRequest $request,
        string $nombreArchivo,
        Lote $lote
    ): Importacion {
        return Importacion::create([
            'lote_id' => $lote->id,
            'nombre_archivo' => $nombreArchivo,
            'ruta_archivo' => null,
            'origen' => $lote->nombre,
            'user_id' => $request->user()->id,
            'estado' => 'procesando',
            'fecha_importacion' => now(),
            'metadata' => ['modo' => 'directo'],
        ]);
    }

    private function crearImportacionBackground(
        ImportarProspectosRequest $request,
        string $nombreArchivo,
        string $rutaArchivo,
        Lote $lote,
        UploadedFile $archivo,
        string $disk
    ): Importacion {
        return Importacion::create([
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
                'disk' => $disk,
                'encolado_en' => now()->toISOString(),
            ],
        ]);
    }

    private function ejecutarImportacion(Importacion $importacion, UploadedFile $archivo): ProspectosImport
    {
        $import = new ProspectosImport($importacion->id);
        Excel::import($import, $archivo);
        $import->actualizarImportacion();
        
        return $import;
    }

    // =========================================================================
    // STORAGE Y JOBS
    // =========================================================================

    private function determinarDisk(): string
    {
        return app()->environment('local') ? 'local' : 'gcs';
    }

    private function generarRutaArchivo(UploadedFile $archivo): string
    {
        $extension = $archivo->getClientOriginalExtension();
        return 'imports/' . now()->format('Y/m/d') . '/' . uniqid() . '_' . time() . '.' . $extension;
    }

    private function encolarJob(Importacion $importacion, string $rutaArchivo, string $disk): void
    {
        ProcesarImportacionJob::dispatch($importacion->id, $rutaArchivo, $disk);
    }

    private function eliminarArchivoSiExiste(Importacion $importacion): void
    {
        if (!$importacion->ruta_archivo) {
            return;
        }

        try {
            Storage::disk('local')->delete($importacion->ruta_archivo);
        } catch (\Exception $e) {
            // Log error but continue with deletion
        }
    }

    // =========================================================================
    // CALCULO DE PROGRESO
    // =========================================================================

    private function calcularProgreso(Importacion $importacion): int
    {
        if ($importacion->estado === 'pendiente') {
            return 0;
        }

        if (in_array($importacion->estado, ['completado', 'fallido'])) {
            return 100;
        }

        return $this->calcularProgresoEnProcesamiento($importacion);
    }

    private function calcularProgresoEnProcesamiento(Importacion $importacion): int
    {
        $totalEstimado = $importacion->metadata['total_estimado'] ?? 0;
        $procesados = $importacion->total_registros ?? 0;

        if ($totalEstimado <= 0) {
            return 50; // Fallback si no hay estimación
        }

        $porcentaje = (int) round(($procesados / $totalEstimado) * 100);
        
        return min($porcentaje, 99); // Máximo 99% mientras procesa
    }

    // =========================================================================
    // FORCE COMPLETE
    // =========================================================================

    /**
     * @return array{puede_completar: bool, info: array}
     */
    private function evaluarParaForceComplete(Importacion $importacion): array
    {
        $porcentaje = $this->calcularPorcentajeProcesado($importacion);
        $archivoExiste = $this->verificarArchivoExiste($importacion);

        $puedeCompletar = $porcentaje >= self::FORCE_COMPLETE_THRESHOLD && !$archivoExiste;

        $info = [
            'id' => $importacion->id,
            'nombre_archivo' => $importacion->nombre_archivo,
            'porcentaje_procesado' => round($porcentaje * 100, 2),
        ];

        if (!$puedeCompletar) {
            $info['archivo_existe'] = $archivoExiste;
            $info['razon'] = $archivoExiste 
                ? 'El archivo aún existe (puede estar procesando)'
                : 'Porcentaje procesado menor al threshold (' . round(self::FORCE_COMPLETE_THRESHOLD * 100) . '%)';
        }

        return [
            'puede_completar' => $puedeCompletar,
            'info' => $info,
        ];
    }

    private function calcularPorcentajeProcesado(Importacion $importacion): float
    {
        $total = $importacion->total_registros ?? 0;
        $exitosos = $importacion->registros_exitosos ?? 0;
        $fallidos = $importacion->registros_fallidos ?? 0;
        $procesados = $exitosos + $fallidos;

        return $total > 0 ? ($procesados / $total) : 0;
    }

    private function verificarArchivoExiste(Importacion $importacion): bool
    {
        if (empty($importacion->ruta_archivo)) {
            return false;
        }

        try {
            $disk = $importacion->metadata['disk'] ?? 'gcs';
            return Storage::disk($disk)->exists($importacion->ruta_archivo);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function forzarCompletado(Importacion $importacion): void
    {
        $importacion->update([
            'estado' => 'completado',
            'metadata' => array_merge($importacion->metadata ?? [], [
                'completado_en' => now()->toISOString(),
                'completado_por' => 'force_complete_api',
                'nota' => 'Forzado via API - proceso terminó pero no guardó estado',
            ]),
        ]);

        $this->updateLoteAfterForceComplete($importacion);
    }

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

        $this->recalcularEstadoLote($lote);
    }

    private function recalcularEstadoLote(Lote $lote): void
    {
        $importaciones = $lote->importaciones()->get();
        
        $todasCompletadas = $importaciones->every(
            fn($imp) => in_array($imp->estado, ['completado', 'fallido'])
        );
        $algunaFallida = $importaciones->contains(fn($imp) => $imp->estado === 'fallido');
        
        $lote->update([
            'total_registros' => $importaciones->sum('total_registros'),
            'registros_exitosos' => $importaciones->sum('registros_exitosos'),
            'registros_fallidos' => $importaciones->sum('registros_fallidos'),
            'estado' => $this->determinarEstadoLote($todasCompletadas, $algunaFallida),
            'cerrado_en' => $todasCompletadas ? now() : null,
        ]);
    }

    private function determinarEstadoLote(bool $todasCompletadas, bool $algunaFallida): string
    {
        if (!$todasCompletadas) {
            return 'procesando';
        }

        return $algunaFallida ? 'fallido' : 'completado';
    }

    // =========================================================================
    // RETRY
    // =========================================================================

    /**
     * @return array{valido: bool, mensaje?: string, codigo?: int}
     */
    private function validarParaRetry(Importacion $importacion): array
    {
        if ($importacion->estado === 'completado') {
            return $this->validacionFallida('Esta importación ya fue completada', 422);
        }

        if ($importacion->estado === 'pendiente' && $this->tieneJobEnCola($importacion)) {
            return $this->validacionFallida('Ya existe un job en cola para esta importación', 422);
        }

        if (empty($importacion->ruta_archivo)) {
            return $this->validacionFallida('La importación no tiene un archivo asociado', 422);
        }

        $validacionArchivo = $this->validarArchivoParaRetry($importacion);
        if (!$validacionArchivo['valido']) {
            return $validacionArchivo;
        }

        return ['valido' => true];
    }

    /**
     * @return array{valido: bool, mensaje: string, codigo: int}
     */
    private function validacionFallida(string $mensaje, int $codigo): array
    {
        return ['valido' => false, 'mensaje' => $mensaje, 'codigo' => $codigo];
    }

    /**
     * @return array{valido: bool, mensaje?: string, codigo?: int}
     */
    private function validarArchivoParaRetry(Importacion $importacion): array
    {
        $disk = $importacion->metadata['disk'] ?? 'gcs';
        
        try {
            if (!Storage::disk($disk)->exists($importacion->ruta_archivo)) {
                return $this->validacionFallida(
                    'El archivo de la importación ya no existe en storage',
                    422
                );
            }
        } catch (\Exception $e) {
            return $this->validacionFallida(
                'Error verificando el archivo: ' . $e->getMessage(),
                500
            );
        }

        return ['valido' => true];
    }

    private function tieneJobEnCola(Importacion $importacion): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%ProcesarImportacionJob%')
            ->where('payload', 'like', '%"importacionId";i:' . $importacion->id . ';%')
            ->exists();
    }

    private function ejecutarRetry(Importacion $importacion): int
    {
        $checkpoint = $importacion->metadata['last_processed_row'] ?? 0;
        $disk = $importacion->metadata['disk'] ?? 'gcs';

        $importacion->update([
            'metadata' => array_merge($importacion->metadata ?? [], [
                'manual_retry_at' => now()->toISOString(),
                'retry_from_checkpoint' => $checkpoint,
            ]),
        ]);

        if ($importacion->estado === 'fallido') {
            $importacion->update(['estado' => 'procesando']);
        }

        $this->encolarJob($importacion, $importacion->ruta_archivo, $disk);

        return $checkpoint;
    }

    // =========================================================================
    // BUILDERS DE RESPUESTA
    // =========================================================================

    private function buildIndexQuery(StoreImportacionRequest $request)
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

        return $query;
    }

    private function buildPaginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function buildProgresoData(Importacion $importacion): array
    {
        return [
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
        ];
    }

    private function buildRespuestaDirecta(
        Importacion $importacion,
        Lote $lote,
        ProspectosImport $import
    ): JsonResponse {
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
    }

    private function buildRespuestaBackground(Importacion $importacion, Lote $lote): JsonResponse
    {
        return response()->json([
            'mensaje' => 'Archivo recibido. La importación se está procesando en segundo plano.',
            'data' => new ImportacionResource($importacion),
            'lote' => new LoteResource($lote->fresh(['importaciones'])),
            'procesamiento' => 'background',
            'instrucciones' => 'Consulte el estado del lote usando GET /api/lotes/' . $lote->id,
        ], 202);
    }

    private function errorResponse(string $mensaje, \Exception $e): JsonResponse
    {
        return response()->json([
            'mensaje' => $mensaje,
            'error' => $e->getMessage(),
        ], 500);
    }

    // =========================================================================
    // SERVICIOS
    // =========================================================================

    private function getRecoveryService(): ImportacionRecoveryService
    {
        return new ImportacionRecoveryService();
    }
}
