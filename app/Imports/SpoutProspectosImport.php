<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Importacion;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Options;

/**
 * Importador de prospectos usando OpenSpout para streaming real.
 * 
 * A diferencia de PhpSpreadsheet, OpenSpout NUNCA carga el archivo completo en memoria.
 * Lee fila por fila, permitiendo procesar archivos de cualquier tamaño con memoria constante.
 * 
 * Diseñado para manejar archivos de 500k+ registros sin problemas de memoria.
 */
class SpoutProspectosImport
{
    private const SYNC_EVERY_N_ROWS = 500;
    private const MAX_ERRORS_STORED = 100;
    private const BATCH_SIZE = 100;
    
    // Bytes promedio por fila en XLSX (usado para estimar total)
    // Basado en: archivo de 37MB con ~380k filas = ~100 bytes/fila
    private const ESTIMATED_BYTES_PER_ROW = 100;

    private int $importacionId;
    private string $filePath;
    
    // Contadores
    private int $registrosExitosos = 0;
    private int $registrosFallidos = 0;
    private int $sinEmail = 0;
    private int $sinTelefono = 0;
    private int $rowsProcessed = 0;
    private int $estimatedTotalRows = 0;
    
    // Errores (limitados para no consumir memoria)
    private array $errores = [];
    
    // Headers del archivo
    private array $headers = [];
    
    // Cache de tipos de prospecto para evitar queries repetidas
    private array $tiposProspectoCache = [];
    
    // Batch para inserts
    private array $prospectosToCreate = [];
    private array $prospectosToUpdate = [];

    public function __construct(int $importacionId, string $filePath)
    {
        $this->importacionId = $importacionId;
        $this->filePath = $filePath;
        $this->loadTiposProspectoCache();
        $this->estimateTotalRows();
    }

