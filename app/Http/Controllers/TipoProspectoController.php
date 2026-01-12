<?php

namespace App\Http\Controllers;

use App\Models\TipoProspecto;
use Illuminate\Http\JsonResponse;

class TipoProspectoController extends Controller
{
    /**
     * Get all active tipos de prospecto ordered by orden
     */
    public function index(): JsonResponse
    {
        $tipos = TipoProspecto::activos()
            ->ordenados()
            ->get();

        return response()->json([
            'data' => $tipos,
        ]);
    }
}
