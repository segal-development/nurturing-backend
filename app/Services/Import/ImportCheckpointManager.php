<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Importacion;
use App\Models\Lote;
use App\Services\Import\DTO\ImportProgress;
use App\Services\Import\DTO\ImportResult;
use Illuminate\Support\Facades\Log;

/**
 * Maneja los checkpoints de importación para soportar resume.
 * 
 * Responsabilidades:
 * - Guardar progreso periódicamente (checkpoints)
 * - Enviar heartbeats para indicar que el proceso está vivo
 * - Marcar importaciones como completadas/fallidas
 * - Actualizar el lote padre cuando corresponde
 * 
 * @see ImportProgress DTO que maneja el estado del progreso
 */
final class ImportCheckpointManager
{
    // =========================================================================
    // CONFIGURACIÓN
    // =========================================================================

    /** Guardar checkpoint completo cada N filas */
    private const CHECKPOINT_INTERVAL = 5000;
    
    /** Enviar heartbeat (touch) cada N filas */
    private const HEARTBEAT_INTERVAL = 1000;
    
    /** Máximo de errores a almacenar (para no consumir memoria) */
    private const MAX_ERRORS_STORED = 100;

    // =========================================================================
    // ESTADO
    // =========================================================================

    private Importacion $importacion;
    private ImportProgress $progress;
    private int $lastCheckpointRow = 0;
    private int $lastHeartbeatRow = 0;
    
    /** @var array<array{fila: int, errores: array}> */
    private array $errores = [];

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(Importacion $importacion)
    {
        $this->importacion = $importacion;
        $this->initializeFromExistingProgress();
    }

    /**
     * Inicializa el estado desde el progreso existente (para resume).
     */
    private function initializeFromExistingProgress(): void
    {
        $this->progress = ImportProgress::fromMetadata($this->importacion->metadata);
        $this->lastCheckpointRow = $this->progress->lastProcessedRow;
        $this->lastHeartbeatRow = $this->progress->lastProcessedRow;
        $this->errores = $this->progress->errores;
    }

    // =========================================================================
    // CONSULTAS
    // =========================================================================

    /**
     * Verifica si una fila debe saltarse (ya procesada en ejecución anterior).
     */
    public function shouldSkipRow(int $rowIndex): bool
    {
        return $this->progress->shouldSkipRow($rowIndex);
    }

    /**
     * Obtiene el progreso inicial para resumir contadores.
     */
    public function getInitialProgress(): ImportProgress
    {
        return $this->progress;
    }

    /**
     * Obtiene la última fila procesada.
     */
    public function getLastProcessedRow(): int
    {
        return $this->progress->lastProcessedRow;
    }

    /**
     * Verifica si esta es una importación que se está resumiendo.
     */
    public function isResuming(): bool
    {
        return $this->progress->hasProgress();
    }

    // =========================================================================
    // ACTUALIZACIÓN DE PROGRESO
    // =========================================================================

    /**
     * Actualiza el progreso y guarda checkpoint/heartbeat si corresponde.
     */
    public function updateProgress(
        int $currentRow,
        int $exitosos,
        int $fallidos,
        int $sinEmail,
        int $sinTelefono,
    ): void {
        $this->progress = $this->progress->withUpdatedCounters(
            $currentRow,
            $exitosos,
            $fallidos,
            $sinEmail,
            $sinTelefono,
        );

        $this->handleCheckpointOrHeartbeat($currentRow);
    }

    /**
     * Decide si guardar checkpoint o enviar heartbeat.
     */
    private function handleCheckpointOrHeartbeat(int $currentRow): void
    {
        $rowsSinceCheckpoint = $currentRow - $this->lastCheckpointRow;
        $rowsSinceHeartbeat = $currentRow - $this->lastHeartbeatRow;

        if ($rowsSinceCheckpoint >= self::CHECKPOINT_INTERVAL) {
            $this->saveCheckpoint();
            $this->lastCheckpointRow = $currentRow;
            $this->lastHeartbeatRow = $currentRow;
            return;
        }

        if ($rowsSinceHeartbeat >= self::HEARTBEAT_INTERVAL) {
            $this->sendHeartbeat();
            $this->lastHeartbeatRow = $currentRow;
        }
    }

    // =========================================================================
    // ERRORES
    // =========================================================================

    /**
     * Agrega un error de fila (limitado para no consumir memoria).
     */
    public function addError(int $fila, array $errores): void
    {
        if ($this->hasReachedErrorLimit()) {
            return;
        }

        $this->errores[] = [
            'fila' => $fila,
            'errores' => $errores,
        ];
    }

    private function hasReachedErrorLimit(): bool
    {
        return count($this->errores) >= self::MAX_ERRORS_STORED;
    }

    // =========================================================================
    // PERSISTENCIA
    // =========================================================================

    /**
     * Guarda el total estimado de filas al inicio.
     */
    public function saveEstimatedTotal(int $estimatedRows): void
    {
        $this->updateMetadata(['total_estimado' => $estimatedRows]);
        
        $this->logInfo('Total estimado guardado', [
            'total_estimado' => $estimatedRows,
        ]);
    }

    /**
     * Guarda el checkpoint actual en la BD.
     */
    public function saveCheckpoint(): void
    {
        try {
            $this->importacion->update([
                'total_registros' => $this->progress->lastProcessedRow,
                'registros_exitosos' => $this->progress->registrosExitosos,
                'registros_fallidos' => $this->progress->registrosFallidos,
                'metadata' => $this->buildCheckpointMetadata(),
            ]);

            $this->logInfo('Checkpoint guardado', [
                'row' => $this->progress->lastProcessedRow,
                'exitosos' => $this->progress->registrosExitosos,
            ]);
        } catch (\Exception $e) {
            $this->logWarning('Error guardando checkpoint', $e);
        }
    }

