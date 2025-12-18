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
}
