<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfiguracionResource extends JsonResource
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
            'email_costo' => (float) $this->email_costo,
            'sms_costo' => (float) $this->sms_costo,
            'max_prospectos_por_flujo' => $this->max_prospectos_por_flujo,
            'max_emails_por_dia' => $this->max_emails_por_dia,
            'max_sms_por_dia' => $this->max_sms_por_dia,
            'reintentos_envio' => $this->reintentos_envio,
            'notificar_flujo_completado' => $this->notificar_flujo_completado,
            'notificar_errores_envio' => $this->notificar_errores_envio,
            'email_notificaciones' => $this->email_notificaciones,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
