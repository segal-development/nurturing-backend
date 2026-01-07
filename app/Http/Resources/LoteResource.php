<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoteResource extends JsonResource
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
            'user_id' => $this->user_id,
            'total_archivos' => $this->total_archivos,
            'total_registros' => $this->total_registros,
            'registros_exitosos' => $this->registros_exitosos,
            'registros_fallidos' => $this->registros_fallidos,
            'estado' => $this->estado,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'importaciones' => ImportacionResource::collection($this->whenLoaded('importaciones')),
            'user' => $this->whenLoaded('user'),
        ];
    }
}
