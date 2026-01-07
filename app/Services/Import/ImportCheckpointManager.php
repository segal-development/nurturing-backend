<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Importacion;
use App\Services\Import\DTO\ImportProgress;
use Illuminate\Support\Facades\Log;

/**
 * Maneja los checkpoints de importación para soportar resume.
 * Guarda el progreso periódicamente para poder continuar si se interrumpe.
 * 
 * Single Responsibility: Solo maneja persistencia de checkpoints.
 */
final class ImportCheckpointManager
{
    private const CHECKPOINT_EVERY_N_ROWS = 5000;
    private const HEARTBEAT_EVERY_N_ROWS = 1000;
    private const MAX_ERRORS_STORED = 100;

    private Importacion $importacion;
    private ImportProgress $progress;
    private int $lastCheckpointRow = 0;
    private int $lastHeartbeatRow = 0;
    
    /** @var array<array{fila: int, errores: array}> */
    private array $errores = [];

    public function __construct(Importacion $importacion)
    {
        $this->importacion = $importacion;
        $this->progress = ImportProgress::fromMetadata($importacion->metadata);
        $this->lastCheckpointRow = $this->progress->lastProcessedRow;
        $this->errores = $this->progress->errores;
    }

    /**
     * Verifica si se debe saltar una fila (ya procesada en una ejecución anterior).
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
     * Actualiza el progreso y guarda checkpoint si es necesario.
     * También envía heartbeat para indicar que el proceso sigue vivo.
     */
    public function updateProgress(
        int $currentRow,
        int $exitosos,
        int $fallidos,
        int $sinEmail,
        int $sinTelefono,
    ): void {
        $this->progress = new ImportProgress(
            lastProcessedRow: $currentRow,
            registrosExitosos: $exitosos,
            registrosFallidos: $fallidos,
            sinEmail: $sinEmail,
            sinTelefono: $sinTelefono,
            errores: $this->errores,
        );

        // Guardar checkpoint completo cada N filas
        if ($currentRow - $this->lastCheckpointRow >= self::CHECKPOINT_EVERY_N_ROWS) {
            $this->saveCheckpoint();
            $this->lastCheckpointRow = $currentRow;
            $this->lastHeartbeatRow = $currentRow; // Reset heartbeat también
        }
        // Heartbeat ligero (solo updated_at) cada M filas
        elseif ($currentRow - $this->lastHeartbeatRow >= self::HEARTBEAT_EVERY_N_ROWS) {
            $this->sendHeartbeat();
            $this->lastHeartbeatRow = $currentRow;
        }
    }

    /**
     * Envía un heartbeat ligero (solo actualiza updated_at).
     * Esto indica que el proceso sigue vivo sin el overhead de guardar todo el metadata.
     */
    private function sendHeartbeat(): void
    {
        try {
            $this->importacion->touch();
        } catch (\Exception $e) {
            // Silenciar errores de heartbeat - no son críticos
        }
    }

    /**
     * Agrega un error (limitado para no consumir memoria).
     */
    public function addError(int $fila, array $errores): void
    {
        if (count($this->errores) >= self::MAX_ERRORS_STORED) {
            return;
        }

        $this->errores[] = [
            'fila' => $fila,
            'errores' => $errores,
        ];
    }

    /**
     * Guarda el checkpoint actual en la BD.
     */
    public function saveCheckpoint(): void
    {
        try {
            $metadata = array_merge(
                $this->importacion->metadata ?? [],
                $this->progress->toMetadata(),
                ['checkpoint_at' => now()->toISOString()]
            );

            $this->importacion->update([
                'total_registros' => $this->progress->lastProcessedRow,
                'registros_exitosos' => $this->progress->registrosExitosos,
                'registros_fallidos' => $this->progress->registrosFallidos,
                'metadata' => $metadata,
            ]);

            Log::info('ImportCheckpointManager: Checkpoint guardado', [
                'importacion_id' => $this->importacion->id,
                'row' => $this->progress->lastProcessedRow,
                'exitosos' => $this->progress->registrosExitosos,
            ]);
        } catch (\Exception $e) {
            Log::warning('ImportCheckpointManager: Error guardando checkpoint', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Marca la importación como completada.
     */
    public function markAsCompleted(int $totalRows, int $exitosos, int $fallidos, int $sinEmail, int $sinTelefono): void
    {
        $estado = ($fallidos > 0 && $exitosos === 0) ? 'fallido' : 'completado';

        $this->importacion->update([
            'estado' => $estado,
            'total_registros' => $totalRows,
            'registros_exitosos' => $exitosos,
            'registros_fallidos' => $fallidos,
            'metadata' => array_merge(
                $this->importacion->metadata ?? [],
                [
                    'errores' => $this->errores,
                    'registros_sin_email' => $sinEmail,
                    'registros_sin_telefono' => $sinTelefono,
                    'completado_en' => now()->toISOString(),
                    'last_processed_row' => $totalRows,
                ]
            ),
        ]);

        Log::info('ImportCheckpointManager: Importación completada', [
            'importacion_id' => $this->importacion->id,
            'estado' => $estado,
            'total' => $totalRows,
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
        ]);
    }

    /**
     * Marca la importación como fallida.
     */
    public function markAsFailed(string $error): void
    {
        $this->importacion->update([
            'estado' => 'fallido',
            'metadata' => array_merge(
                $this->importacion->metadata ?? [],
                $this->progress->toMetadata(),
                [
                    'error' => $error,
                    'error_en' => now()->toISOString(),
                ]
            ),
        ]);
    }

    public function getLastProcessedRow(): int
    {
        return $this->progress->lastProcessedRow;
    }
}
