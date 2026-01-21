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

            // ====== DEBUG: Log de la plantilla ======
            \Illuminate\Support\Facades\Log::info('FlujoEtapa: DEBUG - Buscando plantilla', [
                'flujo_etapa_id' => $this->id,
                'tipo' => $tipo,
                'plantilla_id' => $this->plantilla_id,
                'plantilla_id_email' => $this->plantilla_id_email,
                'plantilla_encontrada' => $plantilla ? true : false,
                'plantilla_nombre' => $plantilla?->nombre,
                'plantilla_tipo' => $plantilla?->tipo,
            ]);

            if ($plantilla) {
                if ($plantilla->esEmail()) {
                    $htmlGenerado = $plantilla->generarPreview();
                    
                    \Illuminate\Support\Facades\Log::info('FlujoEtapa: DEBUG - HTML generado', [
                        'plantilla_id' => $plantilla->id,
                        'tiene_componentes' => !empty($plantilla->componentes),
                        'componentes_count' => is_array($plantilla->componentes) ? count($plantilla->componentes) : 0,
                        'html_length' => strlen($htmlGenerado ?? ''),
                        'html_preview' => substr($htmlGenerado ?? '', 0, 300),
                    ]);
                    
                    return [
                        'contenido' => $htmlGenerado ?? '',
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
            } else {
                \Illuminate\Support\Facades\Log::warning('FlujoEtapa: DEBUG - Plantilla NO encontrada', [
                    'flujo_etapa_id' => $this->id,
                    'plantilla_id' => $this->plantilla_id,
                    'plantilla_id_email' => $this->plantilla_id_email,
                ]);
            }
        }

        // Fallback: contenido inline
        $contenido = $this->plantilla_mensaje ?? '';
        
        \Illuminate\Support\Facades\Log::info('FlujoEtapa: DEBUG - Usando contenido inline (fallback)', [
            'flujo_etapa_id' => $this->id,
            'contenido_length' => strlen($contenido),
        ]);
        
        return [
            'contenido' => $contenido,
            'asunto' => null,
            'es_html' => $this->detectarSiEsHtml($contenido),
        ];
    }

    /**
     * Detecta si un contenido es HTML.
     */
    private function detectarSiEsHtml(string $contenido): bool
    {
        $htmlPatterns = [
            '/<html/i',
            '/<body/i',
            '/<div/i',
            '/<p>/i',
            '/<br/i',
            '/<table/i',
            '/<a\s+href/i',
            '/<img/i',
            '/<h[1-6]/i',
            '/<span/i',
            '/<style/i',
        ];

        foreach ($htmlPatterns as $pattern) {
            if (preg_match($pattern, $contenido)) {
                return true;
            }
        }

        return false;
    }
}
