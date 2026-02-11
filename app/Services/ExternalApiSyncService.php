<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExternalApiSource;
use App\Models\Importacion;
use App\Models\Lote;
use App\Models\TipoProspecto;
use App\Services\Import\ProspectoCacheService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para sincronizar prospectos desde APIs externas.
 *
 * Características:
 * - Crea lotes separados por valor de clasificación (ej: status=form, status=iniciado)
 * - Detecta duplicados usando email/teléfono (no crea prospectos repetidos)
 * - Excluye prospectos que ya están en flujos activos
 * - Usa batch inserts optimizados
 *
 * @example
 * $service = new ExternalApiSyncService();
 * $result = $service->sync($source, $userId);
 */
class ExternalApiSyncService
{
    private const BATCH_SIZE = 500;

    private ProspectoCacheService $cacheService;

    /** @var array<string, int> IDs de prospectos ya en flujos activos */
    private array $prospectosEnFlujoActivo = [];

    public function __construct()
    {
        $this->cacheService = new ProspectoCacheService;
    }

    /**
     * Sincroniza prospectos desde una fuente externa.
     *
     * PRIMER SYNC (sin last_synced_at): Hace sync status por status para no saturar la API.
     * SYNCS SIGUIENTES: Sync incremental, solo trae registros nuevos.
     *
     * @param  ExternalApiSource  $source  La fuente a sincronizar
     * @param  int|null  $userId  ID del usuario que ejecuta (null = sistema)
     * @return array{lotes: array<Lote>, total_prospectos: int, nuevos: int, actualizados: int, omitidos_en_flujo: int}
     *
     * @throws \Exception Si la API falla o hay errores críticos
     */
    public function sync(ExternalApiSource $source, ?int $userId = null): array
    {
        $isFirstSync = $source->last_synced_at === null;

        Log::info('ExternalApiSyncService: Iniciando sincronización', [
            'source' => $source->name,
            'endpoint' => $source->endpoint_url,
            'clasificacion_field' => $source->clasificacion_field,
            'is_first_sync' => $isFirstSync,
        ]);

        try {
            // 1. Cargar cache de prospectos existentes para deduplicación
            $this->cacheService->loadExistingProspectos();

            // 2. Cargar IDs de prospectos ya en flujos activos
            $this->loadProspectosEnFlujoActivo();

            // 3. Si es primer sync Y tiene clasificación, hacer sync por status
            if ($isFirstSync && $source->tieneClasificacion() && ! empty($source->clasificacion_values)) {
                $resultado = $this->syncPorClasificacion($source, $userId ?? 1);
            } else {
                // Sync normal (incremental o sin clasificación)
                $data = $this->fetchFromApi($source);
                $grupos = $this->agruparPorClasificacion($data, $source);
                $resultado = $this->procesarGrupos($grupos, $source, $userId ?? 1);
            }

            // 4. Marcar la fuente como sincronizada
            $source->markAsSynced($resultado['total_prospectos']);

            Log::info('ExternalApiSyncService: Sincronización completada', [
                'source' => $source->name,
                'lotes_creados' => count($resultado['lotes']),
                'total_prospectos' => $resultado['total_prospectos'],
                'nuevos' => $resultado['nuevos'],
                'actualizados' => $resultado['actualizados'],
                'omitidos_en_flujo' => $resultado['omitidos_en_flujo'],
            ]);

            return $resultado;

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
     * Sync por clasificación para primer sync (carga inicial).
     * Hace un sync separado por cada valor de clasificación con pausas entre cada uno.
     */
    private function syncPorClasificacion(ExternalApiSource $source, int $userId): array
    {
        $clasificacionValues = $source->clasificacion_values;
        $valoresAExcluir = $this->getValoresAExcluir($source);
        $pausaEntreStatus = 60; // 60 segundos entre cada status para no saturar la API

        $lotes = [];
        $totalProspectos = 0;
        $totalNuevos = 0;
        $totalActualizados = 0;
        $totalOmitidosEnFlujo = 0;

        $fieldMapping = $source->getFieldMappingWithDefaults();
        $tiposProspecto = $this->loadTiposProspecto();

        foreach ($clasificacionValues as $index => $clasificacionValue) {
            // Saltar valores excluidos (ej: "pagado")
            if (in_array($clasificacionValue, $valoresAExcluir, true)) {
                Log::info("ExternalApiSyncService: Saltando clasificación excluida: {$clasificacionValue}");

                continue;
            }

            Log::info("ExternalApiSyncService: Sincronizando status '{$clasificacionValue}'", [
                'progreso' => ($index + 1).'/'.count($clasificacionValues),
            ]);

            try {
                // Fetch solo este status
                $data = $this->fetchFromApiByStatus($source, $clasificacionValue);

                if (empty($data)) {
                    Log::info("ExternalApiSyncService: No hay registros para status '{$clasificacionValue}'");

                    continue;
                }

                // Crear lote para este status
                $lote = $source->obtenerOCrearLote($clasificacionValue, $userId);

                // Crear importación
                $importacion = $this->createImportacion($source, $lote, $userId, count($data));

                // Procesar prospectos
                $resultado = $this->processProspectos(
                    $importacion,
                    $data,
                    $fieldMapping,
                    $tiposProspecto,
                    $source
                );

                // Finalizar
                $this->finalizeImportacion($importacion, $resultado);
                $lote->recalcularTotales();
                $lote->update(['estado' => 'completado']);

                $lotes[] = $lote;
                $totalProspectos += $resultado['exitosos'];
                $totalNuevos += $resultado['nuevos'];
                $totalActualizados += $resultado['actualizados'];
                $totalOmitidosEnFlujo += $resultado['omitidos_en_flujo'];

                Log::info("ExternalApiSyncService: Status '{$clasificacionValue}' completado", [
                    'prospectos' => $resultado['exitosos'],
                    'nuevos' => $resultado['nuevos'],
                ]);

            } catch (\Exception $e) {
                Log::error("ExternalApiSyncService: Error en status '{$clasificacionValue}'", [
                    'error' => $e->getMessage(),
                ]);
                // Continuar con el siguiente status
            }

            // Pausa entre status para no saturar la API
            if ($index < count($clasificacionValues) - 1) {
                Log::info("ExternalApiSyncService: Pausa de {$pausaEntreStatus} segundos antes del siguiente status");
                sleep($pausaEntreStatus);
            }
        }

        return [
            'lotes' => $lotes,
            'total_prospectos' => $totalProspectos,
            'nuevos' => $totalNuevos,
            'actualizados' => $totalActualizados,
            'omitidos_en_flujo' => $totalOmitidosEnFlujo,
        ];
    }

    /**
     * Fetch de la API filtrando por un status específico.
     */
    private function fetchFromApiByStatus(ExternalApiSource $source, string $status): array
    {
        $allData = [];
        $page = 1;
        $limit = $source->sync_filters['limit'] ?? 100;
        $maxPages = 500; // Límite por status
        $delayBetweenPages = 2000; // 2 segundos entre páginas para no saturar la API
        $maxRetries = 5; // Más reintentos por si hay errores 502

        Log::info("ExternalApiSyncService: Fetch status '{$status}'", [
            'limit_per_page' => $limit,
        ]);

        do {
            $url = $source->endpoint_url;
            $params = [
                'limit' => $limit,
                'page' => $page,
                'status' => $status,
            ];

            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($params);

            $response = $this->fetchWithRetry($url, $source->getRequestHeaders(), $maxRetries);

            $data = $response->json('data') ?? $response->json();

            if (! is_array($data)) {
                throw new \Exception('La respuesta de la API no tiene el formato esperado');
            }

            $allData = array_merge($allData, $data);
            $total = $response->json('total') ?? count($data);
            $fetchedCount = count($data);

            // Log cada 10 páginas
            if ($page % 10 === 1 || $fetchedCount < $limit) {
                Log::info("ExternalApiSyncService: Progreso status '{$status}'", [
                    'page' => $page,
                    'total_acumulado' => count($allData),
                    'total_api' => $total,
                    'progreso' => round((count($allData) / max($total, 1)) * 100, 1).'%',
                ]);
            }

            $page++;
            $hasMorePages = $fetchedCount >= $limit && count($allData) < $total && $page <= $maxPages;

            if ($hasMorePages) {
                usleep($delayBetweenPages * 1000);
            }

        } while ($hasMorePages);

        Log::info("ExternalApiSyncService: Fetch status '{$status}' completado", [
            'total_registros' => count($allData),
            'paginas' => $page - 1,
        ]);

        return $allData;
    }

    /**
     * Prueba la conexión a una fuente externa.
     *
     * @return array{success: bool, message: string, sample_count?: int, clasificacion_values?: array}
     */
    public function testConnection(ExternalApiSource $source): array
    {
        try {
            $response = Http::withHeaders($source->getRequestHeaders())
                ->timeout(30)
                ->get($source->endpoint_url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => "Error HTTP {$response->status()}: {$response->body()}",
                ];
            }

            $data = $response->json('data') ?? $response->json();

            if (! is_array($data)) {
                return [
                    'success' => false,
                    'message' => 'La respuesta de la API no tiene el formato esperado (se espera array en "data")',
                ];
            }

            // Detectar valores de clasificación si hay campo configurado
            $clasificacionValues = [];
            if ($source->clasificacion_field && count($data) > 0) {
                $clasificacionValues = $this->detectarValoresClasificacion($data, $source->clasificacion_field);
            }

            return [
                'success' => true,
                'message' => 'Conexión exitosa',
                'sample_count' => count($data),
                'clasificacion_values' => $clasificacionValues,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error de conexión: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Llama a la API externa y obtiene datos con paginación automática.
     *
     * SYNC INCREMENTAL: Si la fuente tiene last_synced_at, solo trae registros
     * con updatedAt > last_synced_at. La API devuelve ordenado por updatedAt DESC,
     * así que paramos cuando encontramos un registro anterior al último sync.
     */
    private function fetchFromApi(ExternalApiSource $source): array
    {
        $allData = [];
        $page = 1;
        $limit = $source->sync_filters['limit'] ?? 100;
        $maxPages = 2000;
        $delayBetweenPages = 1000; // 1 segundo entre páginas para no saturar
        $maxRetries = 3;

        // Para sync incremental: fecha del último sync
        $lastSyncedAt = $source->last_synced_at;
        $isIncrementalSync = $lastSyncedAt !== null;
        $reachedOldRecords = false;

        Log::info('ExternalApiSyncService: Iniciando fetch', [
            'source' => $source->name,
            'limit_per_page' => $limit,
            'incremental' => $isIncrementalSync,
            'last_synced_at' => $lastSyncedAt?->toISOString(),
        ]);

        do {
            $url = $source->endpoint_url;
            $params = array_merge($source->sync_filters ?? [], [
                'limit' => $limit,
                'page' => $page,
            ]);

            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($params);

            // Retry con backoff exponencial
            $response = $this->fetchWithRetry($url, $source->getRequestHeaders(), $maxRetries);

            $data = $response->json('data') ?? $response->json();

            if (! is_array($data)) {
                throw new \Exception('La respuesta de la API no tiene el formato esperado');
            }

            $total = $response->json('total') ?? count($data);
            $fetchedCount = count($data);

            // Si es sync incremental, filtrar solo registros nuevos
            if ($isIncrementalSync && $fetchedCount > 0) {
                $newRecords = [];
                foreach ($data as $record) {
                    $updatedAt = $record['updatedAt'] ?? null;
                    if ($updatedAt) {
                        $recordDate = \Carbon\Carbon::parse($updatedAt);
                        if ($recordDate->lte($lastSyncedAt)) {
                            // Este registro y los siguientes ya los tenemos
                            $reachedOldRecords = true;
                            break;
                        }
                    }
                    $newRecords[] = $record;
                }
                $data = $newRecords;
            }

            $allData = array_merge($allData, $data);

            // Log cada 10 páginas o cuando hay eventos importantes
            if ($page % 10 === 1 || $fetchedCount < $limit || $reachedOldRecords) {
                Log::info('ExternalApiSyncService: Progreso de paginación', [
                    'page' => $page,
                    'registros_pagina' => $fetchedCount,
                    'registros_nuevos' => count($data),
                    'total_acumulado' => count($allData),
                    'total_api' => $total,
                    'reached_old_records' => $reachedOldRecords,
                ]);
            }

            $page++;

            // Condiciones de salida:
            // 1. No hay más datos en esta página
            // 2. Ya tenemos todos los registros según el total de la API
            // 3. Alcanzamos el límite de páginas (seguridad)
            // 4. SYNC INCREMENTAL: Llegamos a registros que ya teníamos
            $hasMorePages = ! $reachedOldRecords
                && $fetchedCount >= $limit
                && count($allData) < $total
                && $page <= $maxPages;

            // Delay entre páginas para no saturar la API
            if ($hasMorePages) {
                usleep($delayBetweenPages * 1000);
            }

        } while ($hasMorePages);

        Log::info('ExternalApiSyncService: Fetch completado', [
            'total_registros' => count($allData),
            'paginas_procesadas' => $page - 1,
            'incremental' => $isIncrementalSync,
            'stopped_at_old_records' => $reachedOldRecords,
        ]);

        return $allData;
    }

    /**
     * Hace una request HTTP con retry y backoff exponencial.
     */
    private function fetchWithRetry(string $url, array $headers, int $maxRetries): \Illuminate\Http\Client\Response
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout(120)
                    ->get($url);

                if ($response->successful()) {
                    return $response;
                }

                // Si es error 5xx, reintentar
                if ($response->serverError()) {
                    $attempt++;
                    $lastException = new \Exception("Error HTTP {$response->status()}: {$response->body()}");

                    if ($attempt < $maxRetries) {
                        $waitSeconds = pow(2, $attempt); // 2, 4, 8 segundos
                        Log::warning('ExternalApiSyncService: Error 5xx, reintentando', [
                            'attempt' => $attempt,
                            'wait_seconds' => $waitSeconds,
                            'status' => $response->status(),
                        ]);
                        sleep($waitSeconds);

                        continue;
                    }
                }

                // Error 4xx - no reintentar
                throw new \Exception("Error HTTP {$response->status()}: {$response->body()}");
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $attempt++;
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    $waitSeconds = pow(2, $attempt);
                    Log::warning('ExternalApiSyncService: Error de conexión, reintentando', [
                        'attempt' => $attempt,
                        'wait_seconds' => $waitSeconds,
                        'error' => $e->getMessage(),
                    ]);
                    sleep($waitSeconds);

                    continue;
                }
            }
        }

        throw $lastException ?? new \Exception('Error desconocido después de reintentos');
    }

