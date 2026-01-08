<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Importacion;
use App\Services\Import\DTO\ImportProgress;
use App\Services\Import\DTO\ImportResult;
use App\Services\Import\DTO\ProspectoRow;
use Illuminate\Support\Facades\Log;

/**
 * Servicio principal de importación de prospectos.
 * 
 * Orquesta todos los componentes del proceso de importación:
 * - ExcelRowReader: Lee filas del Excel en streaming
 * - ProspectoCacheService: Cache de prospectos existentes
 * - TipoProspectoResolver: Resuelve tipos por monto
 * - ProspectoBatchWriter: Escribe en batches a la BD
 * - ImportCheckpointManager: Guarda progreso para resume
 * 
 * @example
 * $service = new ProspectoImportService($importacion, '/path/to/file.xlsx');
 * $service->import();
 * $result = $service->getResult();
 */
final class ProspectoImportService
{
    // =========================================================================
    // DEPENDENCIAS
    // =========================================================================

    private readonly ProspectoCacheService $cacheService;
    private readonly TipoProspectoResolver $tipoResolver;
    private readonly ProspectoBatchWriter $batchWriter;
    private readonly ImportCheckpointManager $checkpointManager;
    private readonly ExcelRowReader $rowReader;

    // =========================================================================
    // ESTADO
    // =========================================================================

    private int $rowsProcessed = 0;
    private int $registrosExitosos = 0;
    private int $registrosFallidos = 0;
    private int $sinEmail = 0;
    private int $sinTelefono = 0;
    private float $startTime = 0;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(
        Importacion $importacion,
        string $filePath,
    ) {
        $this->cacheService = new ProspectoCacheService();
        $this->tipoResolver = new TipoProspectoResolver();
        $this->batchWriter = new ProspectoBatchWriter($importacion->id);
        $this->checkpointManager = new ImportCheckpointManager($importacion);
        $this->rowReader = new ExcelRowReader($filePath);
        
        $this->restoreFromCheckpoint();
    }

    // =========================================================================
    // PUNTO DE ENTRADA PRINCIPAL
    // =========================================================================

    /**
     * Ejecuta la importación completa con soporte para resume.
     * 
     * @throws \Exception Si ocurre un error irrecuperable
     */
    public function import(): void
    {
        $this->startTime = microtime(true);
        
        $this->logStart();
        $this->loadDependencies();
        
        try {
            $this->processAllRows();
            $this->finalize();
        } catch (\Exception $e) {
            $this->handleError($e);
            throw $e;
        }
    }

    // =========================================================================
    // INICIALIZACIÓN
    // =========================================================================

    /**
     * Restaura contadores desde el último checkpoint (para resume).
     */
    private function restoreFromCheckpoint(): void
    {
        $progress = $this->checkpointManager->getInitialProgress();
        
        $this->rowsProcessed = $progress->lastProcessedRow;
        $this->registrosExitosos = $progress->registrosExitosos;
        $this->registrosFallidos = $progress->registrosFallidos;
        $this->sinEmail = $progress->sinEmail;
        $this->sinTelefono = $progress->sinTelefono;
        
        if ($this->checkpointManager->isResuming()) {
            $this->logResume($progress);
        }
    }

    /**
     * Carga las dependencias necesarias (cache, tipos).
     */
    private function loadDependencies(): void
    {
        $this->tipoResolver->load();
        $this->cacheService->loadExistingProspectos();
        
        Log::info('ProspectoImportService: Dependencias cargadas', [
            'tipos_prospecto' => $this->tipoResolver->getTiposCount(),
            'cache_stats' => $this->cacheService->getStats(),
        ]);
    }

    // =========================================================================
    // PROCESAMIENTO
    // =========================================================================

    /**
     * Procesa todas las filas del Excel.
     */
    private function processAllRows(): void
    {
        foreach ($this->rowReader->readRows() as $rowIndex => $rowData) {
            if ($this->shouldSkipRow($rowIndex)) {
                continue;
            }
            
            $this->processRow($rowIndex, $rowData);
            $this->updateProgress($rowIndex);
        }
        
        $this->batchWriter->flush();
    }

    private function shouldSkipRow(int $rowIndex): bool
    {
        return $this->checkpointManager->shouldSkipRow($rowIndex);
    }

    /**
     * Procesa una fila individual del Excel.
     */
    private function processRow(int $rowIndex, array $rowData): void
    {
        $row = ProspectoRow::fromArray($rowData, $rowIndex);
        
        if (!$this->validateAndCountRow($row)) {
            return;
        }

        $tipoId = $this->resolveTipoProspecto($row);
        if ($tipoId === null) {
            return;
        }

        $this->createOrUpdateProspecto($row, $tipoId);
        $this->registrosExitosos++;
    }

    /**
     * Valida la fila y cuenta datos faltantes.
     */
    private function validateAndCountRow(ProspectoRow $row): bool
    {
        if (!$this->validateRow($row)) {
            return false;
        }

        $this->countMissingContactData($row);
        return true;
    }

