<?php

namespace App\Http\Controllers;

use App\Models\TipoProspecto;
use Illuminate\Http\JsonResponse;

class TipoProspectoController extends Controller
{
    /**
     * Get all active tipos de prospecto.
     * Used by frontend to dynamically categorize prospects by debt amount.
     */
    public function index(): JsonResponse
    {
        $tipos = TipoProspecto::activos()
            ->ordenados()
            ->get(['id', 'nombre', 'descripcion', 'monto_min', 'monto_max', 'orden']);

        return response()->json([
            'data' => $tipos,
        ]);
    }
}