    /**
     * Agrupa los datos por valor de clasificación.
     *
     * @return array<string, array> [valor_clasificacion => [registros]]
     */
    private function agruparPorClasificacion(array $data, ExternalApiSource $source): array
    {
        // Si no hay campo de clasificación, todo va a un grupo "default"
        if (! $source->tieneClasificacion()) {
            return ['default' => $data];
        }

        $grupos = [];
        $field = $source->clasificacion_field;

        // Valores a excluir (ej: "pagado" no es un prospecto para nurturing)
        $excluir = $this->getValoresAExcluir($source);

        foreach ($data as $row) {
            $valor = $this->getFieldValue($row, $field) ?? 'sin_clasificar';
            $valor = (string) $valor;

            // Excluir valores que no son prospectos válidos
            if (in_array($valor, $excluir, true)) {
                continue;
            }

            if (! isset($grupos[$valor])) {
                $grupos[$valor] = [];
            }

            $grupos[$valor][] = $row;
        }

        Log::info('ExternalApiSyncService: Datos agrupados por clasificación', [
            'field' => $field,
            'grupos' => array_map('count', $grupos),
            'excluidos' => $excluir,
        ]);

        return $grupos;
    }

    /**
     * Obtiene los valores de clasificación a excluir del sync.
     * Por defecto excluye "pagado" para APIs de pagos.
     */
    private function getValoresAExcluir(ExternalApiSource $source): array
    {
        // Si tiene sync_filters con "exclude_status", usar esos
        $filters = $source->sync_filters ?? [];

        if (isset($filters['exclude_status'])) {
            return (array) $filters['exclude_status'];
        }

        // Por defecto, excluir "pagado" ya que esos no son prospectos
        return ['pagado'];
    }

