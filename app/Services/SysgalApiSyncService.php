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
 * Servicio para sincronizar prospectos desde la API de Sysgal (Defensoría).
 *
 * Soporta múltiples endpoints en una sola fuente:
 * - /ProspectosNoAgendados: Prospectos que entraron pero no agendaron cita
 * - /AgendadosNoCerrados: Prospectos que agendaron pero no contrataron
 *
 * Características:
 * - Usa POST con fechas en el body
 * - Auth por IP (no requiere token)
 * - Todos los prospectos van a un único lote "SYSGAL"
 * - Se clasifica por nivel de deuda (baja/media/alta) en metadata
 *
 * @example
 * $service = new SysgalApiSyncService();
 * $result = $service->sync($source, $userId);
 */
class SysgalApiSyncService
{
    private const BATCH_SIZE = 500;

    private ProspectoCacheService $cacheService;

    /** @var array<int, bool> IDs de prospectos ya en flujos activos */
    private array $prospectosEnFlujoActivo = [];

    public function __construct()
    {
        $this->cacheService = new ProspectoCacheService;
    }

    /**
     * Sincroniza prospectos desde una fuente Sysgal.
     *
     * Si la fuente tiene múltiples endpoints configurados en sync_filters['endpoints'],
     * itera sobre cada uno y combina los resultados.
     *
     * @param  ExternalApiSource  $source  La fuente a sincronizar
     * @param  int|null  $userId  ID del usuario que ejecuta (null = sistema)
     * @param  string|null  $endpointName  Opcional: sincronizar solo un endpoint específico
     * @return array{lotes: array<Lote>, total_prospectos: int, nuevos: int, actualizados: int, omitidos_en_flujo: int}
     */
    public function sync(ExternalApiSource $source, ?int $userId = null, ?string $endpointName = null): array
    {
        Log::info('SysgalApiSyncService: Iniciando sincronización', [
            'source' => $source->name,
            'endpoint_filter' => $endpointName,
        ]);

        try {
            // 1. Cargar cache de prospectos existentes para deduplicación
            $this->cacheService->loadExistingProspectos();

            // 2. Cargar IDs de prospectos ya en flujos activos
            $this->loadProspectosEnFlujoActivo();

            // 3. Obtener endpoints a sincronizar
            $endpoints = $this->getEndpoints($source, $endpointName);

            if (empty($endpoints)) {
                Log::warning('SysgalApiSyncService: No hay endpoints configurados', [
                    'source' => $source->name,
                ]);

                return $this->emptyResult();
            }

            // 4. Calcular rango de fechas
            $diasAtras = $source->sync_filters['dias_atras'] ?? 7;
            $hasta = now();
            $desde = now()->subDays($diasAtras);

            Log::info('SysgalApiSyncService: Rango de fechas', [
                'desde' => $desde->format('Y-m-d'),
                'hasta' => $hasta->format('Y-m-d'),
                'endpoints' => count($endpoints),
            ]);

            // 5. Sincronizar cada endpoint y combinar resultados
            $allData = [];
            foreach ($endpoints as $endpoint) {
                $data = $this->fetchFromEndpoint($endpoint, $desde, $hasta);
                $allData = array_merge($allData, $data);
            }

            if (empty($allData)) {
                Log::info('SysgalApiSyncService: No hay registros nuevos');

                return $this->emptyResult();
            }

            // 6. Procesar todos los datos en un único lote
            $resultado = $this->procesarDatos($allData, $source, $userId ?? 1);

            // 7. Marcar la fuente como sincronizada
            $source->markAsSynced($resultado['total_prospectos']);

            Log::info('SysgalApiSyncService: Sincronización completada', [
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

            Log::error('SysgalApiSyncService: Error en sincronización', [
                'source' => $source->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtiene los endpoints a sincronizar.
     *
     * Si la fuente tiene múltiples endpoints en sync_filters['endpoints'], los usa.
     * Si no, crea un endpoint virtual usando endpoint_url y field_mapping.
     *
     * @return array<array{name: string, url: string, field_mapping: array, date_format: string}>
     */
    private function getEndpoints(ExternalApiSource $source, ?string $endpointName = null): array
    {
        $syncFilters = $source->sync_filters ?? [];
        $endpoints = $syncFilters['endpoints'] ?? [];

        // Si hay endpoints configurados, usarlos
        if (! empty($endpoints)) {
            if ($endpointName !== null) {
                // Filtrar por nombre específico
                return array_filter($endpoints, fn ($e) => $e['name'] === $endpointName);
            }

            return $endpoints;
        }

        // Fallback: crear endpoint virtual desde la configuración legacy
        $isAgendadosNoCerrados = str_contains($source->endpoint_url, 'AgendadosNoCerrados');

        return [[
            'name' => $isAgendadosNoCerrados ? 'no_cerrados' : 'no_agendados',
            'url' => $source->endpoint_url,
            'display_name' => $source->display_name,
            'field_mapping' => $source->field_mapping ?? [],
            'date_format' => $isAgendadosNoCerrados ? 'Y-m-d H:i:s' : 'Y-m-d',
        ]];
    }

    /**
     * Llama a un endpoint específico de Sysgal.
     */
    private function fetchFromEndpoint(array $endpoint, \Carbon\Carbon $desde, \Carbon\Carbon $hasta): array
    {
        $url = $endpoint['url'];
        $dateFormat = $endpoint['date_format'] ?? 'Y-m-d';
        $endpointName = $endpoint['name'] ?? 'unknown';

        // Formatear fechas según el endpoint
        $body = $dateFormat === 'Y-m-d H:i:s'
            ? [
                'desde' => $desde->format('Y-m-d 00:00:00'),
                'hasta' => $hasta->format('Y-m-d 23:59:59'),
            ]
            : [
                'desde' => $desde->format('Y-m-d'),
                'hasta' => $hasta->format('Y-m-d'),
            ];

        Log::info('SysgalApiSyncService: Llamando a endpoint', [
            'name' => $endpointName,
            'url' => $url,
            'body' => $body,
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->timeout(120)
            ->post($url, $body);

        if (! $response->successful()) {
            throw new \Exception("Error HTTP {$response->status()} en {$endpointName}: {$response->body()}");
        }

        $json = $response->json();

        // Verificar respuesta de Sysgal
        if (($json['Estado'] ?? 0) !== 1) {
            $mensaje = $json['Mensaje'] ?? 'Error desconocido';
            throw new \Exception("Sysgal respondió con error en {$endpointName}: {$mensaje}");
        }

        // El array de datos puede estar en "Prospectos" o "Agendas" según el endpoint
        $data = $json['Prospectos'] ?? $json['Agendas'] ?? [];
        $total = $json['Total'] ?? count($data);

        Log::info('SysgalApiSyncService: Respuesta recibida', [
            'endpoint' => $endpointName,
            'total' => $total,
            'registros' => count($data),
        ]);

        // Agregar metadata del endpoint a cada registro
        foreach ($data as &$row) {
            $row['_endpoint'] = $endpointName;
            $row['_field_mapping'] = $endpoint['field_mapping'] ?? [];
        }

        return $data;
    }

    /**
     * Procesa todos los datos en un único lote SYSGAL.
     */
    private function procesarDatos(array $data, ExternalApiSource $source, int $userId): array
    {
        $syncFilters = $source->sync_filters ?? [];
        $loteNombre = $syncFilters['lote_global'] ?? 'SYSGAL';

        // Obtener o crear el lote único
        $lote = $this->obtenerOCrearLoteGlobal($source, $loteNombre, $userId);

        // Crear importación
        $importacion = $this->createImportacion($source, $lote, $userId, count($data));

        // Cargar tipos de prospecto
        $tiposProspecto = $this->loadTiposProspecto();

        // Procesar prospectos
        $resultado = $this->processProspectos(
            $importacion,
            $data,
            $tiposProspecto,
            $source
        );

        // Finalizar importación
        $this->finalizeImportacion($importacion, $resultado);

        // Actualizar totales del lote
        $lote->recalcularTotales();

        return [
            'lotes' => [$lote],
            'total_prospectos' => $resultado['exitosos'],
            'nuevos' => $resultado['nuevos'],
            'actualizados' => $resultado['actualizados'],
            'omitidos_en_flujo' => $resultado['omitidos_en_flujo'],
        ];
    }

    /**
     * Obtiene o crea el lote global para Sysgal.
     */
    private function obtenerOCrearLoteGlobal(ExternalApiSource $source, string $nombre, int $userId): Lote
    {
        return Lote::firstOrCreate(
            ['nombre' => $nombre],
            [
                'external_api_source_id' => $source->id,
                'clasificacion_value' => 'global',
                'user_id' => $userId,
                'estado' => 'abierto',
            ]
        );
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
                'synced_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Procesa los prospectos con deduplicación y exclusión de flujos activos.
     */
    private function processProspectos(
        Importacion $importacion,
        array $data,
        Collection $tiposProspecto,
        ExternalApiSource $source
    ): array {
        $exitosos = 0;
        $fallidos = 0;
        $nuevos = 0;
        $actualizados = 0;
        $omitidosEnFlujo = 0;
        $errores = [];
        $nuevosPorNivel = ['baja' => 0, 'media' => 0, 'alta' => 0];

        $createBatch = [];
        $updateBatch = [];

        foreach ($data as $index => $row) {
            try {
                // Obtener field_mapping específico del endpoint o el default
                $fieldMapping = $row['_field_mapping'] ?? $source->getFieldMappingWithDefaults();
                $endpointName = $row['_endpoint'] ?? 'unknown';

                $prospectoData = $this->mapRowToProspecto(
                    $row,
                    $fieldMapping,
                    $importacion->id,
                    $tiposProspecto,
                    $endpointName
                );

                if ($prospectoData === null) {
                    $fallidos++;
                    $errores[] = ['index' => $index, 'error' => 'Datos insuficientes (sin nombre o sin email/teléfono válido)'];

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

                    // Contar nuevos por nivel de deuda
                    $nivelDeuda = $prospectoData['metadata']['nivel_deuda'] ?? null;
                    if ($nivelDeuda === 'alta') {
                        $nuevosPorNivel['alta'] = ($nuevosPorNivel['alta'] ?? 0) + 1;
                    } elseif ($nivelDeuda === 'media') {
                        $nuevosPorNivel['media'] = ($nuevosPorNivel['media'] ?? 0) + 1;
                    } else {
                        // baja, sin_informacion, null → todos van a "baja"
                        $nuevosPorNivel['baja'] = ($nuevosPorNivel['baja'] ?? 0) + 1;
                    }

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
            'nuevos_por_nivel' => $nuevosPorNivel,
            'actualizados' => $actualizados,
            'omitidos_en_flujo' => $omitidosEnFlujo,
            'errores' => $errores,
        ];
    }

    /**
     * Mapea una fila de la API Sysgal a los campos de Prospecto.
     */
    private function mapRowToProspecto(
        array $row,
        array $fieldMapping,
        int $importacionId,
        Collection $tiposProspecto,
        string $endpointName
    ): ?array {
        // Obtener valores según el mapping
        $nombre = $this->getFieldValue($row, $fieldMapping['nombre'] ?? 'Nombre');
        $rut = $this->getFieldValue($row, $fieldMapping['rut'] ?? 'Rut');
        $email = $this->getFieldValue($row, $fieldMapping['email'] ?? 'Email');
        $telefono = $this->getFieldValue($row, $fieldMapping['telefono'] ?? 'Telefono');

        // Limpiar nombre (quitar espacios extra)
        if ($nombre) {
            $nombre = trim(preg_replace('/\s+/', ' ', $nombre));
        }

        // Validar datos mínimos
        if (empty($nombre)) {
            return null;
        }

        // Validar email (Sysgal tiene emails inválidos como "s@c", "N@G", "SIN@CORREO")
        if (! empty($email)) {
            $email = strtolower(trim($email));
            if (! $this->isValidEmail($email)) {
                $email = null;
            }
        }

        // Normalizar teléfono
        if (! empty($telefono)) {
            $telefono = $this->normalizarTelefono($telefono);
        }

        // Necesitamos al menos email o teléfono válido
        if (empty($email) && empty($telefono)) {
            return null;
        }

        // Normalizar RUT (Sysgal trae formato "XX.XXX.XXX-X")
        if (! empty($rut) && $rut !== '0' && $rut !== 0) {
            $rut = $this->normalizarRut($rut);
        } else {
            $rut = null;
        }

        // Obtener monto de deuda desde la API
        $montoDeudaRaw = $this->getFieldValue($row, $fieldMapping['monto_deuda'] ?? null);
        $montoDeuda = $this->parsearMontoDeuda($montoDeudaRaw);

        // Calcular nivel de deuda para clasificación
        $nivelDeuda = self::calcularNivelDeuda($montoDeuda);

        // Determinar tipo de prospecto basado en el monto de deuda
        $tipoProspectoId = $this->determinarTipoProspecto($tiposProspecto, $montoDeuda);

        if ($tipoProspectoId === null) {
            return null;
        }

        // Construir metadata con campos extra de Sysgal
        $metadata = [
            'source' => 'sysgal',
            'endpoint' => $endpointName,
            'synced_at' => now()->toISOString(),
            'nivel_deuda' => $nivelDeuda,
        ];

        // Agregar campos extra según el mapping
        foreach ($fieldMapping as $key => $apiField) {
            if (! in_array($key, ['nombre', 'rut', 'email', 'telefono', 'monto_deuda', 'url_informe'])) {
                $value = $this->getFieldValue($row, $apiField);
                if ($value !== null) {
                    $metadata[$key] = $value;
                }
            }
        }

        $now = now();

        return [
            'importacion_id' => $importacionId,
            'nombre' => $nombre,
            'rut' => $rut,
            'email' => $email,
            'telefono' => $telefono,
            'url_informe' => null,
            'tipo_prospecto_id' => $tipoProspectoId,
            'estado' => 'activo',
            'monto_deuda' => $montoDeuda,
            'fila_excel' => null,
            'metadata' => json_encode($metadata),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Parsea el monto de deuda desde el valor de la API.
     * El valor puede venir como string "2500400" o como número.
     */
    private function parsearMontoDeuda(mixed $valor): int
    {
        if ($valor === null || $valor === '' || $valor === '0') {
            return 0;
        }

        // Si es string, limpiar caracteres no numéricos (puntos, comas, espacios)
        if (is_string($valor)) {
            $valor = preg_replace('/[^0-9]/', '', $valor);
        }

        return (int) $valor;
    }

    /**
     * Calcula el nivel de deuda basado en el monto.
     *
     * Rangos:
     * - baja: < $700.000 CLP
     * - media: $700.000 - $1.500.000 CLP
     * - alta: > $1.500.000 CLP
     *
     * Método público estático para poder reutilizarlo en comandos de migración.
     */
    public static function calcularNivelDeuda(int|float $monto): string
    {
        if ($monto <= 0) {
            return 'sin_informacion';
        }

        return match (true) {
            $monto < 700000 => 'baja',
            $monto < 1500000 => 'media',
            default => 'alta',
        };
    }

    /**
     * Determina el tipo de prospecto basado en el monto de deuda.
     * Busca el TipoProspecto cuyo rango contenga el monto.
     */
    private function determinarTipoProspecto(Collection $tiposProspecto, int $montoDeuda): ?int
    {
        // Si no hay monto, usar el primer tipo (default)
        if ($montoDeuda <= 0) {
            return $tiposProspecto->first()?->id;
        }

        // Buscar el tipo cuyo rango contenga el monto
        foreach ($tiposProspecto as $tipo) {
            $min = $tipo->monto_min ?? 0;
            $max = $tipo->monto_max ?? PHP_INT_MAX;

            if ($montoDeuda >= $min && $montoDeuda <= $max) {
                return $tipo->id;
            }
        }

        // Fallback al primer tipo si no hay match
        return $tiposProspecto->first()?->id;
    }

    /**
     * Valida si un email es válido (no es placeholder de Sysgal).
     */
    private function isValidEmail(string $email): bool
    {
        // Emails inválidos conocidos de Sysgal
        $invalidos = ['s@c', 'n@g', 'sin@correo', 'sc@sc.cl', 'sin@correo.cl', 'no@tiene.cl'];

        if (in_array($email, $invalidos, true)) {
            return false;
        }

        // Validación básica de formato
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Emails demasiado cortos probablemente son inválidos
        if (strlen($email) < 6) {
            return false;
        }

        return true;
    }

    /**
     * Normaliza un RUT chileno.
     */
    private function normalizarRut(mixed $rut): ?string
    {
        if (empty($rut) || $rut === '0') {
            return null;
        }

        $rut = (string) $rut;

        // Remover puntos y espacios, mantener guión
        $rut = str_replace(['.', ' '], '', $rut);

        // Convertir a mayúsculas (para la K)
        $rut = strtoupper($rut);

        // Validar formato básico
        if (! preg_match('/^\d{1,8}-[\dK]$/', $rut)) {
            return null;
        }

        return $rut;
    }

    /**
     * Normaliza un teléfono chileno al formato +56XXXXXXXXX.
     */
    private function normalizarTelefono(string $telefono): ?string
    {
        // Remover espacios, guiones, puntos, paréntesis
        $telefono = preg_replace('/[\s\-\.\(\)]/', '', $telefono);

        // Si está vacío después de limpiar
        if (empty($telefono)) {
            return null;
        }

        // Si ya tiene +56, está bien
        if (str_starts_with($telefono, '+56')) {
            return $telefono;
        }

        // Si empieza con 56, agregar +
        if (str_starts_with($telefono, '56') && strlen($telefono) >= 11) {
            return '+'.$telefono;
        }

        // Si es un número de 9 dígitos que empieza con 9, agregar +56
        if (strlen($telefono) === 9 && $telefono[0] === '9') {
            return '+56'.$telefono;
        }

        // Si no cumple el formato chileno, retornar null
        return null;
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
     * Carga todos los tipos de prospecto.
     */
    private function loadTiposProspecto(): Collection
    {
        return TipoProspecto::orderBy('monto_min', 'asc')->get();
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

        Log::info('SysgalApiSyncService: Cargados prospectos en flujos activos', [
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
            Log::warning('SysgalApiSyncService: Batch insert falló, insertando uno por uno', [
                'error' => $e->getMessage(),
                'count' => count($batch),
            ]);

            foreach ($batch as $prospecto) {
                try {
                    DB::table('prospectos')->insert($prospecto);
                } catch (\Exception $individualError) {
                    Log::debug('SysgalApiSyncService: Insert individual falló', [
                        'email' => $prospecto['email'] ?? 'N/A',
                        'error' => $individualError->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Actualiza un batch de prospectos existentes.
     *
     * IMPORTANTE: Incluimos metadata y tipo_prospecto_id para que el sync
     * actualice nivel_deuda y monto_deuda en prospectos existentes.
     */
    private function updateBatch(array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        // Dedup por id (keep last): si el batch trae 2 filas del MISMO id, el upsert tira
        // "cardinality violation: ON CONFLICT DO UPDATE cannot affect row a second time",
        // cae al fallback per-row y ahí se pisaba created_at/importacion_id. Evitarlo de raíz.
        $batch = array_values(collect($batch)->keyBy('id')->all());

        // Columnas que un UPDATE NUNCA debe tocar: created_at (fecha de ingreso real) e
        // importacion_id (lote de origen). Mismo set para upsert y fallback.
        $updatable = ['nombre', 'email', 'telefono', 'rut', 'monto_deuda', 'tipo_prospecto_id', 'metadata', 'updated_at'];

        try {
            DB::table('prospectos')->upsert($batch, ['id'], $updatable);
        } catch (\Exception $e) {
            Log::warning('SysgalApiSyncService: Batch upsert falló, actualizando uno por uno', [
                'error' => $e->getMessage(),
                'count' => count($batch),
            ]);

            foreach ($batch as $data) {
                $id = $data['id'];
                // Solo columnas actualizables: NO pisar created_at ni importacion_id.
                $payload = array_intersect_key($data, array_flip($updatable));

                try {
                    DB::table('prospectos')->where('id', $id)->update($payload);
                } catch (\Exception $individualError) {
                    Log::debug('SysgalApiSyncService: Update individual falló', [
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
                'nuevos_por_nivel' => $result['nuevos_por_nivel'] ?? null,
                'actualizados' => $result['actualizados'],
                'omitidos_en_flujo' => $result['omitidos_en_flujo'],
                'errores' => array_slice($result['errores'], 0, 100),
                'completed_at' => now()->toISOString(),
            ]),
        ]);
    }

    /**
     * Retorna un resultado vacío.
     */
    private function emptyResult(): array
    {
        return [
            'lotes' => [],
            'total_prospectos' => 0,
            'nuevos' => 0,
            'actualizados' => 0,
            'omitidos_en_flujo' => 0,
        ];
    }

    /**
     * Prueba la conexión a una fuente Sysgal.
     *
     * Si tiene múltiples endpoints, prueba cada uno.
     */
    public function testConnection(ExternalApiSource $source): array
    {
        $endpoints = $this->getEndpoints($source);

        if (empty($endpoints)) {
            return [
                'success' => false,
                'message' => 'No hay endpoints configurados',
            ];
        }

        $results = [];
        $allSuccess = true;
        $totalRecords = 0;

        foreach ($endpoints as $endpoint) {
            $result = $this->testSingleEndpoint($endpoint);
            $results[$endpoint['name']] = $result;

            if (! $result['success']) {
                $allSuccess = false;
            } else {
                $totalRecords += $result['sample_count'] ?? 0;
            }
        }

        return [
            'success' => $allSuccess,
            'message' => $allSuccess ? 'Todos los endpoints conectados' : 'Algunos endpoints fallaron',
            'endpoints' => $results,
            'total_records' => $totalRecords,
        ];
    }

    /**
     * Prueba un endpoint individual.
     */
    private function testSingleEndpoint(array $endpoint): array
    {
        try {
            $hasta = now();
            $desde = now()->subDays(1); // Solo 1 día para test
            $dateFormat = $endpoint['date_format'] ?? 'Y-m-d';

            $body = $dateFormat === 'Y-m-d H:i:s'
                ? [
                    'desde' => $desde->format('Y-m-d 00:00:00'),
                    'hasta' => $hasta->format('Y-m-d 23:59:59'),
                ]
                : [
                    'desde' => $desde->format('Y-m-d'),
                    'hasta' => $hasta->format('Y-m-d'),
                ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post($endpoint['url'], $body);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => "Error HTTP {$response->status()}: {$response->body()}",
                ];
            }

            $json = $response->json();

            if (($json['Estado'] ?? 0) !== 1) {
                return [
                    'success' => false,
                    'message' => 'Sysgal respondió con error: '.($json['Mensaje'] ?? 'desconocido'),
                ];
            }

            $data = $json['Prospectos'] ?? $json['Agendas'] ?? [];

            return [
                'success' => true,
                'message' => 'Conexión exitosa',
                'sample_count' => count($data),
                'total' => $json['Total'] ?? count($data),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error de conexión: {$e->getMessage()}",
            ];
        }
    }
}
