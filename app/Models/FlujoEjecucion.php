<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class FlujoEjecucion extends Model
{
    use HasFactory;

    protected $table = 'flujo_ejecuciones';

    protected static function booted(): void
    {
        static::saved(function (FlujoEjecucion $model) {
            if ($model->wasChanged('prospectos_ids') || $model->wasRecentlyCreated) {
                $ids = $model->prospectos_ids ?? [];
                if (! empty($ids)) {
                    $model->prospectos()->sync($ids);
                }
            }
        });

        // Invariante: una ejecución perpetua NUNCA debe terminar en 'completed'.
        // Centraliza el guard de es_perpetuo que antes vivía repetido en BatchCompletedCallback,
        // FlujoEjecucionEtapaObserver, EjecutarNodosProgramados y EnviarEtapaJob. Si alguno
        // de ellos (o código futuro) intenta marcar completed un flujo perpetuo, lo corregimos
        // a 'waiting' y logueamos el caller para poder localizar el path defectuoso.
        static::updating(function (FlujoEjecucion $ejecucion) {
            if (! $ejecucion->isDirty('estado') || $ejecucion->estado !== 'completed') {
                return;
            }

            $esPerpetuo = $ejecucion->es_perpetuo || ($ejecucion->flujo?->es_perpetuo ?? false);
            if (! $esPerpetuo) {
                return;
            }

            $callers = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8))
                ->slice(2)
                ->map(fn ($f) => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''))
                ->filter()
                ->take(5)
                ->values()
                ->all();

            Log::warning('FlujoEjecucion: intento de marcar perpetuo como completed bloqueado por guard del modelo', [
                'ejecucion_id' => $ejecucion->id,
                'flujo_id' => $ejecucion->flujo_id,
                'estado_anterior' => $ejecucion->getOriginal('estado'),
                'callers' => $callers,
            ]);

            $ejecucion->estado = 'waiting';
            $ejecucion->fecha_fin = null;
        });
    }

    protected $fillable = [
        'flujo_id',
        'origen_id',
        'prospectos_ids',
        'prospectos_count',
        'fecha_inicio_programada',
        'fecha_inicio_real',
        'fecha_fin',
        'estado',
        'pausada_en',
        'es_perpetuo',
        'nodo_actual',
        'proximo_nodo',
        'fecha_proximo_nodo',
        'config',
        'error_message',
        // Cost tracking fields
        'costo_estimado',
        'costo_real',
        'costo_emails',
        'costo_sms',
        'total_emails_enviados',
        'total_sms_enviados',
    ];

    protected function casts(): array
    {
        return [
            'prospectos_ids' => 'array',
            'config' => 'array',
            'es_perpetuo' => 'boolean',
            'fecha_inicio_programada' => 'datetime',
            'fecha_inicio_real' => 'datetime',
            'fecha_fin' => 'datetime',
            'fecha_proximo_nodo' => 'datetime',
            'pausada_en' => 'datetime',
            'costo_estimado' => 'decimal:2',
            'costo_real' => 'decimal:2',
            'costo_emails' => 'decimal:2',
            'costo_sms' => 'decimal:2',
            'total_emails_enviados' => 'integer',
            'total_sms_enviados' => 'integer',
        ];
    }

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }

    /**
     * Finaliza la ejecución respetando flujos perpetuos.
     *
     * Para flujos PERPETUOS nunca se marca 'completed' (eso deja la ejecución
     * muerta y CatchUpProspectosJob deja de procesarla, dejando a los nuevos
     * prospectos sin nutrir): queda 'waiting', esperando nuevos prospectos.
     * Solo los flujos normales se completan.
     *
     * ÚNICA fuente de verdad para finalizar una ejecución. La usan
     * BatchCompletedCallback y EjecutarNodosProgramados, que antes duplicaban
     * esta lógica de forma inconsistente: varios paths olvidaban el guard
     * es_perpetuo y re-mataban ejecuciones perpetuas (incidente 2026-05-20).
     */
    public function finalizarRespetandoPerpetuo(): void
    {
        $esPerpetuo = $this->es_perpetuo || ($this->flujo?->es_perpetuo ?? false);

        if ($esPerpetuo) {
            $this->update([
                'estado' => 'waiting',
                'proximo_nodo' => null,
                'fecha_proximo_nodo' => null,
            ]);

            return;
        }

        $this->update([
            'estado' => 'completed',
            'fecha_fin' => now(),
            'proximo_nodo' => null,
            'fecha_proximo_nodo' => null,
        ]);
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(FlujoEjecucionEtapa::class);
    }

    public function condiciones(): HasMany
    {
        return $this->hasMany(FlujoEjecucionCondicion::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(FlujoJob::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FlujoLog::class);
    }

    public function prospectos(): BelongsToMany
    {
        return $this->belongsToMany(Prospecto::class, 'ejecucion_prospecto');
    }

    /**
     * Scope para ejecuciones pendientes
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pending');
    }

    /**
     * Scope para ejecuciones en progreso
     */
    public function scopeEnProgreso($query)
    {
        return $query->where('estado', 'in_progress');
    }

    /**
     * Scope para ejecuciones que deberían haber comenzado
     */
    public function scopeDeberianHaberComenzado($query)
    {
        return $query->where('estado', 'pending')
            ->where('fecha_inicio_programada', '<=', now());
    }

    /**
     * Scope para ejecuciones activas (in_progress)
     */
    public function scopeActivas($query)
    {
        return $query->where('estado', 'in_progress');
    }

    /**
     * Scope para ejecuciones con nodos programados listos para ejecutar
     */
    public function scopeConNodosProgramados($query)
    {
        return $query->where('estado', 'in_progress')
            ->whereNotNull('proximo_nodo')
            ->whereNotNull('fecha_proximo_nodo')
            ->where('fecha_proximo_nodo', '<=', now());
    }

    /**
     * Ejecuciones que tienen etapas en 'executing' (posiblemente terminadas).
     * Usado para detectar etapas de volumen grande que terminaron de procesar.
     */
    public function scopeConEtapasEjecutando($query)
    {
        return $query->where('estado', 'in_progress')
            ->whereHas('etapas', function ($q) {
                $q->where('estado', 'executing');
            });
    }

    /**
     * Scope para ejecuciones perpetuas.
     * New prospects are added to these executions instead of creating new ones.
     */
    public function scopePerpetuas($query)
    {
        return $query->where('es_perpetuo', true);
    }
}