    private function buildCheckpointMetadata(): array
    {
        return array_merge(
            $this->importacion->metadata ?? [],
            $this->progress->toMetadata(),
            ['checkpoint_at' => now()->toISOString()]
        );
    }

    /**
     * Envía heartbeat ligero (solo updated_at).
     */
    private function sendHeartbeat(): void
    {
        try {
            $this->importacion->touch();
        } catch (\Exception) {
            // Silenciar - heartbeats no son críticos
        }
    }

    // =========================================================================
    // FINALIZACIÓN
    // =========================================================================

    /**
     * Marca la importación como completada con el resultado final.
     */
    public function markAsCompleted(ImportResult $result): void
    {
        $estado = $result->getEstadoFinal();

        $this->importacion->update([
            'estado' => $estado,
            'total_registros' => $result->totalRows,
            'registros_exitosos' => $result->registrosExitosos,
            'registros_fallidos' => $result->registrosFallidos,
            'metadata' => $this->buildCompletionMetadata($result),
        ]);

        $this->logInfo('Importación completada', [
            'estado' => $estado,
            'total' => $result->totalRows,
            'exitosos' => $result->registrosExitosos,
            'fallidos' => $result->registrosFallidos,
        ]);

        $this->updateLoteIfExists();
    }

    /**
     * Marca la importación como completada con parámetros individuales.
     * @deprecated Use markAsCompleted(ImportResult) instead
     */
    public function markAsCompletedLegacy(
        int $totalRows,
        int $exitosos,
        int $fallidos,
        int $sinEmail,
        int $sinTelefono
    ): void {
        $result = ImportResult::create(
            $totalRows,
            $exitosos,
            $fallidos,
            $sinEmail,
            $sinTelefono,
            0, // creados - no disponible en legacy
            0, // actualizados - no disponible en legacy
            $this->errores,
            0.0 // tiempo - no disponible en legacy
        );

        $this->markAsCompleted($result);
    }

    private function buildCompletionMetadata(ImportResult $result): array
    {
        return array_merge(
            $this->importacion->metadata ?? [],
            $result->toMetadata(),
            ['errores' => $this->errores]
        );
    }

    /**
     * Marca la importación como fallida.
     */
    public function markAsFailed(string $error): void
    {
        $this->importacion->update([
            'estado' => 'fallido',
            'metadata' => $this->buildFailureMetadata($error),
        ]);

        $this->logInfo('Importación fallida', ['error' => $error]);
    }

    private function buildFailureMetadata(string $error): array
    {
        return array_merge(
            $this->importacion->metadata ?? [],
            $this->progress->toMetadata(),
            [
                'error' => $error,
                'error_en' => now()->toISOString(),
            ]
        );
    }

    // =========================================================================
    // ACTUALIZACIÓN DE LOTE
    // =========================================================================

    /**
     * Actualiza el lote padre cuando una importación termina.
     * 
     * IMPORTANTE: Solo recalcula totales, NO cierra el lote automáticamente.
     * El lote solo se cierra via POST /api/lotes/{id}/cerrar.
     */
    private function updateLoteIfExists(): void
    {
        $this->importacion->refresh();
        
        $lote = $this->getLoteIfExists();
        if ($lote === null) {
            return;
        }

        $this->recalcularTotalesLote($lote);
    }

    private function getLoteIfExists(): ?Lote
    {
        if (!$this->importacion->lote_id) {
            return null;
        }

        return $this->importacion->lote;
    }

    private function recalcularTotalesLote(Lote $lote): void
    {
        $importaciones = $lote->importaciones()->get();
        
        $totales = $this->calcularTotalesImportaciones($importaciones);
        $estadoLote = $this->determinarEstadoLote($importaciones);

        $lote->update([
            'total_registros' => $totales['registros'],
            'registros_exitosos' => $totales['exitosos'],
            'registros_fallidos' => $totales['fallidos'],
            'estado' => $estadoLote,
        ]);

        $this->logInfo('Lote actualizado (sin cierre automático)', [
            'lote_id' => $lote->id,
            'estado' => $estadoLote,
            'total_registros' => $totales['registros'],
        ]);
    }

    private function calcularTotalesImportaciones($importaciones): array
    {
        return [
            'registros' => $importaciones->sum('total_registros'),
            'exitosos' => $importaciones->sum('registros_exitosos'),
            'fallidos' => $importaciones->sum('registros_fallidos'),
        ];
    }

    private function determinarEstadoLote($importaciones): string
    {
        $hayActivas = $importaciones->contains(
            fn ($imp) => in_array($imp->estado, ['procesando', 'pendiente'])
        );

        return $hayActivas ? 'procesando' : 'abierto';
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function updateMetadata(array $data): void
    {
        try {
            $metadata = array_merge($this->importacion->metadata ?? [], $data);
            $this->importacion->update(['metadata' => $metadata]);
        } catch (\Exception $e) {
            $this->logWarning('Error actualizando metadata', $e);
        }
    }

    private function logInfo(string $message, array $context = []): void
    {
        Log::info("ImportCheckpointManager: {$message}", array_merge(
            ['importacion_id' => $this->importacion->id],
            $context
        ));
    }

    private function logWarning(string $message, \Exception $e): void
    {
        Log::warning("ImportCheckpointManager: {$message}", [
            'importacion_id' => $this->importacion->id,
            'error' => $e->getMessage(),
        ]);
    }
}
