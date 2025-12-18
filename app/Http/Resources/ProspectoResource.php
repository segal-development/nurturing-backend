<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProspectoResource extends JsonResource
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
            'rut' => $this->rut,
            'email' => $this->email,
            'telefono' => $this->telefono,
            'telefono_sin_prefijo' => $this->telefono_sin_prefijo,
            'url_informe' => $this->url_informe,
            'tipo_prospecto_id' => $this->tipo_prospecto_id,
            'tipo_prospecto' => $this->whenLoaded('tipoProspecto', function () {
                return [
                    'id' => $this->tipoProspecto->id,
                    'nombre' => $this->tipoProspecto->nombre,
                ];
            }),
            'estado' => $this->estado,
            'monto_deuda' => $this->monto_deuda,
            'fecha_ultimo_contacto' => $this->fecha_ultimo_contacto?->timezone('America/Santiago')->format('d/m/Y H:i:s'),
            'origen' => $this->origen,
            'importacion_id' => $this->importacion_id,
            'importacion' => $this->whenLoaded('importacion', function () {
                return [
                    'id' => $this->importacion->id,
                    'origen' => $this->importacion->origen,
                    'fecha_importacion' => $this->importacion->fecha_importacion?->timezone('America/Santiago')->format('d/m/Y H:i:s'),
                ];
            }),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->timezone('America/Santiago')->format('d/m/Y H:i:s'),
            'updated_at' => $this->updated_at?->timezone('America/Santiago')->format('d/m/Y H:i:s'),
        ];
    }
}
