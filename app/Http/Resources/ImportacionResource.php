<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportacionResource extends JsonResource
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
            'nombre_archivo' => $this->nombre_archivo,
            'origen' => $this->origen,
            'total_registros' => $this->total_registros,
            'registros_exitosos' => $this->registros_exitosos,
            'registros_fallidos' => $this->registros_fallidos,
            'estado' => $this->estado,
            'fecha_importacion' => $this->fecha_importacion?->timezone('America/Santiago')->format('d/m/Y H:i:s'),
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->timezone('America/Santiago')->format('d/m/Y H:i:s'),
            'updated_at' => $this->updated_at?->timezone('America/Santiago')->format('d/m/Y H:i:s'),
        ];
    }
}
