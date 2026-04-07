<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrearPlantillaEmailRequest;
use App\Http\Requests\CrearPlantillaSMSRequest;
use App\Models\Plantilla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlantillaController extends Controller
{
    /**
     * Listar todas las plantillas con filtros opcionales
     */
    public function index(Request $request): JsonResponse
    {
        $query = Plantilla::query();

        // Filtro por tipo
        if ($request->filled('tipo')) {
            $query->tipo($request->input('tipo'));
        }

        // Filtro por estado activo
        if ($request->filled('activo')) {
            $activo = filter_var($request->input('activo'), FILTER_VALIDATE_BOOLEAN);
            if ($activo) {
                $query->activas();
            } else {
                $query->where('activo', false);
            }
        }

        // Filtro por búsqueda (nombre, descripcion, asunto)
        if ($request->filled('busqueda')) {
            $busqueda = $request->busqueda;
            $query->where(function ($q) use ($busqueda) {
                $q->where('nombre', 'like', "%{$busqueda}%")
                    ->orWhere('descripcion', 'like', "%{$busqueda}%")
                    ->orWhere('asunto', 'like', "%{$busqueda}%");
            });
        }

        // Paginación
        $porPagina = $request->input('por_pagina', 10);
        $plantillas = $query->latest()->paginate($porPagina);

        return response()->json([
            'data' => $plantillas->items(),
            'meta' => [
                'total' => $plantillas->total(),
                'per_page' => $plantillas->perPage(),
                'current_page' => $plantillas->currentPage(),
                'last_page' => $plantillas->lastPage(),
            ],
        ]);
    }

    /**
     * Obtener una plantilla por ID
     */
    public function show(Plantilla $plantilla): JsonResponse
    {
        return response()->json([
            'data' => $plantilla,
        ]);
    }

    /**
     * Crear plantilla SMS
     */
    public function crearSMS(CrearPlantillaSMSRequest $request): JsonResponse
    {
        $plantilla = Plantilla::create($request->validated());

        // Validar longitud del SMS
        $validacion = $plantilla->validarLongitudSMS();

        return response()->json([
            'id' => $plantilla->id,
            'mensaje' => 'Plantilla SMS creada exitosamente',
            'plantilla' => $plantilla,
            'validacion_sms' => $validacion,
        ], 201);
    }

    /**
     * Crear plantilla Email
     */
    public function crearEmail(CrearPlantillaEmailRequest $request): JsonResponse
    {
        // ✅ Logging para debug
        \Log::info('Guardando plantilla Email', [
            'componentes_recibidos_raw' => $request->input('componentes'),
            'componentes_validated' => $request->validated()['componentes'] ?? null,
        ]);

        $plantilla = Plantilla::create($request->validated());

        // ✅ Verificar qué se guardó
        \Log::info('Plantilla guardada', [
            'plantilla_id' => $plantilla->id,
            'componentes_guardados' => $plantilla->componentes,
        ]);

        return response()->json([
            'id' => $plantilla->id,
            'mensaje' => 'Plantilla Email creada exitosamente',
            'plantilla' => $plantilla,
        ], 201);
    }

    /**
     * Actualizar plantilla
     */
    public function update(Request $request, Plantilla $plantilla): JsonResponse
    {
        // Validación dinámica según tipo
        if ($plantilla->esSMS()) {
            $request->validate([
                'nombre' => ['sometimes', 'string', 'max:100'],
                'descripcion' => ['nullable', 'string', 'max:500'],
                'contenido' => ['sometimes', 'string', 'max:160'],
                'activo' => ['sometimes', 'boolean'],
            ]);
        } else {
            $request->validate([
                'nombre' => ['sometimes', 'string', 'max:100'],
                'descripcion' => ['nullable', 'string', 'max:500'],
                'asunto' => ['sometimes', 'string', 'max:200'],
                'componentes' => ['sometimes', 'array', 'min:1'],
                'componentes.*.tipo' => ['sometimes', 'in:logo,texto,boton,separador,imagen,footer'],
                'componentes.*.id' => ['sometimes', 'string'],
                'componentes.*.orden' => ['sometimes', 'integer'],
                // ✅ Permitir campos adicionales
                'componentes.*.contenido' => ['nullable', 'string'],
                'componentes.*.url' => ['nullable', 'string'],
                'componentes.*.altura' => ['nullable', 'integer'],
                'componentes.*.alineacion' => ['nullable', 'string'],
                'componentes.*.tamano' => ['nullable', 'integer'],
                'componentes.*.color' => ['nullable', 'string'],
                'componentes.*.color_fondo' => ['nullable', 'string'],
                'componentes.*.color_texto' => ['nullable', 'string'],
                'componentes.*.texto' => ['nullable', 'string'],
                'activo' => ['sometimes', 'boolean'],
            ]);
        }

        // ✅ Logging para debug (solo si es email con componentes)
        if ($plantilla->esEmail() && $request->has('componentes')) {
            \Log::info('Actualizando plantilla Email', [
                'plantilla_id' => $plantilla->id,
                'componentes_recibidos' => $request->input('componentes'),
                'componentes_anteriores' => $plantilla->componentes,
            ]);
        }

        $plantilla->update($request->only([
            'nombre',
            'descripcion',
            'contenido',
            'asunto',
            'componentes',
            'activo',
        ]));

        // ✅ Verificar qué se guardó
        if ($plantilla->esEmail() && $request->has('componentes')) {
            \Log::info('Plantilla actualizada', [
                'plantilla_id' => $plantilla->id,
                'componentes_guardados' => $plantilla->fresh()->componentes,
            ]);
        }

        return response()->json([
            'mensaje' => 'Plantilla actualizada exitosamente',
            'plantilla' => $plantilla->fresh(),
        ]);
    }

    /**
     * Eliminar plantilla
     */
    public function destroy(Plantilla $plantilla): JsonResponse
    {
        $plantilla->delete();

        return response()->json([
            'mensaje' => 'Plantilla eliminada exitosamente',
        ]);
    }

    /**
     * Generar preview HTML de email
     */
    public function generarPreviewEmail(Request $request): JsonResponse
    {
        $request->validate([
            'asunto' => ['required', 'string'],
            'componentes' => ['required', 'array', 'min:1'],
        ]);

        // Crear plantilla temporal para generar el preview
        $plantillaTemp = new Plantilla([
            'tipo' => 'email',
            'asunto' => $request->input('asunto'),
            'componentes' => $request->input('componentes'),
        ]);

        $html = $plantillaTemp->generarPreview();

        return response()->json([
            'preview' => $html,
        ]);
    }

    /**
     * Validar SMS en tiempo real
     */
    public function validarSMS(Request $request): JsonResponse
    {
        $request->validate([
            'contenido' => ['required', 'string'],
        ]);

        $plantillaTemp = new Plantilla([
            'tipo' => 'sms',
            'contenido' => $request->input('contenido'),
        ]);

        $validacion = $plantillaTemp->validarLongitudSMS();

        return response()->json($validacion);
    }

    /**
     * Preview de plantilla guardada con datos de ejemplo
     *
     * Retorna el contenido renderizado (HTML para email, texto para SMS)
     * con variables reemplazadas por valores de ejemplo.
     */
    public function preview(Plantilla $plantilla): JsonResponse
    {
        $contenido = $plantilla->generarPreview() ?? $plantilla->contenido ?? '';

        // Reemplazar variables con datos de ejemplo
        $contenidoConEjemplos = $this->reemplazarVariablesEjemplo($contenido);

        // Detectar qué variables usa la plantilla
        $variables = $this->detectarVariables($plantilla->contenido ?? '');

        return response()->json([
            'data' => [
                'id' => $plantilla->id,
                'nombre' => $plantilla->nombre,
                'tipo' => $plantilla->tipo,
                'asunto' => $plantilla->asunto
                    ? $this->reemplazarVariablesEjemplo($plantilla->asunto)
                    : null,
                'contenido' => $contenidoConEjemplos,
                'variables' => $variables,
            ],
        ]);
    }

    /**
     * Reemplazar variables tipo {{variable}} con datos de ejemplo
     */
    private function reemplazarVariablesEjemplo(string $contenido): string
    {
        $ejemplos = [
            'nombre' => 'Juan Pérez',
            'email' => 'juan.perez@ejemplo.com',
            'telefono' => '+56 9 1234 5678',
            'rut' => '12.345.678-9',
            'monto_deuda' => '$150.000',
            'monto' => '$150.000',
            'fecha_vencimiento' => '15/04/2026',
            'empresa' => 'Grupo Segal',
            'url_informe' => 'https://ejemplo.com/informe/abc123',
            'url_pago' => 'https://ejemplo.com/pagar/abc123',
            'link' => 'https://ejemplo.com/accion',
        ];

        // Reemplazar {{variable}} y {variable}
        foreach ($ejemplos as $variable => $valor) {
            $contenido = str_replace("{{{$variable}}}", $valor, $contenido);
            $contenido = str_replace("{{$variable}}", $valor, $contenido);
        }

        return $contenido;
    }

    /**
     * Detectar variables usadas en el contenido
     */
    private function detectarVariables(string $contenido): array
    {
        preg_match_all('/\{\{?(\w+)\}?\}/', $contenido, $matches);

        return array_unique($matches[1] ?? []);
    }

    /**
     * Obtener las variables disponibles para plantillas
     *
     * Devuelve las variables organizadas por categoría:
     * - basicas: campos fijos del prospecto
     * - sistema: fecha_hoy, fecha_hora, etc.
     * - metadata: campos dinámicos detectados de los prospectos
     */
    public function variablesDisponibles(): JsonResponse
    {
        // Variables básicas (campos fijos del prospecto)
        $basicas = [
            ['key' => 'nombre', 'label' => 'Nombre completo', 'ejemplo' => 'Juan Pérez'],
            ['key' => 'email', 'label' => 'Email', 'ejemplo' => 'juan@email.com'],
            ['key' => 'telefono', 'label' => 'Teléfono', 'ejemplo' => '+56912345678'],
            ['key' => 'rut', 'label' => 'RUT', 'ejemplo' => '12.345.678-9'],
            ['key' => 'monto', 'label' => 'Monto deuda (formateado)', 'ejemplo' => '$150.000'],
            ['key' => 'monto_deuda', 'label' => 'Monto deuda (formateado)', 'ejemplo' => '$150.000'],
            ['key' => 'url_informe', 'label' => 'URL del informe', 'ejemplo' => 'https://...'],
            ['key' => 'estado', 'label' => 'Estado del prospecto', 'ejemplo' => 'activo'],
        ];

        // Variables de sistema
        $sistema = [
            ['key' => 'fecha_hoy', 'label' => 'Fecha actual', 'ejemplo' => now()->format('d/m/Y')],
            ['key' => 'fecha_hora', 'label' => 'Fecha y hora actual', 'ejemplo' => now()->format('d/m/Y H:i')],
            ['key' => 'anio', 'label' => 'Año actual', 'ejemplo' => now()->format('Y')],
        ];

        // Variables de metadata - detectadas dinámicamente de los prospectos
        $metadata = $this->detectarVariablesMetadata();

        return response()->json([
            'data' => [
                'basicas' => $basicas,
                'sistema' => $sistema,
                'metadata' => $metadata,
            ],
        ]);
    }

    /**
     * Detecta las variables de metadata disponibles analizando prospectos existentes
     */
    private function detectarVariablesMetadata(): array
    {
        // Obtener una muestra de prospectos con metadata
        // Use raw query for PostgreSQL JSON compatibility
        $prospectos = \App\Models\Prospecto::whereNotNull('metadata')
            ->whereRaw("metadata::text NOT IN ('{}', '[]', 'null', '')")
            ->limit(100)
            ->get(['metadata']);

        $keysEncontradas = [];
        $ejemplos = [];

        foreach ($prospectos as $prospecto) {
            $metadata = $prospecto->metadata;
            if (! is_array($metadata)) {
                continue;
            }

            $this->extraerKeysRecursivo($metadata, '', $keysEncontradas, $ejemplos);
        }

        // Convertir a formato de respuesta, filtrando keys internas
        $keysInternas = ['source', 'endpoint', 'synced_at', 'cliente_id'];
        $resultado = [];

        foreach ($keysEncontradas as $key => $count) {
            // Filtrar keys internas y arrays complejos
            if (in_array($key, $keysInternas)) {
                continue;
            }

            // Generar label legible
            $label = $this->generarLabelDesdeKey($key);

            $resultado[] = [
                'key' => $key,
                'label' => $label,
                'ejemplo' => $ejemplos[$key] ?? '',
                'frecuencia' => $count, // Cuántos prospectos tienen esta key
            ];
        }

        // Ordenar por frecuencia (más comunes primero)
        usort($resultado, fn ($a, $b) => $b['frecuencia'] <=> $a['frecuencia']);

        // Limitar a las 30 más comunes
        return array_slice($resultado, 0, 30);
    }

    /**
     * Extrae keys recursivamente de un array de metadata
     */
    private function extraerKeysRecursivo(array $data, string $prefix, array &$keys, array &$ejemplos): void
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                // Si es array indexado (0, 1, 2...), tomar solo el primer elemento
                if ($this->isListArray($value) && ! empty($value)) {
                    $this->extraerKeysRecursivo($value[0], "{$fullKey}.0", $keys, $ejemplos);
                } else {
                    // Array asociativo, seguir recursivamente
                    $this->extraerKeysRecursivo($value, $fullKey, $keys, $ejemplos);
                }
            } else {
                // Valor escalar
                $keys[$fullKey] = ($keys[$fullKey] ?? 0) + 1;

                // Guardar ejemplo si no existe o si el actual es más informativo
                if (! isset($ejemplos[$fullKey]) || (strlen((string) $value) > 0 && strlen((string) $value) < 50)) {
                    $ejemplos[$fullKey] = (string) $value;
                }
            }
        }
    }

    /**
     * Genera un label legible desde una key de metadata
     * Ej: "abogado.Nombre" -> "Nombre del Abogado"
     */
    private function generarLabelDesdeKey(string $key): string
    {
        // Mapeo de keys conocidas
        $mapeo = [
            'abogado.Nombre' => 'Nombre del Abogado',
            'abogado.Email' => 'Email del Abogado',
            'abogado.Telefono' => 'Teléfono del Abogado',
            'abogado.Apellido_Paterno' => 'Apellido Paterno del Abogado',
            'abogado.Apellido_Materno' => 'Apellido Materno del Abogado',
            'nivel_deuda' => 'Nivel de Deuda',
            'etapa_sysgal' => 'Etapa en Sysgal',
            'cuotas.0.Monto' => 'Monto de Cuota',
            'cuotas.0.Vencimiento' => 'Fecha Vencimiento Cuota',
            'cuotas.0.Estado' => 'Estado de Cuota',
            'cuotas.0.Contrato' => 'Número de Contrato',
            'cuotas.0.Cuota' => 'Número de Cuota',
        ];

        if (isset($mapeo[$key])) {
            return $mapeo[$key];
        }

        // Generar label automático
        $parts = explode('.', $key);
        $lastPart = end($parts);

        // Convertir snake_case y PascalCase a palabras
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $lastPart);
        $label = str_replace('_', ' ', $label);
        $label = ucfirst(strtolower($label));

        return $label;
    }

    /**
     * Check if array is a list (sequential numeric keys starting from 0)
     * Polyfill for array_is_list() which requires PHP 8.1+
     */
    private function isListArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}