    /**
     * Valida una fila y registra errores si es inválida.
     */
    private function validateRow(ProspectoRow $row): bool
    {
        if (!$row->hasValidName()) {
            $this->recordValidationError($row->rowIndex, 'nombre', 'Nombre requerido o inválido');
            return false;
        }

        if (!$row->hasValidContact()) {
            $this->recordValidationError($row->rowIndex, 'contacto', 'Debe tener email o teléfono');
            return false;
        }

        return true;
    }

    private function recordValidationError(int $rowIndex, string $field, string $message): void
    {
        $this->registrosFallidos++;
        $this->checkpointManager->addError($rowIndex, [$field => $message]);
    }

    /**
     * Contabiliza registros sin email o teléfono.
     */
    private function countMissingContactData(ProspectoRow $row): void
    {
        if (!$row->hasEmail()) {
            $this->sinEmail++;
        }
        if (!$row->hasTelefono()) {
            $this->sinTelefono++;
        }
    }

    /**
     * Resuelve el tipo de prospecto basado en el monto.
     */
    private function resolveTipoProspecto(ProspectoRow $row): ?int
    {
        $tipoId = $this->tipoResolver->resolveIdByMonto((float) $row->montoDeuda);
        
        if ($tipoId === null) {
            $this->registrosFallidos++;
            $this->checkpointManager->addError($row->rowIndex, [
                'monto_deuda' => "No se encontró tipo para monto: {$row->montoDeuda}"
            ]);
        }

        return $tipoId;
    }

    /**
     * Crea o actualiza un prospecto según si ya existe.
     */
    private function createOrUpdateProspecto(ProspectoRow $row, int $tipoId): void
    {
        $existingId = $this->cacheService->findExistingProspectoId($row->email, $row->telefono);

        if ($this->shouldUpdate($existingId)) {
            $this->batchWriter->queueUpdate($existingId, $row, $tipoId);
            return;
        }

        $this->batchWriter->queueCreate($row, $tipoId);
        $this->cacheService->registerNewProspecto($row->email, $row->telefono);
    }

    private function shouldUpdate(?int $existingId): bool
    {
        return $existingId !== null && $existingId > 0;
    }

    /**
     * Actualiza el progreso y checkpoint.
     */
    private function updateProgress(int $rowIndex): void
    {
        $this->rowsProcessed = $rowIndex;
        
        $this->checkpointManager->updateProgress(
            $rowIndex,
            $this->registrosExitosos,
            $this->registrosFallidos,
            $this->sinEmail,
            $this->sinTelefono,
        );
    }

    // =========================================================================
    // FINALIZACIÓN
    // =========================================================================

    /**
     * Finaliza la importación exitosamente.
     */
    private function finalize(): void
    {
        $result = $this->buildResult();
        
        $this->checkpointManager->markAsCompleted($result);
        $this->logComplete($result);
    }

    /**
     * Construye el resultado final de la importación.
     */
    private function buildResult(): ImportResult
    {
        return ImportResult::create(
            totalRows: $this->rowsProcessed,
            exitosos: $this->registrosExitosos,
            fallidos: $this->registrosFallidos,
            sinEmail: $this->sinEmail,
            sinTelefono: $this->sinTelefono,
            creados: $this->batchWriter->getTotalCreated(),
            actualizados: $this->batchWriter->getTotalUpdated(),
            errores: [], // Los errores están en el checkpoint manager
            tiempoSegundos: $this->getElapsedSeconds(),
        );
    }

    // =========================================================================
    // MANEJO DE ERRORES
    // =========================================================================

    /**
     * Maneja un error durante la importación.
     */
    private function handleError(\Exception $e): void
    {
        $this->tryFlushPendingData();
        $this->checkpointManager->saveCheckpoint();

        Log::error('ProspectoImportService: Error en importación', [
            'row' => $this->rowsProcessed,
            'exitosos' => $this->registrosExitosos,
            'error' => $e->getMessage(),
        ]);
    }

    private function tryFlushPendingData(): void
    {
        try {
            $this->batchWriter->flush();
        } catch (\Exception $flushError) {
            Log::warning('ProspectoImportService: Error en flush durante error handling', [
                'error' => $flushError->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // LOGGING
    // =========================================================================

    private function logStart(): void
    {
        $estimatedRows = $this->rowReader->estimateTotalRows();
        
        Log::info('ProspectoImportService: Iniciando importación', [
            'file_size_mb' => $this->rowReader->getFileSizeMb(),
            'estimated_rows' => $estimatedRows,
        ]);
        
        $this->checkpointManager->saveEstimatedTotal($estimatedRows);
    }

    private function logResume(ImportProgress $progress): void
    {
        Log::info('ProspectoImportService: Resumiendo importación', [
            'desde_fila' => $progress->lastProcessedRow,
            'exitosos_previos' => $progress->registrosExitosos,
        ]);
    }

    private function logComplete(ImportResult $result): void
    {
        Log::info('ProspectoImportService: Importación completada', $result->toLogArray());
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    public function getRegistrosExitosos(): int
    {
        return $this->registrosExitosos;
    }

    public function getRegistrosFallidos(): int
    {
        return $this->registrosFallidos;
    }

    public function getRowsProcessed(): int
    {
        return $this->rowsProcessed;
    }

    public function getResult(): ImportResult
    {
        return $this->buildResult();
    }

    private function getElapsedSeconds(): float
    {
        return round(microtime(true) - $this->startTime, 2);
    }
}