    /**
     * Estima el total de filas basándose en el tamaño del archivo.
     * Esto permite mostrar progreso aproximado en el frontend.
     */
    private function estimateTotalRows(): void
    {
        if (!file_exists($this->filePath)) {
            return;
        }

        $fileSize = filesize($this->filePath);
        $this->estimatedTotalRows = (int) ceil($fileSize / self::ESTIMATED_BYTES_PER_ROW);
        
        // Guardar estimación en la importación
        try {
            $importacion = Importacion::find($this->importacionId);
            if ($importacion) {
                $importacion->update([
                    'metadata' => array_merge($importacion->metadata ?? [], [
                        'total_estimado' => $this->estimatedTotalRows,
                        'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                    ]),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('SpoutProspectosImport: Error guardando estimación', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pre-carga todos los tipos de prospecto para evitar N+1 queries.
     */
    private function loadTiposProspectoCache(): void
    {
        $tipos = TipoProspecto::activos()->ordenados()->get();
        foreach ($tipos as $tipo) {
            $this->tiposProspectoCache[] = $tipo;
        }
    }

    /**
     * Encuentra el tipo de prospecto por monto usando el cache.
     */
    private function findTipoProspectoByMonto(float $monto): ?TipoProspecto
    {
        foreach ($this->tiposProspectoCache as $tipo) {
            if ($tipo->enRango($monto)) {
                return $tipo;
            }
        }
        return null;
    }

    /**
     * Ejecuta la importación completa.
     */
    public function import(): void
    {
        Log::info('SpoutProspectosImport: Iniciando importación', [
            'importacion_id' => $this->importacionId,
            'file_path' => $this->filePath,
        ]);

        $options = new Options();
        $reader = new Reader($options);
        
        try {
            $reader->open($this->filePath);
            
            foreach ($reader->getSheetIterator() as $sheet) {
                // Solo procesamos la primera hoja
                $isFirstRow = true;
                
                foreach ($sheet->getRowIterator() as $row) {
                    // OpenSpout v5: Row::toArray() devuelve los valores directamente
                    $rowData = $row->toArray();
                    
                    if ($isFirstRow) {
                        $this->headers = $this->normalizeHeaders($rowData);
                        $isFirstRow = false;
                        continue;
                    }
                    
                    $this->processRow($rowData);
                    $this->rowsProcessed++;
                    
                    // Sincronizar progreso periódicamente
                    if ($this->rowsProcessed % self::SYNC_EVERY_N_ROWS === 0) {
                        $this->flushBatches();
                        $this->syncProgress();
                    }
                }
                
                // Solo procesamos la primera hoja
                break;
            }
            
            // Flush final de cualquier batch pendiente
            $this->flushBatches();
            $this->syncProgress();
            
            $reader->close();
            
            Log::info('SpoutProspectosImport: Importación completada', [
                'importacion_id' => $this->importacionId,
                'rows_processed' => $this->rowsProcessed,
                'exitosos' => $this->registrosExitosos,
                'fallidos' => $this->registrosFallidos,
            ]);
            
        } catch (\Exception $e) {
            $reader->close();
            Log::error('SpoutProspectosImport: Error durante importación', [
                'importacion_id' => $this->importacionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Normaliza los headers a lowercase y sin espacios.
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            if ($header === null) {
                return '';
            }
            return strtolower(trim(str_replace(' ', '_', (string) $header)));
        }, $headers);
    }

    /**
     * Convierte una fila en un array asociativo usando los headers.
     */
    private function rowToAssoc(array $rowData): array
    {
        $assoc = [];
        foreach ($this->headers as $index => $header) {
            $assoc[$header] = $rowData[$index] ?? null;
        }
        return $assoc;
    }

    /**
     * Procesa una fila individual.
     */
    private function processRow(array $rowData): void
    {
        $rowIndex = $this->rowsProcessed + 2; // +2 porque row 1 es header y empezamos en 0
        $data = $this->rowToAssoc($rowData);
        
        // Normalizar valores vacíos a null
        $data['email'] = !empty($data['email']) ? trim((string) $data['email']) : null;
        $data['telefono'] = !empty($data['telefono']) ? trim((string) $data['telefono']) : null;
        $data['rut'] = !empty($data['rut']) ? trim((string) $data['rut']) : null;
        $data['url_informe'] = !empty($data['url_informe']) ? trim((string) $data['url_informe']) : null;
        $data['nombre'] = !empty($data['nombre']) ? trim((string) $data['nombre']) : null;
        
        // Validación
        $validator = Validator::make($data, [
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'rut' => ['nullable', 'string', 'max:255'],
            'url_informe' => ['nullable', 'string', 'max:1000'],
            'monto_deuda' => ['nullable'],
        ]);

        if ($validator->fails()) {
            $this->registrosFallidos++;
            $this->addError($rowIndex, $validator->errors()->toArray());
            return;
        }

        // Validar que al menos email o teléfono estén presentes
        if (empty($data['email']) && empty($data['telefono'])) {
            $this->registrosFallidos++;
            $this->addError($rowIndex, ['contacto' => 'Debe proporcionar al menos un email o teléfono']);
            return;
        }

        try {
            $montoDeuda = $this->limpiarMontoDeuda($data['monto_deuda'] ?? 0);
            $tipoProspecto = $this->findTipoProspectoByMonto((float) $montoDeuda);

            if (!$tipoProspecto) {
                $this->registrosFallidos++;
                $this->addError($rowIndex, [
                    'monto_deuda' => 'No se encontró un tipo de prospecto para el monto: $' . number_format($montoDeuda, 0, ',', '.')
                ]);
                return;
            }

            // Contar registros sin email o teléfono
            if (empty($data['email'])) {
                $this->sinEmail++;
            }
            if (empty($data['telefono'])) {
                $this->sinTelefono++;
            }

            // Buscar prospecto existente
            $existente = $this->findExistingProspecto($data['email'], $data['telefono']);

            if ($existente) {
                $this->queueUpdate($existente, $data, $montoDeuda, $tipoProspecto);
            } else {
                $this->queueCreate($data, $montoDeuda, $tipoProspecto, $rowIndex);
            }

            $this->registrosExitosos++;
            
        } catch (\Exception $e) {
            $this->registrosFallidos++;
            $this->addError($rowIndex, ['general' => $e->getMessage()]);
            Log::warning('SpoutProspectosImport: Error procesando fila', [
                'fila' => $rowIndex,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Busca un prospecto existente por email o teléfono.
     */
    private function findExistingProspecto(?string $email, ?string $telefono): ?Prospecto
    {
        if (!empty($email)) {
            $prospecto = Prospecto::where('email', $email)->first();
            if ($prospecto) {
                return $prospecto;
            }
        }

        if (!empty($telefono)) {
            return Prospecto::where('telefono', $telefono)->first();
        }

        return null;
    }

    /**
     * Encola un prospecto para crear.
     */
    private function queueCreate(array $data, int $montoDeuda, TipoProspecto $tipoProspecto, int $rowIndex): void
    {
        $this->prospectosToCreate[] = [
            'importacion_id' => $this->importacionId,
            'nombre' => $data['nombre'],
            'rut' => $data['rut'],
            'email' => $data['email'],
            'telefono' => $data['telefono'],
            'url_informe' => $data['url_informe'],
            'tipo_prospecto_id' => $tipoProspecto->id,
            'estado' => 'activo',
            'monto_deuda' => $montoDeuda,
            'fila_excel' => $rowIndex,
            'metadata' => json_encode(['importado_en' => now()->toISOString()]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (count($this->prospectosToCreate) >= self::BATCH_SIZE) {
            $this->flushCreates();
        }
    }

    /**
     * Encola un prospecto para actualizar.
     */
    private function queueUpdate(Prospecto $existente, array $data, int $montoDeuda, TipoProspecto $tipoProspecto): void
    {
        $this->prospectosToUpdate[] = [
            'id' => $existente->id,
            'nombre' => $data['nombre'],
            'email' => $data['email'] ?? $existente->email,
            'telefono' => $data['telefono'] ?? $existente->telefono,
            'rut' => $data['rut'] ?? $existente->rut,
            'url_informe' => $data['url_informe'] ?? $existente->url_informe,
            'monto_deuda' => $montoDeuda,
            'tipo_prospecto_id' => $tipoProspecto->id,
            'fecha_ultimo_contacto' => now(),
            'metadata' => json_encode(array_merge(
                $existente->metadata ?? [],
                ['ultima_actualizacion_importacion' => $this->importacionId]
            )),
        ];

        if (count($this->prospectosToUpdate) >= self::BATCH_SIZE) {
            $this->flushUpdates();
        }
    }

    /**
     * Ejecuta los inserts pendientes en batch.
     */
    private function flushCreates(): void
    {
        if (empty($this->prospectosToCreate)) {
            return;
        }

        try {
            DB::table('prospectos')->insert($this->prospectosToCreate);
        } catch (\Exception $e) {
            Log::error('SpoutProspectosImport: Error en batch insert', [
                'count' => count($this->prospectosToCreate),
                'error' => $e->getMessage(),
            ]);
            // Si falla el batch, intentamos uno por uno para no perder todo
            foreach ($this->prospectosToCreate as $prospecto) {
                try {
                    DB::table('prospectos')->insert($prospecto);
                } catch (\Exception $innerE) {
                    $this->registrosFallidos++;
                    $this->registrosExitosos--;
                    $this->addError($prospecto['fila_excel'], ['insert' => $innerE->getMessage()]);
                }
            }
        }
        
        $this->prospectosToCreate = [];
    }

    /**
     * Ejecuta los updates pendientes.
     */
    private function flushUpdates(): void
    {
        if (empty($this->prospectosToUpdate)) {
            return;
        }

        foreach ($this->prospectosToUpdate as $data) {
            try {
                $id = $data['id'];
                unset($data['id']);
                DB::table('prospectos')->where('id', $id)->update($data);
            } catch (\Exception $e) {
                $this->registrosFallidos++;
                $this->registrosExitosos--;
                Log::warning('SpoutProspectosImport: Error actualizando prospecto', [
                    'id' => $id ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $this->prospectosToUpdate = [];
    }

    /**
     * Flush todos los batches pendientes.
     */
    private function flushBatches(): void
    {
        $this->flushCreates();
        $this->flushUpdates();
    }

    /**
     * Limpia y convierte el monto de deuda a entero.
     */
    private function limpiarMontoDeuda(mixed $valor): int
    {
        if (is_numeric($valor)) {
            return (int) $valor;
        }

        $limpio = preg_replace('/[^0-9]/', '', (string) $valor);
        return (int) ($limpio ?: 0);
    }

    /**
     * Agrega un error (limitado a MAX_ERRORS_STORED).
     */
    private function addError(int $fila, array $errores): void
    {
        if (count($this->errores) < self::MAX_ERRORS_STORED) {
            $this->errores[] = [
                'fila' => $fila,
                'errores' => $errores,
            ];
        }
    }

    /**
     * Sincroniza el progreso con la base de datos.
     */
    private function syncProgress(): void
    {
        try {
            $importacion = Importacion::find($this->importacionId);
            if ($importacion) {
                $importacion->update([
                    'total_registros' => $this->rowsProcessed,
                    'registros_exitosos' => $this->registrosExitosos,
                    'registros_fallidos' => $this->registrosFallidos,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('SpoutProspectosImport: Error sincronizando progreso', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Finaliza la importación y actualiza el estado final.
     */
    public function finalize(): void
    {
        $importacion = Importacion::find($this->importacionId);
        
        if (!$importacion) {
            return;
        }

        $estado = 'completado';
        if ($this->registrosFallidos > 0 && $this->registrosExitosos === 0) {
            $estado = 'fallido';
        }

        $importacion->update([
            'estado' => $estado,
            'total_registros' => $this->rowsProcessed,
            'registros_exitosos' => $this->registrosExitosos,
            'registros_fallidos' => $this->registrosFallidos,
            'metadata' => array_merge(
                $importacion->metadata ?? [],
                [
                    'errores' => $this->errores,
                    'registros_sin_email' => $this->sinEmail,
                    'registros_sin_telefono' => $this->sinTelefono,
                    'completado_en' => now()->toISOString(),
                    'procesado_con' => 'openspout',
                ]
            ),
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

    public function getErrores(): array
    {
        return $this->errores;
    }

    public function getSinEmail(): int
    {
        return $this->sinEmail;
    }

    public function getSinTelefono(): int
    {
        return $this->sinTelefono;
    }

    public function getRowsProcessed(): int
    {
        return $this->rowsProcessed;
    }
}
