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
 * Endpoints soportados:
 * - /ProspectosNoAgendados: Prospectos que entraron pero no agendaron cita
 * - /AgendadosNoCerrados: Prospectos que agendaron pero no contrataron
 *
 * Diferencias con ExternalApiSyncService:
 * - Usa POST en lugar de GET
 * - Envía fechas en el body (desde/hasta)
 * - Estructura de respuesta diferente (Estado, Total, Prospectos/Agendas)
 * - Auth por IP (no requiere token)
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
     * @param  ExternalApiSource  $source  La fuente a sincronizar
     * @param  int|null  $userId  ID del usuario que ejecuta (null = sistema)
     * @return array{lotes: array<Lote>, total_prospectos: int, nuevos: int, actualizados: int, omitidos_en_flujo: int}
     */
    public function sync(ExternalApiSource $source, ?int $userId = null): array
    {
        Log::info('SysgalApiSyncService: Iniciando sincronización', [
            'source' => $source->name,
            'endpoint' => $source->endpoint_url,
        ]);

        try {
            // 1. Cargar cache de prospectos existentes para deduplicación
            $this->cacheService->loadExistingProspectos();

            // 2. Cargar IDs de prospectos ya en flujos activos
            $this->loadProspectosEnFlujoActivo();

            // 3. Calcular rango de fechas
            $diasAtras = $source->sync_filters['dias_atras'] ?? 7;
            $hasta = now();
            $desde = now()->subDays($diasAtras);

            Log::info('SysgalApiSyncService: Rango de fechas', [
                'desde' => $desde->format('Y-m-d'),
                'hasta' => $hasta->format('Y-m-d'),
            ]);

            // 4. Fetch de la API
            $data = $this->fetchFromSysgal($source, $desde, $hasta);

            if (empty($data)) {
                Log::info('SysgalApiSyncService: No hay registros nuevos');

                return [
                    'lotes' => [],
                    'total_prospectos' => 0,
                    'nuevos' => 0,
                    'actualizados' => 0,
                    'omitidos_en_flujo' => 0,
                ];
            }

            // 5. Agrupar por clasificación si corresponde
            $grupos = $this->agruparPorClasificacion($data, $source);

            // 6. Procesar cada grupo
            $resultado = $this->procesarGrupos($grupos, $source, $userId ?? 1);

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
     * Llama a la API de Sysgal con POST y rango de fechas.
     */
    private function fetchFromSysgal(ExternalApiSource $source, \Carbon\Carbon $desde, \Carbon\Carbon $hasta): array
    {
        $url = $source->endpoint_url;
        $headers = $source->headers ?? [];

        // Determinar formato de fechas según endpoint
        $isAgendadosNoCerrados = str_contains($url, 'AgendadosNoCerrados');

        // AgendadosNoCerrados usa formato "YYYY-MM-DD HH:MM:SS"
        // ProspectosNoAgendados usa formato "YYYY-MM-DD"
        $body = $isAgendadosNoCerrados
            ? [
                'desde' => $desde->format('Y-m-d 00:00:00'),
                'hasta' => $hasta->format('Y-m-d 23:59:59'),
            ]
            : [
                'desde' => $desde->format('Y-m-d'),
                'hasta' => $hasta->format('Y-m-d'),
            ];

        Log::info('SysgalApiSyncService: Llamando a API', [
            'url' => $url,
            'body' => $body,
        ]);

        $response = Http::withHeaders($headers)
            ->timeout(120)
            ->post($url, $body);

        if (! $response->successful()) {
            throw new \Exception("Error HTTP {$response->status()}: {$response->body()}");
        }

        $json = $response->json();

        // Verificar respuesta de Sysgal
        if (($json['Estado'] ?? 0) !== 1) {
            $mensaje = $json['Mensaje'] ?? 'Error desconocido';
            throw new \Exception("Sysgal respondió con error: {$mensaje}");
        }

        // El array de datos puede estar en "Prospectos" o "Agendas" según el endpoint
        $data = $json['Prospectos'] ?? $json['Agendas'] ?? [];
        $total = $json['Total'] ?? count($data);

        Log::info('SysgalApiSyncService: Respuesta recibida', [
            'total' => $total,
            'registros' => count($data),
        ]);

        return $data;
    }

    /**
     * Agrupa los datos por valor de clasificación.
     */
    private function agruparPorClasificacion(array $data, ExternalApiSource $source): array
    {
        // Si no hay campo de clasificación, todo va a un grupo "default"
        if (! $source->tieneClasificacion()) {
            return ['default' => $data];
        }

        $grupos = [];
        $field = $source->clasificacion_field;

        foreach ($data as $row) {
            $valor = $this->getFieldValue($row, $field) ?? 'sin_clasificar';
            $valor = trim((string) $valor);

            // Normalizar valores de clasificación (quitar espacios extra)
            $valor = preg_replace('/\s+/', ' ', $valor);

            if (! isset($grupos[$valor])) {
                $grupos[$valor] = [];
            }

            $grupos[$valor][] = $row;
        }

        Log::info('SysgalApiSyncService: Datos agrupados por clasificación', [
            'field' => $field,
            'grupos' => array_map('count', $grupos),
        ]);

        return $grupos;
    }

    /**
     * Procesa cada grupo creando un lote y sus prospectos.
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
            // Sanitizar el nombre de clasificación para el lote
            $clasificacionSanitizada = $this->sanitizarClasificacion($clasificacionValue);

            // Crear o reutilizar lote para este valor de clasificación
            $lote = $this->obtenerOCrearLote($source, $clasificacionSanitizada, $userId);

            // Crear importación dentro del lote
            $importacion = $this->createImportacion($source, $lote, $userId, count($registros), $clasificacionValue);

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

            // Actualizar totales del lote y cerrar
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
     * Sanitiza el valor de clasificación para usarlo como nombre de lote.
     */
    private function sanitizarClasificacion(string $valor): string
    {
        // Convertir a minúsculas y reemplazar espacios/caracteres especiales
        $sanitizado = strtolower($valor);
        $sanitizado = str_replace(['/', '-', ' '], '_', $sanitizado);
        $sanitizado = preg_replace('/[^a-z0-9_]/', '', $sanitizado);
        $sanitizado = preg_replace('/_+/', '_', $sanitizado);
        $sanitizado = trim($sanitizado, '_');

        // Limitar longitud
        if (strlen($sanitizado) > 50) {
            $sanitizado = substr($sanitizado, 0, 50);
        }

        return $sanitizado ?: 'otros';
    }

    /**
     * Obtiene o crea un lote para Sysgal.
     */
    private function obtenerOCrearLote(ExternalApiSource $source, string $clasificacionValue, int $userId): Lote
    {
        $prefix = $source->lote_prefix ?: 'SG';
        $fecha = now()->format('Y-m-d');
        $nombreLote = "{$prefix}_{$clasificacionValue}_{$fecha}";

        return Lote::firstOrCreate(
            [
                'external_api_source_id' => $source->id,
                'clasificacion_value' => $clasificacionValue,
                'nombre' => $nombreLote,
            ],
            [
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
        int $totalRegistros,
        string $clasificacionOriginal
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
                'clasificacion_value' => $clasificacionOriginal,
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
                $prospectoData = $this->mapRowToProspecto($row, $fieldMapping, $importacion->id, $tiposProspecto, $source);

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
     * Mapea una fila de la API Sysgal a los campos de Prospecto.
     */
    private function mapRowToProspecto(
        array $row,
        array $fieldMapping,
        int $importacionId,
        Collection $tiposProspecto,
        ExternalApiSource $source
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

        // Sysgal no trae monto de deuda, usar el mínimo
        $tipoProspectoId = $tiposProspecto->first()?->id;

        if ($tipoProspectoId === null) {
            return null;
        }

        // Construir metadata con campos extra de Sysgal
        $metadata = [
            'source' => 'sysgal',
            'synced_at' => now()->toISOString(),
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
            'monto_deuda' => 0, // Sysgal no trae monto
            'fila_excel' => null,
            'metadata' => json_encode($metadata),
            'created_at' => $now,
            'updated_at' => $now,
        ];
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
                ['nombre', 'email', 'telefono', 'rut', 'updated_at']
            );
        } catch (\Exception $e) {
            Log::warning('SysgalApiSyncService: Batch upsert falló, actualizando uno por uno', [
                'error' => $e->getMessage(),
                'count' => count($batch),
            ]);

            foreach ($batch as $data) {
                $id = $data['id'];
                unset($data['id']);

                try {
                    DB::table('prospectos')->where('id', $id)->update($data);
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
                'actualizados' => $result['actualizados'],
                'omitidos_en_flujo' => $result['omitidos_en_flujo'],
                'errores' => array_slice($result['errores'], 0, 100),
                'completed_at' => now()->toISOString(),
            ]),
        ]);
    }

    /**
     * Prueba la conexión a una fuente Sysgal.
     */
    public function testConnection(ExternalApiSource $source): array
    {
        try {
            $hasta = now();
            $desde = now()->subDays(1); // Solo 1 día para test

            $isAgendadosNoCerrados = str_contains($source->endpoint_url, 'AgendadosNoCerrados');

            $body = $isAgendadosNoCerrados
                ? [
                    'desde' => $desde->format('Y-m-d 00:00:00'),
                    'hasta' => $hasta->format('Y-m-d 23:59:59'),
                ]
                : [
                    'desde' => $desde->format('Y-m-d'),
                    'hasta' => $hasta->format('Y-m-d'),
                ];

            $response = Http::withHeaders($source->headers ?? [])
                ->timeout(30)
                ->post($source->endpoint_url, $body);

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
