<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExternalApiSource;
use App\Models\Importacion;
use App\Models\Lote;
use App\Models\TipoProspecto;
use App\Services\Import\ProspectoCacheService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para sincronizar prospectos desde la API de Grupo Deudas.
 *
 * Endpoints soportados:
 * - /ContratosNuevos: Contratos nuevos (sync incremental)
 * - /CuotasPorVencer: Cuotas que vencen hoy (sync diario)
 * - /CuotasVencidas: Cuotas vencidas hoy - morosos (sync diario)
 * - /ClientesPorFechaIngreso: Clientes que firmaron contrato hoy (sync diario)
 *
 * Características:
 * - Usa POST con rango de fechas en el body
 * - Auth por IP (no requiere token)
 * - Cada endpoint tiene su propio lote
 *
 * @example
 * $service = new GrupoDeudaApiSyncService();
 * $result = $service->sync($source, $userId); // ContratosNuevos
 * $result = $service->syncCuotasPorVencer($source, $userId);
 * $result = $service->syncCuotasVencidas($source, $userId);
 * $result = $service->syncClientesPorFechaIngreso($source, $userId);
 */
class GrupoDeudaApiSyncService
{
    private const BATCH_SIZE = 500;

    // Constantes de endpoints
    public const ENDPOINT_CONTRATOS_NUEVOS = 'contratos';

    public const ENDPOINT_CUOTAS_POR_VENCER = 'cuotas-vencer';

    public const ENDPOINT_CUOTAS_VENCIDAS = 'cuotas-vencidas';

    public const ENDPOINT_CLIENTES_INGRESO = 'clientes-ingreso';

    private ProspectoCacheService $cacheService;

    /** @var array<int, bool> IDs de prospectos ya en flujos activos */
    private array $prospectosEnFlujoActivo = [];

    public function __construct()
    {
        $this->cacheService = new ProspectoCacheService;
    }

