<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExternalApiSource;
use App\Models\Importacion;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use App\Services\Import\DTO\ImportResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para sincronizar prospectos desde APIs externas.
 * 
 * Consume la API configurada y crea una Importacion con sus Prospectos,
 * de forma análoga a la importación por CSV.
 * 
 * @example
 * $service = new ExternalApiSyncService();
 * $result = $service->sync($source);
 */
class ExternalApiSyncService
{
    private const BATCH_SIZE = 500;

    /**
     * Sincroniza prospectos desde una fuente externa.
     * 
     * @param ExternalApiSource $source La fuente a sincronizar
     * @param int|null $userId ID del usuario que ejecuta (null = sistema)
     * @return Importacion La importación creada
     * @throws \Exception Si la API falla o hay errores críticos
     */
    public function sync(ExternalApiSource $source, ?int $userId = null): Importacion
    {
        Log::info('ExternalApiSyncService: Iniciando sincronización', [
            'source' => $source->name,
            'endpoint' => $source->endpoint_url,
        ]);

        try {
            // 1. Llamar a la API externa
            $data = $this->fetchFromApi($source);
            
            // 2. Crear la Importación
            $importacion = $this->createImportacion($source, $userId, count($data));
            
            // 3. Procesar los prospectos
            $result = $this->processProspectos($importacion, $source, $data);
            
            // 4. Actualizar estado de la importación
            $this->finalizeImportacion($importacion, $result);
            
            // 5. Marcar la fuente como sincronizada
            $source->markAsSynced($result['exitosos']);
            
            Log::info('ExternalApiSyncService: Sincronización completada', [
                'source' => $source->name,
                'importacion_id' => $importacion->id,
                'total' => count($data),
                'exitosos' => $result['exitosos'],
                'fallidos' => $result['fallidos'],
            ]);
            
            return $importacion;
            
        } catch (\Exception $e) {
            $source->markAsFailed($e->getMessage());
            
            Log::error('ExternalApiSyncService: Error en sincronización', [
                'source' => $source->name,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Prueba la conexión a una fuente externa.
     * 
     * @return array{success: bool, message: string, sample_count?: int}
     */
    public function testConnection(ExternalApiSource $source): array
    {
        try {
            $response = Http::withHeaders($source->getRequestHeaders())
                ->timeout(30)
                ->get($source->endpoint_url);
            
            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => "Error HTTP {$response->status()}: {$response->body()}",
                ];
            }
            
            $data = $response->json('data') ?? $response->json();
            
            if (!is_array($data)) {
                return [
                    'success' => false,
                    'message' => 'La respuesta de la API no tiene el formato esperado (se espera array en "data")',
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Conexión exitosa',
                'sample_count' => count($data),
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error de conexión: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Sincroniza todas las fuentes activas.
     * 
     * @return array<string, Importacion|string> Resultados por fuente
     */
    public function syncAll(): array
    {
        $sources = ExternalApiSource::active()->get();
        $results = [];
        
        foreach ($sources as $source) {
            try {
                $results[$source->name] = $this->sync($source);
            } catch (\Exception $e) {
                $results[$source->name] = "Error: {$e->getMessage()}";
            }
        }
        
        return $results;
    }

    /**
     * Llama a la API externa y obtiene los datos.
     */
    private function fetchFromApi(ExternalApiSource $source): array
    {
        $response = Http::withHeaders($source->getRequestHeaders())
            ->timeout(120)
            ->get($source->endpoint_url);
        
        if (!$response->successful()) {
            throw new \Exception("Error HTTP {$response->status()}: {$response->body()}");
        }
        
        $data = $response->json('data') ?? $response->json();
        
        if (!is_array($data)) {
            throw new \Exception('La respuesta de la API no tiene el formato esperado');
        }
        
        return $data;
    }

    /**
     * Crea el registro de Importación.
     */
    private function createImportacion(ExternalApiSource $source, ?int $userId, int $totalRegistros): Importacion
    {
        return Importacion::create([
            'external_api_source_id' => $source->id,
            'nombre_archivo' => "sync_{$source->name}_" . now()->format('Y-m-d_His'),
            'ruta_archivo' => null,
            'origen' => $source->display_name,
            'total_registros' => $totalRegistros,
            'registros_exitosos' => 0,
            'registros_fallidos' => 0,
            'user_id' => $userId ?? 1, // Sistema si no hay usuario
            'estado' => 'procesando',
            'fecha_importacion' => now(),
            'metadata' => [
                'source_name' => $source->name,
                'endpoint_url' => $source->endpoint_url,
                'synced_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Procesa los prospectos de la API y los inserta.
     * 
     * @return array{exitosos: int, fallidos: int, errores: array}
     */
    private function processProspectos(Importacion $importacion, ExternalApiSource $source, array $data): array
    {
        $fieldMapping = $source->getFieldMappingWithDefaults();
        $tiposProspecto = $this->loadTiposProspecto();
        
        $exitosos = 0;
        $fallidos = 0;
        $errores = [];
        $batch = [];
        
        foreach ($data as $index => $row) {
            try {
                $prospecto = $this->mapRowToProspecto($row, $fieldMapping, $importacion->id, $tiposProspecto);
                
                if ($prospecto === null) {
                    $fallidos++;
                    $errores[] = ['index' => $index, 'error' => 'Datos insuficientes (sin nombre o sin email/teléfono)'];
                    continue;
                }
                
                $batch[] = $prospecto;
                
                // Flush en batches
                if (count($batch) >= self::BATCH_SIZE) {
                    $this->insertBatch($batch);
                    $exitosos += count($batch);
                    $batch = [];
                }
                
            } catch (\Exception $e) {
                $fallidos++;
                $errores[] = ['index' => $index, 'error' => $e->getMessage()];
            }
        }
        
        // Flush del último batch
        if (!empty($batch)) {
            $this->insertBatch($batch);
            $exitosos += count($batch);
        }
        
        return [
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'errores' => $errores,
        ];
    }

    /**
     * Mapea una fila de la API a los campos de Prospecto.
     * 
     * @return array|null Array con datos del prospecto o null si es inválido
     */
    private function mapRowToProspecto(
        array $row, 
        array $fieldMapping, 
        int $importacionId,
        Collection $tiposProspecto
    ): ?array {
        $nombre = $this->getFieldValue($row, $fieldMapping['nombre'] ?? 'nombre');
        $email = $this->getFieldValue($row, $fieldMapping['email'] ?? 'email');
        $telefono = $this->getFieldValue($row, $fieldMapping['telefono'] ?? 'telefono');
        $rut = $this->getFieldValue($row, $fieldMapping['rut'] ?? 'rut');
        $montoDeuda = (int) ($this->getFieldValue($row, $fieldMapping['monto_deuda'] ?? 'monto_deuda') ?? 0);
        $urlInforme = $this->getFieldValue($row, $fieldMapping['url_informe'] ?? 'url_informe');
        
        // Validar datos mínimos
        if (empty($nombre)) {
            return null;
        }
        
        if (empty($email) && empty($telefono)) {
            return null;
        }
        
        // Resolver tipo de prospecto por monto
        $tipoProspectoId = $this->resolveTipoProspectoId($montoDeuda, $tiposProspecto);
        
        if ($tipoProspectoId === null) {
            return null;
        }
        
        $now = now();
        
        return [
            'importacion_id' => $importacionId,
            'nombre' => $nombre,
            'rut' => $rut,
            'email' => $email,
            'telefono' => $telefono,
            'url_informe' => $urlInforme ?: null,
            'tipo_prospecto_id' => $tipoProspectoId,
            'estado' => 'activo',
            'monto_deuda' => $montoDeuda,
            'fila_excel' => null, // No aplica para API
            'metadata' => json_encode(['source' => 'external_api']),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Obtiene el valor de un campo, soportando notación con punto para campos anidados.
     */
    private function getFieldValue(array $row, string $field): mixed
    {
        // Soportar campos anidados con notación de punto (ej: "persona.nombre")
        $keys = explode('.', $field);
        $value = $row;
        
        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        
        return $value;
    }

    /**
     * Carga todos los tipos de prospecto.
     */
    private function loadTiposProspecto(): Collection
    {
        return TipoProspecto::orderBy('monto_minimo', 'desc')->get();
    }

    /**
     * Resuelve el ID del tipo de prospecto basado en el monto.
     */
    private function resolveTipoProspectoId(int $monto, Collection $tiposProspecto): ?int
    {
        foreach ($tiposProspecto as $tipo) {
            if ($monto >= $tipo->monto_minimo && $monto <= $tipo->monto_maximo) {
                return $tipo->id;
            }
        }
        
        // Si no encuentra, usar el primero (fallback)
        return $tiposProspecto->first()?->id;
    }

    /**
     * Inserta un batch de prospectos.
     */
    private function insertBatch(array $batch): void
    {
        try {
            DB::table('prospectos')->insert($batch);
        } catch (\Exception $e) {
            // Fallback: insertar uno por uno
            Log::warning('ExternalApiSyncService: Batch insert falló, insertando uno por uno', [
                'error' => $e->getMessage(),
            ]);
            
            foreach ($batch as $prospecto) {
                try {
                    DB::table('prospectos')->insert($prospecto);
                } catch (\Exception $individualError) {
                    Log::debug('ExternalApiSyncService: Insert individual falló', [
                        'email' => $prospecto['email'] ?? 'N/A',
                        'error' => $individualError->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Finaliza la importación actualizando contadores y estado.
     */
    private function finalizeImportacion(Importacion $importacion, array $result): void
    {
        $importacion->update([
            'registros_exitosos' => $result['exitosos'],
            'registros_fallidos' => $result['fallidos'],
            'estado' => 'completado',
            'metadata' => array_merge($importacion->metadata ?? [], [
                'errores' => array_slice($result['errores'], 0, 100), // Guardar máximo 100 errores
                'completed_at' => now()->toISOString(),
            ]),
        ]);
    }
}
