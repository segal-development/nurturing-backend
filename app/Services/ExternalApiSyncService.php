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
     * @param  ExternalApiSource  $source  La fuente a sincronizar
     * @param  int|null  $userId  ID del usuario que ejecuta (null = sistema)
     * @return array{lotes: array<Lote>, total_prospectos: int, nuevos: int, actualizados: int, omitidos_en_flujo: int}
     *
     * @throws \Exception Si la API falla o hay errores críticos
     */
    public function sync(ExternalApiSource $source, ?int $userId = null): array
    {
        Log::info('ExternalApiSyncService: Iniciando sincronización', [
            'source' => $source->name,
            'endpoint' => $source->endpoint_url,
            'clasificacion_field' => $source->clasificacion_field,
        ]);

        try {
            // 1. Cargar cache de prospectos existentes para deduplicación
            $this->cacheService->loadExistingProspectos();

            // 2. Cargar IDs de prospectos ya en flujos activos
            $this->loadProspectosEnFlujoActivo();

            // 3. Llamar a la API externa
            $data = $this->fetchFromApi($source);

            // 4. Agrupar por clasificación (si está configurada)
            $grupos = $this->agruparPorClasificacion($data, $source);

            // 5. Procesar cada grupo como un lote separado
            $resultado = $this->procesarGrupos($grupos, $source, $userId ?? 1);

            // 6. Marcar la fuente como sincronizada
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
     * Llama a la API externa y obtiene los datos.
     */
    private function fetchFromApi(ExternalApiSource $source): array
    {
        $url = $source->endpoint_url;

        // Agregar filtros si están configurados
        if (! empty($source->sync_filters)) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($source->sync_filters);
        }

        $response = Http::withHeaders($source->getRequestHeaders())
            ->timeout(120)
            ->get($url);

        if (! $response->successful()) {
            throw new \Exception("Error HTTP {$response->status()}: {$response->body()}");
        }

        $data = $response->json('data') ?? $response->json();

        if (! is_array($data)) {
            throw new \Exception('La respuesta de la API no tiene el formato esperado');
        }

        return $data;
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

            // Actualizar totales del lote
            $lote->recalcularTotales();

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