    /**
     * Procesa cada grupo creando un lote y sus prospectos.
     *
     * @return array{lotes: array<Lote>, total_prospectos: int, nuevos: int, actualizados: int, omitidos_en_flujo: int}
     */
    private function procesarGrupos(array $grupos, ExternalApiSource $source, int $userId): array
    {
        $lotes = [];
        $totalProspectos = 0;
        $totalNuevos = 0;
        $totalActualizados = 0;
        $totalOmitidosEnFlujo = 0;

        $fieldMapping = $source->getFieldMappingWithDefaults();
        $tiposProspecto = $this->loadTiposProspecto();

        foreach ($grupos as $clasificacionValue => $registros) {
            // Crear o reutilizar lote para este valor de clasificación
            $lote = $source->obtenerOCrearLote($clasificacionValue, $userId);

            // Crear importación dentro del lote
            $importacion = $this->createImportacion($source, $lote, $userId, count($registros));

            // Procesar prospectos
            $resultado = $this->processProspectos(
                $importacion,
                $registros,
                $fieldMapping,
                $tiposProspecto,
                $source
            );

            // Actualizar importación
            $this->finalizeImportacion($importacion, $resultado);

            // Actualizar totales del lote y cerrar (los lotes de API se cierran automáticamente)
            $lote->recalcularTotales();
            $lote->update(['estado' => 'completado']);

            $lotes[] = $lote;
            $totalProspectos += $resultado['exitosos'];
            $totalNuevos += $resultado['nuevos'];
            $totalActualizados += $resultado['actualizados'];
            $totalOmitidosEnFlujo += $resultado['omitidos_en_flujo'];
        }

        return [
            'lotes' => $lotes,
            'total_prospectos' => $totalProspectos,
            'nuevos' => $totalNuevos,
            'actualizados' => $totalActualizados,
            'omitidos_en_flujo' => $totalOmitidosEnFlujo,
        ];
    }

