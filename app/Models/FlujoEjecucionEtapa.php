<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FlujoEjecucionEtapa extends Model
{
    use HasFactory;

    protected $table = 'flujo_ejecucion_etapas';

    /**
     * Boot method to add model validation.
     *
     * Validates that node_id is required for all etapas to prevent
     * orphaned records without proper node references.
     */
    protected static function booted(): void
    {
        static::saving(function (FlujoEjecucionEtapa $etapa) {
            if (empty($etapa->nodo_id) && empty($etapa->node_id)) {
                throw new \InvalidArgumentException('FlujoEjecucionEtapa requires nodo_id or node_id');
            }
        });

        static::saved(function (FlujoEjecucionEtapa $model) {
            if ($model->wasChanged('prospectos_ids') || $model->wasRecentlyCreated) {
                $ids = $model->prospectos_ids ?? [];
                if (! empty($ids)) {
                    $model->prospectos()->sync($ids);
                }
            }
        });
    }

    protected $fillable = [
        'flujo_ejecucion_id',
        'etapa_id',
        'node_id',
        'prospectos_ids',  // Prospectos que deben procesarse en esta etapa
        'prospectos_count', // Cache del count para evitar cargar el JSON completo
        'fecha_programada',
        'fecha_ejecucion',
        'primer_envio_at', // Cuándo procesó envíos por primera vez (para contar etapas que han trabajado)
        'estado',
        'ejecutado',
        'message_id',
        'response_athenacampaign',
        'error_mensaje',
        'pause_reason',
        'paused_at',
        'auto_resume_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha_programada' => 'datetime',
            'fecha_ejecucion' => 'datetime',
            'primer_envio_at' => 'datetime',
            'ejecutado' => 'boolean',
            'response_athenacampaign' => 'array',
            'prospectos_ids' => 'array',
            'pause_reason' => 'array',
            'paused_at' => 'datetime',
            'auto_resume_at' => 'datetime',
        ];
    }

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(FlujoEjecucion::class, 'flujo_ejecucion_id');
    }

    public function prospectos(): BelongsToMany
    {
        return $this->belongsToMany(Prospecto::class, 'etapa_prospecto');
    }

    /**
     * Scope para etapas pendientes
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pending');
    }

    /**
     * Scope para etapas que deberían ejecutarse
     */
    public function scopeDeberianEjecutarse($query)
    {
        return $query->where('estado', 'pending')
            ->where('fecha_programada', '<=', now());
    }

    /**
     * Scope para etapas no ejecutadas
     */
    public function scopeNoEjecutadas($query)
    {
        return $query->where('ejecutado', false);
    }

    /**
     * Scope para etapas ejecutadas
     */
    public function scopeEjecutadas($query)
    {
        return $query->where('ejecutado', true);
    }

    /**
     * Scope para etapas que han procesado envíos al menos una vez.
     * Útil para contar progreso en flujos perpetuos donde el estado cambia constantemente.
     */
    public function scopeHanProcesadoEnvios($query)
    {
        return $query->whereNotNull('primer_envio_at');
    }

    /**
     * Scope para etapas pausadas automáticamente por circuit breaker
     */
    public function scopePausadasPorCircuitBreaker($query)
    {
        return $query->where('estado', 'paused')
            ->whereNotNull('pause_reason');
    }

    /**
     * Scope para etapas que deberían reanudarse automáticamente
     */
    public function scopeDeberianReanudarse($query)
    {
        return $query->where('estado', 'paused')
            ->whereNotNull('auto_resume_at')
            ->where('auto_resume_at', '<=', now());
    }

    /**
     * Scope para etapas en ejecución de un canal específico (email/sms)
     */
    public function scopeEnEjecucionDeCanal($query, string $channel)
    {
        return $query->where('estado', 'executing')
            ->whereHas('ejecucion.flujo', function ($q) use ($channel) {
                // Verificar por tipo de mensaje en config_structure
                $q->whereRaw("config_structure->'stages' @> ?", [
                    json_encode([['tipo_mensaje' => $channel]]),
                ]);
            });
    }

    /**
     * Pausa la etapa por circuit breaker
     */
    public function pausarPorCircuitBreaker(string $channel, int $failures, int $recoverySeconds, ?string $errorMessage = null): void
    {
        $this->update([
            'estado' => 'paused',
            'pause_reason' => [
                'reason' => 'circuit_breaker_opened',
                'channel' => $channel,
                'failures' => $failures,
                'error_message' => $errorMessage,
            ],
            'paused_at' => now(),
            'auto_resume_at' => now()->addSeconds($recoverySeconds),
        ]);
    }

    /**
     * Reanuda la etapa pausada
     */
    public function reanudar(): void
    {
        $this->update([
            'estado' => 'pending', // Vuelve a pending para que el scheduler la retome
            'pause_reason' => null,
            'paused_at' => null,
            'auto_resume_at' => null,
        ]);
    }

    /**
     * Verifica si está pausada por circuit breaker
     */
    public function estaPausadaPorCircuitBreaker(): bool
    {
        return $this->estado === 'paused'
            && isset($this->pause_reason['reason'])
            && $this->pause_reason['reason'] === 'circuit_breaker_opened';
    }

    /**
     * Verifica si debería reanudarse automáticamente
     */
    public function deberiaReanudarse(): bool
    {
        return $this->estado === 'paused'
            && $this->auto_resume_at !== null
            && $this->auto_resume_at->isPast();
    }
}
