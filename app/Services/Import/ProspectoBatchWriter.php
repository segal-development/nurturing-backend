<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Import\DTO\ProspectoRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Escribe prospectos en batches optimizados.
 * Usa INSERT batch para creates y UPSERT para updates.
 * 
 * Single Responsibility: Solo escribe datos a la BD.
 */
final class ProspectoBatchWriter
{
    private const BATCH_SIZE = 1000;

    private int $importacionId;
    
    /** @var array<array> */
    private array $createBuffer = [];
    
    /** @var array<array> */
    private array $updateBuffer = [];
    
    private int $totalCreated = 0;
    private int $totalUpdated = 0;
    private int $totalFailed = 0;

    public function __construct(int $importacionId)
    {
        $this->importacionId = $importacionId;
    }

    /**
     * Encola un prospecto para crear.
     */
    public function queueCreate(ProspectoRow $row, int $tipoProspectoId): void
    {
        $this->createBuffer[] = [
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
            'metadata' => json_encode(['importado_en' => now()->toISOString()]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (count($this->createBuffer) >= self::BATCH_SIZE) {
            $this->flushCreates();
        }
    }

    /**
     * Encola un prospecto para actualizar.
     */
    public function queueUpdate(int $existingId, ProspectoRow $row, int $tipoProspectoId): void
    {
        $this->updateBuffer[] = [
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

        if (count($this->updateBuffer) >= self::BATCH_SIZE) {
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

    /**
     * Ejecuta los inserts pendientes en batch.
     */
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
            Log::warning('ProspectoBatchWriter: Batch insert falló, intentando uno por uno', [
                'count' => $count,
                'error' => $e->getMessage(),
            ]);
            
            $this->insertOneByOne();
        }
        
        $this->createBuffer = [];
    }

    /**
     * Fallback: inserta uno por uno cuando el batch falla.
     */
    private function insertOneByOne(): void
    {
        foreach ($this->createBuffer as $prospecto) {
            try {
                DB::table('prospectos')->insert($prospecto);
                $this->totalCreated++;
            } catch (\Exception $e) {
                $this->totalFailed++;
                Log::debug('ProspectoBatchWriter: Insert individual falló', [
                    'fila' => $prospecto['fila_excel'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Ejecuta los updates pendientes usando UPSERT.
     */
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
                ['nombre', 'email', 'telefono', 'rut', 'url_informe', 'monto_deuda', 'tipo_prospecto_id', 'fecha_ultimo_contacto', 'updated_at']
            );
            $this->totalUpdated += $count;
        } catch (\Exception $e) {
            Log::warning('ProspectoBatchWriter: Batch upsert falló, intentando uno por uno', [
                'count' => $count,
                'error' => $e->getMessage(),
            ]);
            
            $this->updateOneByOne();
        }
        
        $this->updateBuffer = [];
    }

    /**
     * Fallback: actualiza uno por uno cuando el batch falla.
     */
    private function updateOneByOne(): void
    {
        foreach ($this->updateBuffer as $data) {
            try {
                $id = $data['id'];
                unset($data['id']);
                DB::table('prospectos')->where('id', $id)->update($data);
                $this->totalUpdated++;
            } catch (\Exception $e) {
                $this->totalFailed++;
                Log::debug('ProspectoBatchWriter: Update individual falló', [
                    'id' => $data['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

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
}