    /**
     * Crea el registro de Importación dentro de un Lote.
     */
    private function createImportacion(
        ExternalApiSource $source,
        Lote $lote,
        int $userId,
        int $totalRegistros
    ): Importacion {
        return Importacion::create([
            'lote_id' => $lote->id,
            'external_api_source_id' => $source->id,
            'nombre_archivo' => "sync_{$source->name}_".now()->format('Y-m-d_His'),
            'ruta_archivo' => null,
            'origen' => $source->display_name,
            'total_registros' => $totalRegistros,
            'registros_exitosos' => 0,
            'registros_fallidos' => 0,
            'user_id' => $userId,
            'estado' => 'procesando',
            'fecha_importacion' => now(),
            'metadata' => [
                'source_name' => $source->name,
                'endpoint_url' => $source->endpoint_url,
                'lote_id' => $lote->id,
                'clasificacion_value' => $lote->clasificacion_value,
                'synced_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Procesa los prospectos con deduplicación y exclusión de flujos activos.
     *
     * @return array{exitosos: int, fallidos: int, nuevos: int, actualizados: int, omitidos_en_flujo: int, errores: array}
     */
    private function processProspectos(
        Importacion $importacion,
        array $data,
        array $fieldMapping,
        Collection $tiposProspecto,
        ExternalApiSource $source
    ): array {
        $exitosos = 0;
        $fallidos = 0;
        $nuevos = 0;
        $actualizados = 0;
        $omitidosEnFlujo = 0;
        $errores = [];

        $createBatch = [];
        $updateBatch = [];

        foreach ($data as $index => $row) {
            try {
                $prospectoData = $this->mapRowToProspecto($row, $fieldMapping, $importacion->id, $tiposProspecto);

                if ($prospectoData === null) {
                    $fallidos++;
                    $errores[] = ['index' => $index, 'error' => 'Datos insuficientes (sin nombre o sin email/teléfono)'];

                    continue;
                }

                $email = $prospectoData['email'];
                $telefono = $prospectoData['telefono'];

                // Buscar si ya existe
                $existingId = $this->cacheService->findExistingProspectoId($email, $telefono);

                if ($existingId !== null) {
                    // Ya existe - verificar si está en flujo activo
                    if ($this->estaEnFlujoActivo($existingId)) {
                        $omitidosEnFlujo++;

                        continue;
                    }

                    // Actualizar prospecto existente
                    $updateBatch[] = array_merge($prospectoData, ['id' => $existingId]);
                    $actualizados++;
                } else {
                    // Nuevo prospecto
                    $createBatch[] = $prospectoData;
                    $nuevos++;

                    // Registrar en cache para detectar duplicados dentro del mismo sync
                    $this->cacheService->registerNewProspecto($email, $telefono);
                }

                $exitosos++;

                // Flush en batches
                if (count($createBatch) >= self::BATCH_SIZE) {
                    $this->insertBatch($createBatch);
                    $createBatch = [];
                }

                if (count($updateBatch) >= self::BATCH_SIZE) {
                    $this->updateBatch($updateBatch);
                    $updateBatch = [];
                }

            } catch (\Exception $e) {
                $fallidos++;
                $errores[] = ['index' => $index, 'error' => $e->getMessage()];
            }
        }

        // Flush final
        if (! empty($createBatch)) {
            $this->insertBatch($createBatch);
        }

        if (! empty($updateBatch)) {
            $this->updateBatch($updateBatch);
        }

        return [
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'nuevos' => $nuevos,
            'actualizados' => $actualizados,
            'omitidos_en_flujo' => $omitidosEnFlujo,
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
        $nombre = $this->getFieldValueWithConcat($row, $fieldMapping['nombre'] ?? 'nombre');
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

        // Normalizar teléfono (agregar +56 si es chileno)
        if (! empty($telefono)) {
            $telefono = $this->normalizarTelefono($telefono);
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
            'fila_excel' => null,
            'metadata' => json_encode([
                'source' => 'external_api',
                'synced_at' => $now->toISOString(),
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Normaliza un teléfono chileno al formato +56XXXXXXXXX.
     */
    private function normalizarTelefono(string $telefono): string
    {
        // Remover espacios, guiones, puntos
        $telefono = preg_replace('/[\s\-\.]/', '', $telefono);

        // Si ya tiene +56, está bien
        if (str_starts_with($telefono, '+56')) {
            return $telefono;
        }

        // Si empieza con 56, agregar +
        if (str_starts_with($telefono, '56') && strlen($telefono) >= 11) {
            return '+'.$telefono;
        }

        // Si es un número de 9 dígitos, agregar +56
        if (strlen($telefono) === 9 && $telefono[0] === '9') {
            return '+56'.$telefono;
        }

        // Dejar como está si no aplica ninguna regla
        return $telefono;
    }

    /**
     * Obtiene el valor de un campo, soportando notación con punto para campos anidados.
     */
    private function getFieldValue(array $row, ?string $field): mixed
    {
        if ($field === null) {
            return null;
        }

        $keys = explode('.', $field);
        $value = $row;

        foreach ($keys as $key) {
            if (! is_array($value) || ! isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Obtiene el valor de un campo, soportando concatenación con "|".
     * Ej: "user.name|user.lastname" -> "Juan Pérez"
     */
    private function getFieldValueWithConcat(array $row, ?string $field): ?string
    {
        if ($field === null) {
            return null;
        }

        // Si contiene "|", es una concatenación
        if (str_contains($field, '|')) {
            $parts = explode('|', $field);
            $values = [];

            foreach ($parts as $part) {
                $value = $this->getFieldValue($row, trim($part));
                if ($value !== null && $value !== '') {
                    $values[] = trim((string) $value);
                }
            }

            return empty($values) ? null : implode(' ', $values);
        }

        // Campo simple
        $value = $this->getFieldValue($row, $field);

        return $value !== null ? (string) $value : null;
    }

    /**
     * Carga todos los tipos de prospecto.
     */
    private function loadTiposProspecto(): Collection
    {
        return TipoProspecto::orderBy('monto_min', 'desc')->get();
    }

    /**
     * Resuelve el ID del tipo de prospecto basado en el monto.
     */
    private function resolveTipoProspectoId(int $monto, Collection $tiposProspecto): ?int
    {
        foreach ($tiposProspecto as $tipo) {
            if ($monto >= $tipo->monto_min && $monto <= $tipo->monto_max) {
                return $tipo->id;
            }
        }

        // Si no encuentra, usar el primero (fallback)
        return $tiposProspecto->first()?->id;
    }

    /**
     * Carga los IDs de prospectos que ya están en flujos activos.
     */
    private function loadProspectosEnFlujoActivo(): void
    {
        $this->prospectosEnFlujoActivo = DB::table('prospecto_en_flujo')
            ->join('flujos', 'prospecto_en_flujo.flujo_id', '=', 'flujos.id')
            ->where('flujos.activo', true)
            ->whereIn('prospecto_en_flujo.estado', ['pendiente', 'en_progreso'])
            ->pluck('prospecto_en_flujo.prospecto_id')
            ->flip()
            ->toArray();

        Log::info('ExternalApiSyncService: Cargados prospectos en flujos activos', [
            'count' => count($this->prospectosEnFlujoActivo),
        ]);
    }

    /**
     * Verifica si un prospecto está en un flujo activo.
     */
    private function estaEnFlujoActivo(int $prospectoId): bool
    {
        return isset($this->prospectosEnFlujoActivo[$prospectoId]);
    }

    /**
     * Inserta un batch de prospectos nuevos.
     */
    private function insertBatch(array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        try {
            DB::table('prospectos')->insert($batch);
        } catch (\Exception $e) {
            Log::warning('ExternalApiSyncService: Batch insert falló, insertando uno por uno', [
                'error' => $e->getMessage(),
                'count' => count($batch),
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
     * Actualiza un batch de prospectos existentes.
     */
    private function updateBatch(array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        try {
            DB::table('prospectos')->upsert(
                $batch,
                ['id'],
                ['nombre', 'email', 'telefono', 'rut', 'url_informe', 'monto_deuda', 'tipo_prospecto_id', 'updated_at']
            );
        } catch (\Exception $e) {
            Log::warning('ExternalApiSyncService: Batch upsert falló, actualizando uno por uno', [
                'error' => $e->getMessage(),
                'count' => count($batch),
            ]);

            foreach ($batch as $data) {
                $id = $data['id'];
                unset($data['id']);

                try {
                    DB::table('prospectos')->where('id', $id)->update($data);
                } catch (\Exception $individualError) {
                    Log::debug('ExternalApiSyncService: Update individual falló', [
                        'id' => $id,
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
                'nuevos' => $result['nuevos'],
                'actualizados' => $result['actualizados'],
                'omitidos_en_flujo' => $result['omitidos_en_flujo'],
                'errores' => array_slice($result['errores'], 0, 100),
                'completed_at' => now()->toISOString(),
            ]),
        ]);
    }

    /**
     * Detecta los valores únicos de un campo de clasificación.
     */
    private function detectarValoresClasificacion(array $data, string $field): array
    {
        $valores = [];

        foreach ($data as $row) {
            $valor = $this->getFieldValue($row, $field);
            if ($valor !== null && ! in_array($valor, $valores, true)) {
                $valores[] = $valor;
            }
        }

        return $valores;
    }
}
