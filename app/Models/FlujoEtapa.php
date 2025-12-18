<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlujoEtapa extends Model
{
    protected $table = 'flujo_etapas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'flujo_id',
        'orden',
        'label',
        'dia_envio',
        'tipo_mensaje',
        'plantilla_mensaje',
        'plantilla_id',
        'plantilla_id_email',
        'plantilla_type',
        'fecha_inicio_personalizada',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'dia_envio' => 'integer',
            'plantilla_id' => 'integer',
            'plantilla_id_email' => 'integer',
            'activo' => 'boolean',
            'fecha_inicio_personalizada' => 'datetime',
        ];
    }

    /**
     * Relación con el flujo
     */
    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }

    /**
     * Relación con plantilla principal (SMS o Email)
     */
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(Plantilla::class, 'plantilla_id');
    }

    /**
     * Relación con plantilla de email (solo cuando tipo_mensaje es 'ambos')
     */
    public function plantillaEmail(): BelongsTo
    {
        return $this->belongsTo(Plantilla::class, 'plantilla_id_email');
    }

    /**
     * Determina si usa plantilla de referencia o contenido inline
     */
    public function usaPlantillaReferencia(): bool
    {
        return $this->plantilla_type === 'reference' && ($this->plantilla_id || $this->plantilla_id_email);
    }

    /**
     * Obtiene el contenido a enviar, ya sea de la plantilla o inline
     * 
     * @param string $tipo 'sms' o 'email'
     * @return array{contenido: string, asunto: string|null, es_html: bool}
     */
    public function obtenerContenidoParaEnvio(string $tipo = 'email'): array
    {
        // Si usa plantilla de referencia
        if ($this->usaPlantillaReferencia()) {
            $plantilla = $tipo === 'email' && $this->plantilla_id_email 
                ? $this->plantillaEmail 
                : $this->plantilla;

            if ($plantilla) {
                if ($plantilla->esEmail()) {
                    return [
                        'contenido' => $plantilla->generarPreview() ?? '',
                        'asunto' => $plantilla->asunto,
                        'es_html' => true,
                    ];
                } else {
                    return [
                        'contenido' => $plantilla->contenido ?? '',
                        'asunto' => null,
                        'es_html' => false,
                    ];
                }
            }
        }

        // Fallback: contenido inline
        return [
            'contenido' => $this->plantilla_mensaje ?? '',
            'asunto' => null,
            'es_html' => false,
        ];
    }
}
