<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Import\DTO\ProspectoRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Escribe prospectos en batches optimizados.
 * 
 * Usa INSERT batch para creates y UPSERT para updates.
 * Incluye fallback a escritura individual si el batch falla.
 * 
 * @example
 * $writer = new ProspectoBatchWriter($importacionId);
 * $writer->queueCreate($row, $tipoId);
 * $writer->queueUpdate($existingId, $row, $tipoId);
 * $writer->flush(); // Escribe todo lo pendiente
 */
final class ProspectoBatchWriter
{
    // =========================================================================
    // CONFIGURACIÓN
    // =========================================================================

    /** Tamaño del batch para operaciones de BD */
    private const BATCH_SIZE = 1000;

    /** Columnas a actualizar en UPSERT */
    private const UPDATE_COLUMNS = [
        'nombre',
        'email', 
        'telefono',
        'rut',
        'url_informe',
        'monto_deuda',
        'tipo_prospecto_id',
        'fecha_ultimo_contacto',
        'updated_at',
    ];

    // =========================================================================
    // ESTADO
    // =========================================================================

    private readonly int $importacionId;
    
    /** @var array<int, array<string, mixed>> */
    private array $createBuffer = [];
    
    /** @var array<int, array<string, mixed>> */
    private array $updateBuffer = [];
    
    private int $totalCreated = 0;
    private int $totalUpdated = 0;
    private int $totalFailed = 0;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(int $importacionId)
    {
        $this->importacionId = $importacionId;
    }

    // =========================================================================
    // API PÚBLICA
    // =========================================================================

    /**
     * Encola un prospecto para crear.
     */
    public function queueCreate(ProspectoRow $row, int $tipoProspectoId): void
    {
        $this->createBuffer[] = $this->buildCreateData($row, $tipoProspectoId);

        if ($this->shouldFlushCreates()) {
            $this->flushCreates();
        }
    }

    /**
     * Encola un prospecto para actualizar.
     */
    public function queueUpdate(int $existingId, ProspectoRow $row, int $tipoProspectoId): void
    {
        $this->updateBuffer[] = $this->buildUpdateData($existingId, $row, $tipoProspectoId);

        if ($this->shouldFlushUpdates()) {
            $this->flushUpdates();
        }
    }

    /**
     * Escribe todos los buffers pendientes a la BD.
     */
    public function flush(): void
    {
        $this->flushCreates();
        $this->flushUpdates();
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    public function getTotalCreated(): int
    {
        return $this->totalCreated;
    }

    public function getTotalUpdated(): int
    {
        return $this->totalUpdated;
    }

    public function getTotalFailed(): int
    {
        return $this->totalFailed;
    }

    public function getPendingCount(): int
    {
        return count($this->createBuffer) + count($this->updateBuffer);
    }

    public function getStats(): array
    {
        return [
            'created' => $this->totalCreated,
            'updated' => $this->totalUpdated,
            'failed' => $this->totalFailed,
            'pending' => $this->getPendingCount(),
        ];
    }

    // =========================================================================
    // BUILDERS
    // =========================================================================

    private function buildCreateData(ProspectoRow $row, int $tipoProspectoId): array
    {
        $now = now();
        
        return [
            'importacion_id' => $this->importacionId,
            'nombre' => $row->nombre,
            'rut' => $row->rut,
            'email' => $row->email,
            'telefono' => $row->telefono,
            'url_informe' => $row->urlInforme,
            'tipo_prospecto_id' => $tipoProspectoId,
            'estado' => 'activo',
            'monto_deuda' => $row->montoDeuda,
            'fila_excel' => $row->rowIndex,
            'metadata' => json_encode(['importado_en' => $now->toISOString()]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function buildUpdateData(int $existingId, ProspectoRow $row, int $tipoProspectoId): array
    {
        return [
            'id' => $existingId,
            'nombre' => $row->nombre,
            'email' => $row->email,
            'telefono' => $row->telefono,
            'rut' => $row->rut,
            'url_informe' => $row->urlInforme,
            'monto_deuda' => $row->montoDeuda,
            'tipo_prospecto_id' => $tipoProspectoId,
            'fecha_ultimo_contacto' => now(),
            'updated_at' => now(),
        ];
    }

    // =========================================================================
    // FLUSH OPERATIONS
    // =========================================================================

    private function shouldFlushCreates(): bool
    {
        return count($this->createBuffer) >= self::BATCH_SIZE;
    }

    private function shouldFlushUpdates(): bool
    {
        return count($this->updateBuffer) >= self::BATCH_SIZE;
    }

    private function flushCreates(): void
    {
        if (empty($this->createBuffer)) {
            return;
        }

        $count = count($this->createBuffer);
        
        try {
            DB::table('prospectos')->insert($this->createBuffer);
            $this->totalCreated += $count;
        } catch (\Exception $e) {
            $this->handleBatchCreateFailure($count, $e);
        }
        
        $this->createBuffer = [];
    }

    private function flushUpdates(): void
    {
        if (empty($this->updateBuffer)) {
            return;
        }

        $count = count($this->updateBuffer);

        try {
            DB::table('prospectos')->upsert(
                $this->updateBuffer,
                ['id'],
                self::UPDATE_COLUMNS
            );
            $this->totalUpdated += $count;
        } catch (\Exception $e) {
            $this->handleBatchUpdateFailure($count, $e);
        }
        
        $this->updateBuffer = [];
    }

    // =========================================================================
    // FALLBACK: ONE BY ONE
    // =========================================================================

    private function handleBatchCreateFailure(int $count, \Exception $e): void
    {
        Log::warning('ProspectoBatchWriter: Batch insert falló, intentando uno por uno', [
            'count' => $count,
            'error' => $e->getMessage(),
        ]);
        
        $this->insertOneByOne();
    }

    private function handleBatchUpdateFailure(int $count, \Exception $e): void
    {
        Log::warning('ProspectoBatchWriter: Batch upsert falló, intentando uno por uno', [
            'count' => $count,
            'error' => $e->getMessage(),
        ]);
        
        $this->updateOneByOne();
    }

    private function insertOneByOne(): void
    {
        foreach ($this->createBuffer as $prospecto) {
            try {
                DB::table('prospectos')->insert($prospecto);
                $this->totalCreated++;
            } catch (\Exception $e) {
                $this->totalFailed++;
                $this->logIndividualFailure('insert', $prospecto['fila_excel'] ?? 'unknown', $e);
            }
        }
    }

    private function updateOneByOne(): void
    {
        foreach ($this->updateBuffer as $data) {
            $id = $data['id'];
            unset($data['id']);
            
            try {
                DB::table('prospectos')->where('id', $id)->update($data);
                $this->totalUpdated++;
            } catch (\Exception $e) {
                $this->totalFailed++;
                $this->logIndividualFailure('update', $id, $e);
            }
        }
    }

    private function logIndividualFailure(string $operation, mixed $identifier, \Exception $e): void
    {
        Log::debug("ProspectoBatchWriter: {$operation} individual falló", [
            'identifier' => $identifier,
            'error' => $e->getMessage(),
        ]);
    }
}
