<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Importacion;
use App\Services\Import\DTO\ImportProgress;
use App\Services\Import\DTO\ProspectoRow;
use Illuminate\Support\Facades\Log;

/**
 * Servicio principal de importación de prospectos.
 * Orquesta todos los componentes y soporta resume automático.
 * 
 * Aplica:
 * - Single Responsibility: Orquesta, no implementa detalles
 * - Dependency Injection: Recibe dependencias por constructor
 * - Open/Closed: Extensible sin modificar
 */
final class ProspectoImportService
{
    private ProspectoCacheService $cacheService;
    private TipoProspectoResolver $tipoResolver;
    private ProspectoBatchWriter $batchWriter;
    private ImportCheckpointManager $checkpointManager;
    private ExcelRowReader $rowReader;
    
    // Contadores
    private int $rowsProcessed = 0;
    private int $registrosExitosos = 0;
    private int $registrosFallidos = 0;
    private int $sinEmail = 0;
    private int $sinTelefono = 0;

    public function __construct(
        Importacion $importacion,
        string $filePath,
    ) {
        $this->cacheService = new ProspectoCacheService();
        $this->tipoResolver = new TipoProspectoResolver();
        $this->batchWriter = new ProspectoBatchWriter($importacion->id);
        $this->checkpointManager = new ImportCheckpointManager($importacion);
        $this->rowReader = new ExcelRowReader($filePath);
        
        $this->initializeFromCheckpoint();
    }

    /**
     * Ejecuta la importación completa con soporte para resume.
     */
    public function import(): void
    {
        $this->logStart();
        $this->loadDependencies();
        
        try {
            $this->processAllRows();
            $this->finalizeImport();
        } catch (\Exception $e) {
            $this->handleError($e);
            throw $e;
        }
    }

    /**
     * Inicializa contadores desde el último checkpoint (para resume).
     */
    private function initializeFromCheckpoint(): void
    {
        $progress = $this->checkpointManager->getInitialProgress();
        
        $this->rowsProcessed = $progress->lastProcessedRow;
        $this->registrosExitosos = $progress->registrosExitosos;
        $this->registrosFallidos = $progress->registrosFallidos;
        $this->sinEmail = $progress->sinEmail;
        $this->sinTelefono = $progress->sinTelefono;
        
        if ($progress->lastProcessedRow > 0) {
            Log::info('ProspectoImportService: Resumiendo importación', [
                'desde_fila' => $progress->lastProcessedRow,
                'exitosos_previos' => $progress->registrosExitosos,
            ]);
        }
    }

    /**
     * Carga las dependencias necesarias (cache, tipos, etc).
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

    /**
     * Procesa todas las filas del Excel.
     */
    private function processAllRows(): void
    {
        foreach ($this->rowReader->readRows() as $rowIndex => $rowData) {
            // Skip filas ya procesadas (resume)
            if ($this->checkpointManager->shouldSkipRow($rowIndex)) {
                continue;
            }
            
            $this->processRow($rowIndex, $rowData);
            $this->rowsProcessed = $rowIndex;
            
            // Actualizar checkpoint
            $this->checkpointManager->updateProgress(
                $rowIndex,
                $this->registrosExitosos,
                $this->registrosFallidos,
                $this->sinEmail,
                $this->sinTelefono,
            );
        }
        
        // Flush cualquier batch pendiente
        $this->batchWriter->flush();
    }

    /**
     * Procesa una fila individual.
     */
    private function processRow(int $rowIndex, array $rowData): void
    {
        $row = ProspectoRow::fromArray($rowData, $rowIndex);
        
        // Validaciones rápidas (early returns)
        if (!$this->validateRow($row)) {
            return;
        }

        // Resolver tipo de prospecto
        $tipoId = $this->tipoResolver->resolveIdByMonto((float) $row->montoDeuda);
        if ($tipoId === null) {
            $this->registrosFallidos++;
            $this->checkpointManager->addError($rowIndex, [
                'monto_deuda' => "No se encontró tipo para monto: {$row->montoDeuda}"
            ]);
            return;
        }

        // Contabilizar datos faltantes
        $this->countMissingData($row);

        // Crear o actualizar
        $this->createOrUpdate($row, $tipoId);
        $this->registrosExitosos++;
    }

    /**
     * Valida una fila y retorna false si es inválida.
     */
    private function validateRow(ProspectoRow $row): bool
    {
        if (!$row->hasValidName()) {
            $this->registrosFallidos++;
            $this->checkpointManager->addError($row->rowIndex, [
                'nombre' => 'Nombre requerido o inválido'
            ]);
            return false;
        }

        if (!$row->hasValidContact()) {
            $this->registrosFallidos++;
            $this->checkpointManager->addError($row->rowIndex, [
                'contacto' => 'Debe tener email o teléfono'
            ]);
            return false;
        }

        return true;
    }

    /**
     * Contabiliza registros sin email o teléfono.
     */
    private function countMissingData(ProspectoRow $row): void
    {
        if ($row->email === null) {
            $this->sinEmail++;
        }
        if ($row->telefono === null) {
            $this->sinTelefono++;
        }
    }

    /**
     * Crea un nuevo prospecto o actualiza uno existente.
     */
    private function createOrUpdate(ProspectoRow $row, int $tipoId): void
    {
        $existingId = $this->cacheService->findExistingProspectoId($row->email, $row->telefono);

        if ($existingId !== null && $existingId > 0) {
            $this->batchWriter->queueUpdate($existingId, $row, $tipoId);
            return;
        }

        $this->batchWriter->queueCreate($row, $tipoId);
        $this->cacheService->registerNewProspecto($row->email, $row->telefono);
    }

    /**
     * Finaliza la importación exitosamente.
     */
    private function finalizeImport(): void
    {
        $this->checkpointManager->markAsCompleted(
            $this->rowsProcessed,
            $this->registrosExitosos,
            $this->registrosFallidos,
            $this->sinEmail,
            $this->sinTelefono,
        );

        $this->logComplete();
    }

    /**
     * Maneja un error durante la importación.
     */
    private function handleError(\Exception $e): void
    {
        // Flush lo que se pueda antes de fallar
        try {
            $this->batchWriter->flush();
        } catch (\Exception $flushError) {
            Log::warning('ProspectoImportService: Error en flush durante error handling', [
                'error' => $flushError->getMessage(),
            ]);
        }

        // Guardar checkpoint para poder resumir
        $this->checkpointManager->saveCheckpoint();

        Log::error('ProspectoImportService: Error en importación', [
            'row' => $this->rowsProcessed,
            'exitosos' => $this->registrosExitosos,
            'error' => $e->getMessage(),
        ]);
    }

    private function logStart(): void
    {
        $estimatedRows = $this->rowReader->estimateTotalRows();
        
        Log::info('ProspectoImportService: Iniciando importación', [
            'file_size_mb' => $this->rowReader->getFileSizeMb(),
            'estimated_rows' => $estimatedRows,
        ]);
        
        // Guardar total_estimado en metadata para que el frontend pueda mostrar progreso
        $this->checkpointManager->saveEstimatedTotal($estimatedRows);
    }

    private function logComplete(): void
    {
        Log::info('ProspectoImportService: Importación completada', [
            'total_rows' => $this->rowsProcessed,
            'exitosos' => $this->registrosExitosos,
            'fallidos' => $this->registrosFallidos,
            'created' => $this->batchWriter->getTotalCreated(),
            'updated' => $this->batchWriter->getTotalUpdated(),
            'memoria_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);
    }

    // Getters para compatibilidad
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
}