    /**
     * Sincroniza contratos nuevos desde Grupo Deudas.
     *
     * Usa last_synced_at para sync incremental.
     * Si last_synced_at es null, trae últimas 24 horas.
     *
     * @param  ExternalApiSource  $source  La fuente a sincronizar
     * @param  int|null  $userId  ID del usuario que ejecuta (null = sistema)
     * @return array{lotes: array<Lote>, total_prospectos: int, nuevos: int, actualizados: int, omitidos_en_flujo: int}
     */
    public function sync(ExternalApiSource $source, ?int $userId = null): array
    {
        Log::info('GrupoDeudaApiSyncService: Iniciando sincronización', [
            'source' => $source->name,
            'last_synced_at' => $source->last_synced_at?->toISOString(),
        ]);

        try {
            // 1. Cargar cache de prospectos existentes para deduplicación
            $this->cacheService->loadExistingProspectos();

            // 2. Cargar IDs de prospectos ya en flujos activos
            $this->loadProspectosEnFlujoActivo();

            // 3. Calcular rango de fechas (incremental)
            $hasta = now();
            $desde = $source->last_synced_at ?? now()->subHours(24);

            Log::info('GrupoDeudaApiSyncService: Rango de fechas', [
                'desde' => $desde->format('Y-m-d H:i:s'),
                'hasta' => $hasta->format('Y-m-d H:i:s'),
            ]);

            // 4. Llamar a la API
            $data = $this->fetchContratos($source, $desde, $hasta);

            if (empty($data)) {
                Log::info('GrupoDeudaApiSyncService: No hay contratos nuevos');

                // Igual marcar como sincronizado
                $source->markAsSynced(0);

                return $this->emptyResult();
            }

            // 5. Procesar los datos en un único lote
            $resultado = $this->procesarDatos($data, $source, $userId ?? 1);

            // 6. Marcar la fuente como sincronizada
            $source->markAsSynced($resultado['total_prospectos']);

            Log::info('GrupoDeudaApiSyncService: Sincronización completada', [
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

            Log::error('GrupoDeudaApiSyncService: Error en sincronización', [
                'source' => $source->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Llama a la API de ContratosNuevos.
     */
    private function fetchContratos(ExternalApiSource $source, Carbon $desde, Carbon $hasta): array
    {
        $url = $source->endpoint_url;

        $body = [
            'desde' => $desde->format('Y-m-d H:i:s'),
            'hasta' => $hasta->format('Y-m-d H:i:s'),
        ];

        Log::info('GrupoDeudaApiSyncService: Llamando a API', [
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
            throw new \Exception("Error HTTP {$response->status()}: {$response->body()}");
        }

        $json = $response->json();

        // Verificar respuesta
        if (($json['Estado'] ?? 0) !== 1) {
            $mensaje = $json['Mensaje'] ?? 'Error desconocido';
            throw new \Exception("API respondió con error: {$mensaje}");
        }

        $data = $json['Contratos'] ?? [];
        $total = $json['Total'] ?? count($data);

        Log::info('GrupoDeudaApiSyncService: Respuesta recibida', [
            'total' => $total,
            'contratos' => count($data),
        ]);

        return $data;
    }

    /**
     * Procesa todos los datos en un único lote.
     */
    private function procesarDatos(array $data, ExternalApiSource $source, int $userId): array
    {
        $syncFilters = $source->sync_filters ?? [];
        $loteNombre = $syncFilters['lote_global'] ?? 'CONTRATOS_NUEVOS';

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
     * Obtiene o crea el lote global para Grupo Deudas.
     */
    private function obtenerOCrearLoteGlobal(ExternalApiSource $source, string $nombre, int $userId): Lote
    {
        return Lote::firstOrCreate(
            ['nombre' => $nombre],
            [
                'external_api_source_id' => $source->id,
                'clasificacion_value' => 'contratos',
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

        $createBatch = [];
        $updateBatch = [];

        foreach ($data as $index => $row) {
            try {
                $fieldMapping = $source->getFieldMappingWithDefaults();

                $prospectoData = $this->mapRowToProspecto(
                    $row,
                    $fieldMapping,
                    $importacion->id,
                    $tiposProspecto
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
     */
    private function mapRowToProspecto(
        array $row,
        array $fieldMapping,
        int $importacionId,
        Collection $tiposProspecto
    ): ?array {
        // Obtener valores según el mapping
        $nombre = $this->getFieldValue($row, $fieldMapping['nombre'] ?? 'Cliente');
        $rut = $this->getFieldValue($row, $fieldMapping['rut'] ?? 'Rut');
        $email = $this->getFieldValue($row, $fieldMapping['email'] ?? 'Email');
        $telefono = $this->getFieldValue($row, $fieldMapping['telefono'] ?? 'Telefono');
        $montoDeudaRaw = $this->getFieldValue($row, $fieldMapping['monto_deuda'] ?? 'Monto');

        // Limpiar nombre (quitar espacios extra)
        if ($nombre) {
            $nombre = trim(preg_replace('/\s+/', ' ', $nombre));
        }

        // Validar datos mínimos
        if (empty($nombre)) {
            return null;
        }

        // Validar email
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

        // Normalizar RUT (formato "XX.XXX.XXX-X" -> "XXXXXXXX-X")
        if (! empty($rut) && $rut !== '0' && $rut !== 0) {
            $rut = $this->normalizarRut($rut);
        } else {
            $rut = null;
        }

        // Parsear monto de deuda
        $montoDeuda = $this->parsearMontoDeuda($montoDeudaRaw);

        // Determinar tipo de prospecto basado en el monto
        $tipoProspectoId = $this->determinarTipoProspecto($tiposProspecto, $montoDeuda);

        if ($tipoProspectoId === null) {
            return null;
        }

        // Construir metadata con campos extra del contrato
        $metadata = [
            'source' => 'grupo_deuda',
            'endpoint' => 'contratos_nuevos',
            'synced_at' => now()->toISOString(),
            'contrato_id' => $row['Id'] ?? null,
            'cuotas' => $row['Cuotas'] ?? null,
            'vigencia' => $row['Vigencia'] ?? null,
            'vendedor' => $row['Vendedor'] ?? null,
        ];

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
     */
    private function parsearMontoDeuda(mixed $valor): int
    {
        if ($valor === null || $valor === '' || $valor === '0') {
            return 0;
        }

        // Si es string, limpiar caracteres no numéricos
        if (is_string($valor)) {
            $valor = preg_replace('/[^0-9]/', '', $valor);
        }

        return (int) $valor;
    }

    /**
     * Determina el tipo de prospecto basado en el monto de deuda.
     */
    private function determinarTipoProspecto(Collection $tiposProspecto, int $montoDeuda): ?int
    {
        if ($montoDeuda <= 0) {
            return $tiposProspecto->first()?->id;
        }

        foreach ($tiposProspecto as $tipo) {
            $min = $tipo->monto_min ?? 0;
            $max = $tipo->monto_max ?? PHP_INT_MAX;

            if ($montoDeuda >= $min && $montoDeuda <= $max) {
                return $tipo->id;
            }
        }

        return $tiposProspecto->first()?->id;
    }

    /**
     * Valida si un email es válido.
     */
    private function isValidEmail(string $email): bool
    {
        // Emails inválidos conocidos
        $invalidos = ['s@c', 'n@g', 'sin@correo', 'sc@sc.cl', 'sin@correo.cl', 'no@tiene.cl', 's@c.cl', 'notiene@notiene.cl'];

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

        Log::info('GrupoDeudaApiSyncService: Cargados prospectos en flujos activos', [
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
            Log::warning('GrupoDeudaApiSyncService: Batch insert falló, insertando uno por uno', [
                'error' => $e->getMessage(),
                'count' => count($batch),
            ]);

            foreach ($batch as $prospecto) {
                try {
                    DB::table('prospectos')->insert($prospecto);
                } catch (\Exception $individualError) {
                    Log::debug('GrupoDeudaApiSyncService: Insert individual falló', [
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
                [
                    'nombre',
                    'email',
                    'telefono',
                    'rut',
                    'monto_deuda',
                    'tipo_prospecto_id',
                    'metadata',
                    'updated_at',
                ]
            );
        } catch (\Exception $e) {
            Log::warning('GrupoDeudaApiSyncService: Batch upsert falló, actualizando uno por uno', [
                'error' => $e->getMessage(),
                'count' => count($batch),
            ]);

            foreach ($batch as $data) {
                $id = $data['id'];
                unset($data['id']);

                try {
                    DB::table('prospectos')->where('id', $id)->update($data);
                } catch (\Exception $individualError) {
                    Log::debug('GrupoDeudaApiSyncService: Update individual falló', [
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
     * Prueba la conexión a la API de Grupo Deudas.
     */
    public function testConnection(ExternalApiSource $source): array
    {
        try {
            $hasta = now();
            $desde = now()->subHours(24);

            $body = [
                'desde' => $desde->format('Y-m-d H:i:s'),
                'hasta' => $hasta->format('Y-m-d H:i:s'),
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
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
                    'message' => 'API respondió con error: '.($json['Mensaje'] ?? 'desconocido'),
                ];
            }

            $data = $json['Contratos'] ?? [];

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

    /**
     * Sincroniza cuotas por vencer (que vencen hoy).
     *
     * Siempre trae datos del día actual (00:00:00 a 23:59:59).
     * No es sync incremental.
     */
    public function syncCuotasPorVencer(ExternalApiSource $source, ?int $userId = null): array
    {
        return $this->syncCuotasEndpoint($source, $userId, 'CuotasPorVencer', 'CUOTAS_POR_VENCER');
    }

    /**
     * Sincroniza cuotas vencidas (morosos de hoy).
     *
     * Siempre trae datos del día actual (00:00:00 a 23:59:59).
     * No es sync incremental.
     */
    public function syncCuotasVencidas(ExternalApiSource $source, ?int $userId = null): array
    {
        return $this->syncCuotasEndpoint($source, $userId, 'CuotasVencidas', 'CUOTAS_VENCIDAS');
    }

    /**
     * Método común para sincronizar endpoints de cuotas.
     */
    private function syncCuotasEndpoint(ExternalApiSource $source, ?int $userId, string $endpointPath, string $loteNombre): array
    {
        Log::info("GrupoDeudaApiSyncService: Iniciando sincronización {$endpointPath}", [
            'source' => $source->name,
        ]);

        try {
            $this->cacheService->loadExistingProspectos();
            $this->loadProspectosEnFlujoActivo();

            // Siempre usa fecha del día actual
            $desde = now()->startOfDay();
            $hasta = now()->endOfDay();

            Log::info("GrupoDeudaApiSyncService: Rango de fechas {$endpointPath}", [
                'desde' => $desde->format('Y-m-d H:i:s'),
                'hasta' => $hasta->format('Y-m-d H:i:s'),
            ]);

            $data = $this->fetchCuotasEndpoint($source, $endpointPath, $desde, $hasta);

            if (empty($data)) {
                Log::info("GrupoDeudaApiSyncService: No hay datos en {$endpointPath}");
                $source->markAsSynced(0);

                return $this->emptyResult();
            }

            $resultado = $this->procesarDatosCuotas($data, $source, $userId ?? 1, $loteNombre, $endpointPath);

            $source->markAsSynced($resultado['total_prospectos']);

            Log::info("GrupoDeudaApiSyncService: Sincronización {$endpointPath} completada", [
                'source' => $source->name,
                'total_prospectos' => $resultado['total_prospectos'],
                'nuevos' => $resultado['nuevos'],
                'actualizados' => $resultado['actualizados'],
            ]);

            return $resultado;

        } catch (\Exception $e) {
            $source->markAsFailed($e->getMessage());

            Log::error("GrupoDeudaApiSyncService: Error en {$endpointPath}", [
                'source' => $source->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Llama a un endpoint de cuotas (CuotasPorVencer o CuotasVencidas).
     */
    private function fetchCuotasEndpoint(ExternalApiSource $source, string $endpointPath, Carbon $desde, Carbon $hasta): array
    {
        // Construir URL con el endpoint correcto
        $baseUrl = dirname($source->endpoint_url);
        $url = $baseUrl.'/'.$endpointPath;

        $body = [
            'desde' => $desde->format('Y-m-d H:i:s'),
            'hasta' => $hasta->format('Y-m-d H:i:s'),
        ];

        Log::info("GrupoDeudaApiSyncService: Llamando a API {$endpointPath}", [
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
            throw new \Exception("Error HTTP {$response->status()}: {$response->body()}");
        }

        $json = $response->json();

        if (($json['Estado'] ?? 0) !== 1) {
            $mensaje = $json['Mensaje'] ?? 'Error desconocido';
            throw new \Exception("API respondió con error: {$mensaje}");
        }

        // El response de Cuotas tiene "Clientes" como objeto (no array)
        $clientes = $json['Clientes'] ?? [];

        Log::info("GrupoDeudaApiSyncService: Respuesta {$endpointPath} recibida", [
            'total_clientes' => $json['Total_Clientes'] ?? 0,
            'total_cuotas' => $json['Total_Cuotas'] ?? 0,
        ]);

        return $clientes;
    }

    /**
     * Procesa datos del endpoint de cuotas (objeto de clientes).
     */
    private function procesarDatosCuotas(array $clientes, ExternalApiSource $source, int $userId, string $loteNombre, string $endpointPath): array
    {
        $lote = $this->obtenerOCrearLoteGlobal($source, $loteNombre, $userId);
        $importacion = $this->createImportacion($source, $lote, $userId, count($clientes));
        $tiposProspecto = $this->loadTiposProspecto();

        $exitosos = 0;
        $fallidos = 0;
        $nuevos = 0;
        $actualizados = 0;
        $omitidosEnFlujo = 0;
        $errores = [];

        $createBatch = [];
        $updateBatch = [];

        // Clientes viene como objeto asociativo, no como array
        foreach ($clientes as $clienteId => $cliente) {
            try {
                $prospectoData = $this->mapCuotasClienteToProspecto(
                    $cliente,
                    $importacion->id,
                    $tiposProspecto,
                    $endpointPath
                );

                if ($prospectoData === null) {
                    $fallidos++;
                    $errores[] = ['cliente_id' => $clienteId, 'error' => 'Datos insuficientes'];

                    continue;
                }

                $email = $prospectoData['email'];
                $telefono = $prospectoData['telefono'];

                $existingId = $this->cacheService->findExistingProspectoId($email, $telefono);

                if ($existingId !== null) {
                    if ($this->estaEnFlujoActivo($existingId)) {
                        $omitidosEnFlujo++;

                        continue;
                    }

                    $updateBatch[] = array_merge($prospectoData, ['id' => $existingId]);
                    $actualizados++;
                } else {
                    $createBatch[] = $prospectoData;
                    $nuevos++;
                    $this->cacheService->registerNewProspecto($email, $telefono);
                }

                $exitosos++;

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
                $errores[] = ['cliente_id' => $clienteId, 'error' => $e->getMessage()];
            }
        }

        if (! empty($createBatch)) {
            $this->insertBatch($createBatch);
        }

        if (! empty($updateBatch)) {
            $this->updateBatch($updateBatch);
        }

        $this->finalizeImportacion($importacion, [
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'nuevos' => $nuevos,
            'actualizados' => $actualizados,
            'omitidos_en_flujo' => $omitidosEnFlujo,
            'errores' => $errores,
        ]);

        $lote->recalcularTotales();

        return [
            'lotes' => [$lote],
            'total_prospectos' => $exitosos,
            'nuevos' => $nuevos,
            'actualizados' => $actualizados,
            'omitidos_en_flujo' => $omitidosEnFlujo,
        ];
    }

    /**
     * Mapea un cliente del endpoint de Cuotas a Prospecto.
     */
    private function mapCuotasClienteToProspecto(
        array $cliente,
        int $importacionId,
        Collection $tiposProspecto,
        string $endpointPath
    ): ?array {
        $nombre = trim(implode(' ', array_filter([
            $cliente['Nombre'] ?? '',
            $cliente['Apellido_Paterno'] ?? '',
            $cliente['Apellido_Materno'] ?? '',
        ])));

        if (empty($nombre)) {
            return null;
        }

        $email = $cliente['Email'] ?? null;
        if (! empty($email)) {
            $email = strtolower(trim($email));
            if (! $this->isValidEmail($email)) {
                $email = null;
            }
        }

        $telefono = $cliente['Telefono'] ?? null;
        if (! empty($telefono)) {
            $telefono = $this->normalizarTelefono($telefono);
        }

        if (empty($email) && empty($telefono)) {
            return null;
        }

        // Calcular monto total de cuotas
        $cuotas = $cliente['Cuotas'] ?? [];
        $montoDeuda = 0;
        foreach ($cuotas as $cuota) {
            $montoDeuda += (int) ($cuota['Monto'] ?? 0);
        }

        $tipoProspectoId = $this->determinarTipoProspecto($tiposProspecto, $montoDeuda);

        if ($tipoProspectoId === null) {
            return null;
        }

        $metadata = [
            'source' => 'grupo_deuda',
            'endpoint' => strtolower($endpointPath),
            'synced_at' => now()->toISOString(),
            'cliente_id' => $cliente['Id'] ?? null,
            'abogado' => $cliente['Abogado'] ?? null,
            'cuotas' => $cuotas,
        ];

        $now = now();

        return [
            'importacion_id' => $importacionId,
            'nombre' => $nombre,
            'rut' => null,
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
     * Sincroniza clientes por fecha de ingreso para onboarding.
     *
     * Trae clientes que firmaron contrato hace exactamente 3 días.
     * Esto permite que entren al flujo de onboarding el día 3 después de firmar.
     *
     * Arquitectura:
     * - Día 0: Cliente firma → entra a Flujo Contratos Nuevos (email bienvenida)
     * - Día 3: Este sync lo trae → entra a Flujo Onboarding
     * - Día 3: Nodo 1 - "Soy tu abogado" (inmediato)
     * - Día 4: Nodo 2 - "Cómo funciona" (+1 día)
     * - Día 5: Nodo 3 - "No repactes" (+1 día)
     * - Día 10: Nodo 4 - "Monitoreo" (+5 días)
     * - Día 18: Nodo 5 - "Tabla cuotas" (+8 días)
     */
    public function syncClientesPorFechaIngreso(ExternalApiSource $source, ?int $userId = null): array
    {
        Log::info('GrupoDeudaApiSyncService: Iniciando sincronización ClientesPorFechaIngreso', [
            'source' => $source->name,
        ]);

        try {
            $this->cacheService->loadExistingProspectos();
            $this->loadProspectosEnFlujoActivo();

            // Traer clientes que firmaron hace exactamente 3 días
            // para que entren al flujo de onboarding
            $desde = now()->subDays(3)->startOfDay();
            $hasta = now()->subDays(3)->endOfDay();

            Log::info('GrupoDeudaApiSyncService: Rango de fechas ClientesPorFechaIngreso', [
                'desde' => $desde->format('Y-m-d H:i:s'),
                'hasta' => $hasta->format('Y-m-d H:i:s'),
            ]);

            $data = $this->fetchClientesIngreso($source, $desde, $hasta);

            if (empty($data)) {
                Log::info('GrupoDeudaApiSyncService: No hay datos en ClientesPorFechaIngreso');
                $source->markAsSynced(0);

                return $this->emptyResult();
            }

            $resultado = $this->procesarDatosClientesIngreso($data, $source, $userId ?? 1);

            $source->markAsSynced($resultado['total_prospectos']);

            Log::info('GrupoDeudaApiSyncService: Sincronización ClientesPorFechaIngreso completada', [
                'source' => $source->name,
                'total_prospectos' => $resultado['total_prospectos'],
                'nuevos' => $resultado['nuevos'],
                'actualizados' => $resultado['actualizados'],
            ]);

            return $resultado;

        } catch (\Exception $e) {
            $source->markAsFailed($e->getMessage());

            Log::error('GrupoDeudaApiSyncService: Error en ClientesPorFechaIngreso', [
                'source' => $source->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Llama al endpoint ClientesPorFechaIngreso.
     */
    private function fetchClientesIngreso(ExternalApiSource $source, Carbon $desde, Carbon $hasta): array
    {
        $baseUrl = dirname($source->endpoint_url);
        $url = $baseUrl.'/ClientesPorFechaIngreso';

        $body = [
            'desde' => $desde->format('Y-m-d H:i:s'),
            'hasta' => $hasta->format('Y-m-d H:i:s'),
            'cuotas' => true, // Incluir info de cuotas para tabla en emails
        ];

        Log::info('GrupoDeudaApiSyncService: Llamando a API ClientesPorFechaIngreso', [
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
            throw new \Exception("Error HTTP {$response->status()}: {$response->body()}");
        }

        $json = $response->json();

        if (($json['Estado'] ?? 0) !== 1) {
            $mensaje = $json['Mensaje'] ?? 'Error desconocido';
            throw new \Exception("API respondió con error: {$mensaje}");
        }

        // Este endpoint tiene "Clientes" como array
        $clientes = $json['Clientes'] ?? [];

        Log::info('GrupoDeudaApiSyncService: Respuesta ClientesPorFechaIngreso recibida', [
            'total' => $json['Total'] ?? count($clientes),
        ]);

        return $clientes;
    }

    /**
     * Procesa datos del endpoint ClientesPorFechaIngreso.
     */
    private function procesarDatosClientesIngreso(array $clientes, ExternalApiSource $source, int $userId): array
    {
        $loteNombre = 'CLIENTES_ACTIVOS';
        $lote = $this->obtenerOCrearLoteGlobal($source, $loteNombre, $userId);
        $importacion = $this->createImportacion($source, $lote, $userId, count($clientes));
        $tiposProspecto = $this->loadTiposProspecto();

        $exitosos = 0;
        $fallidos = 0;
        $nuevos = 0;
        $actualizados = 0;
        $omitidosEnFlujo = 0;
        $errores = [];

        $createBatch = [];
        $updateBatch = [];

        // Este endpoint tiene Clientes como array
        foreach ($clientes as $index => $cliente) {
            try {
                $prospectoData = $this->mapClienteIngresoToProspecto(
                    $cliente,
                    $importacion->id,
                    $tiposProspecto
                );

                if ($prospectoData === null) {
                    $fallidos++;
                    $errores[] = ['index' => $index, 'error' => 'Datos insuficientes'];

                    continue;
                }

                $email = $prospectoData['email'];
                $telefono = $prospectoData['telefono'];

                $existingId = $this->cacheService->findExistingProspectoId($email, $telefono);

                if ($existingId !== null) {
                    if ($this->estaEnFlujoActivo($existingId)) {
                        $omitidosEnFlujo++;

                        continue;
                    }

                    $updateBatch[] = array_merge($prospectoData, ['id' => $existingId]);
                    $actualizados++;
                } else {
                    $createBatch[] = $prospectoData;
                    $nuevos++;
                    $this->cacheService->registerNewProspecto($email, $telefono);
                }

                $exitosos++;

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

        if (! empty($createBatch)) {
            $this->insertBatch($createBatch);
        }

        if (! empty($updateBatch)) {
            $this->updateBatch($updateBatch);
        }

        $this->finalizeImportacion($importacion, [
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'nuevos' => $nuevos,
            'actualizados' => $actualizados,
            'omitidos_en_flujo' => $omitidosEnFlujo,
            'errores' => $errores,
        ]);

        $lote->recalcularTotales();

        return [
            'lotes' => [$lote],
            'total_prospectos' => $exitosos,
            'nuevos' => $nuevos,
            'actualizados' => $actualizados,
            'omitidos_en_flujo' => $omitidosEnFlujo,
        ];
    }

    /**
     * Mapea un cliente del endpoint ClientesPorFechaIngreso a Prospecto.
     */
    private function mapClienteIngresoToProspecto(
        array $cliente,
        int $importacionId,
        Collection $tiposProspecto
    ): ?array {
        $nombre = trim(implode(' ', array_filter([
            $cliente['Nombre'] ?? '',
            $cliente['Apellido_Paterno'] ?? '',
            $cliente['Apellido_Materno'] ?? '',
        ])));

        if (empty($nombre)) {
            return null;
        }

        $email = $cliente['Email'] ?? null;
        if (! empty($email)) {
            $email = strtolower(trim($email));
            if (! $this->isValidEmail($email)) {
                $email = null;
            }
        }

        $telefono = $cliente['Telefono'] ?? null;
        if (! empty($telefono)) {
            $telefono = $this->normalizarTelefono($telefono);
        }

        if (empty($email) && empty($telefono)) {
            return null;
        }

        // Este endpoint no tiene monto específico, usar 0 o calcular de cuotas si hay
        $cuotas = $cliente['Cuotas'] ?? [];
        $montoDeuda = 0;
        foreach ($cuotas as $cuota) {
            $montoDeuda += (int) ($cuota['Monto'] ?? 0);
        }

        $tipoProspectoId = $this->determinarTipoProspecto($tiposProspecto, $montoDeuda);

        if ($tipoProspectoId === null) {
            return null;
        }

        $metadata = [
            'source' => 'grupo_deuda',
            'endpoint' => 'clientes_por_fecha_ingreso',
            'synced_at' => now()->toISOString(),
            'cliente_id' => $cliente['Id'] ?? null,
            'abogado' => $cliente['Abogado'] ?? null,
            'cuotas' => $cuotas,
        ];

        $now = now();

        return [
            'importacion_id' => $importacionId,
            'nombre' => $nombre,
            'rut' => null,
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
     * Prueba un endpoint específico de la API.
     */
    public function testEndpoint(string $endpoint, ExternalApiSource $source): array
    {
        try {
            $baseUrl = 'https://sysgal.segal.cl/defensoria/Servicio';
            $desde = now()->startOfDay();
            $hasta = now()->endOfDay();

            $endpointMap = [
                self::ENDPOINT_CONTRATOS_NUEVOS => 'ContratosNuevos',
                self::ENDPOINT_CUOTAS_POR_VENCER => 'CuotasPorVencer',
                self::ENDPOINT_CUOTAS_VENCIDAS => 'CuotasVencidas',
                self::ENDPOINT_CLIENTES_INGRESO => 'ClientesPorFechaIngreso',
            ];

            $endpointPath = $endpointMap[$endpoint] ?? null;

            if ($endpointPath === null) {
                return [
                    'success' => false,
                    'message' => "Endpoint desconocido: {$endpoint}. Válidos: ".implode(', ', array_keys($endpointMap)),
                ];
            }

            $url = $baseUrl.'/'.$endpointPath;

            // ContratosNuevos usa últimas 24h para test, los demás usan el día actual
            if ($endpoint === self::ENDPOINT_CONTRATOS_NUEVOS) {
                $desde = now()->subHours(24);
                $hasta = now();
            }

            $body = [
                'desde' => $desde->format('Y-m-d H:i:s'),
                'hasta' => $hasta->format('Y-m-d H:i:s'),
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post($url, $body);

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
                    'message' => 'API respondió con error: '.($json['Mensaje'] ?? 'desconocido'),
                ];
            }

            // Determinar conteo según endpoint
            $count = 0;
            $total = 0;

            if ($endpoint === self::ENDPOINT_CONTRATOS_NUEVOS) {
                $data = $json['Contratos'] ?? [];
                $count = count($data);
                $total = $json['Total'] ?? $count;
            } elseif (in_array($endpoint, [self::ENDPOINT_CUOTAS_POR_VENCER, self::ENDPOINT_CUOTAS_VENCIDAS])) {
                $data = $json['Clientes'] ?? [];
                $count = is_array($data) ? count($data) : 0;
                $total = $json['Total_Clientes'] ?? $count;
            } else {
                $data = $json['Clientes'] ?? [];
                $count = count($data);
                $total = $json['Total'] ?? $count;
            }

            return [
                'success' => true,
                'message' => 'Conexión exitosa',
                'endpoint' => $endpointPath,
                'sample_count' => $count,
                'total' => $total,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error de conexión: {$e->getMessage()}",
            ];
        }
    }
}
