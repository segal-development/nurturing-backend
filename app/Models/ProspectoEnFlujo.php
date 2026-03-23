<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectoEnFlujo extends Model
{
    use HasFactory;

    protected $table = 'prospecto_en_flujo';

    protected $fillable = [
        'prospecto_id',
        'flujo_id',
        'canal_asignado',
        'estado',
        'etapa_actual_id',
        'ultima_etapa_node_id',
        'fecha_inicio',
        'fecha_proxima_etapa',
        'completado',
        'cancelado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_proxima_etapa' => 'datetime',
            'completado' => 'boolean',
            'cancelado' => 'boolean',
        ];
    }

    public function prospecto(): BelongsTo
    {
        return $this->belongsTo(Prospecto::class);
    }

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }

    public function etapaActual(): BelongsTo
    {
        return $this->belongsTo(EtapaFlujo::class, 'etapa_actual_id');
    }

    // Relationships cleaned: removed unused envios() — 2026-02-03

    public function scopeActivos($query)
    {
        return $query->where('completado', false)->where('cancelado', false);
    }

    public function scopeCompletados($query)
    {
        return $query->where('completado', true);
    }

    public function scopeCancelados($query)
    {
        return $query->where('cancelado', true);
    }

    public function scopePorFlujo($query, int $flujoId)
    {
        return $query->where('flujo_id', $flujoId);
    }

    /**
     * Scope to filter by ultima_etapa_node_id.
     *
     * @param  string|null  $nodeId  The node_id to filter by (null = no stage completed)
     */
    public function scopePorEtapa($query, ?string $nodeId)
    {
        if ($nodeId === null) {
            return $query->whereNull('ultima_etapa_node_id');
        }

        return $query->where('ultima_etapa_node_id', $nodeId);
    }

    public function scopePorCanal($query, string $canal)
    {
        return $query->where('canal_asignado', $canal);
    }

    public function scopePorEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopeEnProceso($query)
    {
        return $query->where('estado', 'en_proceso');
    }

    public function marcarEnProceso(): void
    {
        $this->update(['estado' => 'en_proceso']);
    }

    public function marcarCompletado(): void
    {
        $this->update(['estado' => 'completado']);
    }

    public function marcarCancelado(): void
    {
        $this->update(['estado' => 'cancelado']);
    }

    public function scopeProximosEnvios($query)
    {
        return $query->activos()
            ->whereNotNull('fecha_proxima_etapa')
            ->where('fecha_proxima_etapa', '<=', now());
    }

    public function avanzarEtapa(EtapaFlujo $siguienteEtapa): void
    {
        $this->update([
            'etapa_actual_id' => $siguienteEtapa->id,
            'fecha_proxima_etapa' => $siguienteEtapa->calcularFechaProgramada($this->fecha_inicio),
        ]);
    }

    public function completar(): void
    {
        $this->update([
            'completado' => true,
            'fecha_proxima_etapa' => null,
        ]);
    }

    public function cancelar(): void
    {
        $this->update([
            'cancelado' => true,
            'fecha_proxima_etapa' => null,
        ]);
    }

    public function isActivo(): bool
    {
        return ! $this->completado && ! $this->cancelado;
    }
}
