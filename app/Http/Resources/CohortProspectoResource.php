<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CohortProspectoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'email' => $this->email,
            'telefono' => $this->telefono,
            'ultima_etapa_node_id' => $this->ultima_etapa_node_id ?? null, // @transition-authority-ok: read-only (API resource)
            'envios_resumen' => $this->envios_resumen ?? [
                'total' => 0,
                'enviados' => 0,
                'fallidos' => 0,
                'abiertos' => 0,
                'clickeados' => 0,
            ],
            'ultimo_envio' => $this->ultimo_envio,
        ];
    }
}
