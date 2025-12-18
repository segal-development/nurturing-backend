<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateConfiguracionRequest;
use App\Http\Resources\ConfiguracionResource;
use App\Models\Configuracion;
use Illuminate\Http\JsonResponse;

class ConfiguracionController extends Controller
{
    /**
     * Display the system configuration.
     */
    public function show(): JsonResponse
    {
        $configuracion = Configuracion::get();

        return response()->json([
            'data' => new ConfiguracionResource($configuracion),
        ]);
    }

    /**
     * Update the system configuration.
     */
    public function update(UpdateConfiguracionRequest $request): JsonResponse
    {
        $configuracion = Configuracion::get();

        $configuracion->update($request->validated());

        return response()->json([
            'data' => new ConfiguracionResource($configuracion->fresh()),
        ]);
    }
}
