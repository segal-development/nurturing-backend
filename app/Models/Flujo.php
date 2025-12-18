<?php

namespace App\Models;

use App\Enums\CanalEnvio;
use App\Services\CanalEnvioResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Flujo extends Model
{
    use HasFactory;

    protected $fillable = [
        'tipo_prospecto_id',
        'origen_id',
        'origen',
        'nombre',
        'descripcion',
        'canal_envio',
        'activo',
        'user_id',
        'metadata',
        'config_visual',
        'config_structure',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'metadata' => 'array',
            'config_visual' => 'array',
            'config_structure' => 'array',
        ];
    }

    public function tipoProspecto(): BelongsTo
    {
        return $this->belongsTo(TipoProspecto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaFlujo::class)->orderBy('orden');
    }

    public function prospectosEnFlujo(): HasMany
    {
        return $this->hasMany(ProspectoEnFlujo::class);
    }

    public function envios(): HasMany
    {
        return $this->hasMany(Envio::class);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorTipo($query, int $tipoProspectoId)
    {
        return $query->where('tipo_prospecto_id', $tipoProspectoId);
    }

    public function scopePorOrigen($query, string $origen)
    {
        return $query->where('origen', $origen);
    }

    public static function findOrCreateForProspecto(int $tipoProspectoId, string $origen): self
    {
        // Obtener el primer usuario del sistema para asignar al flujo
        $user = User::first();

        return self::firstOrCreate(
            [
                'tipo_prospecto_id' => $tipoProspectoId,
                'origen' => $origen,
            ],
            [
                'nombre' => self::generarNombre($tipoProspectoId, $origen),
                'activo' => true,
                'user_id' => $user?->id,
            ]
        );
    }

    protected static function generarNombre(int $tipoProspectoId, string $origen): string
    {
        $tipo = TipoProspecto::find($tipoProspectoId);
        $nombreTipo = $tipo ? $tipo->nombre : "Tipo {$tipoProspectoId}";

        return "Flujo {$nombreTipo} - ".ucfirst($origen);
    }

    public function prospectos(): HasManyThrough
    {
        return $this->hasManyThrough(
            Prospecto::class,
            ProspectoEnFlujo::class,
            'flujo_id',
            'id',
            'id',
            'prospecto_id'
        );
    }

    // FlowBuilder relationships
    public function flujoEtapas(): HasMany
    {
        return $this->hasMany(FlujoEtapa::class)->orderBy('orden');
    }

    public function flujoCondiciones(): HasMany
    {
        return $this->hasMany(FlujoCondicion::class);
    }

    public function flujoRamificaciones(): HasMany
    {
        return $this->hasMany(FlujoRamificacion::class);
    }

    public function flujoNodosFinales(): HasMany
    {
        return $this->hasMany(FlujoNodoFinal::class);
    }

    public function ejecuciones(): HasMany
    {
        return $this->hasMany(FlujoEjecucion::class);
    }

    /**
     * Accessor para obtener los datos del flujo unificados
     * Combina config_visual y config_structure en un solo objeto
     */
    public function getFlujoDataAttribute(): array
    {
        return array_merge(
            $this->config_visual ?? [],
            $this->config_structure ?? []
        );
    }

    /**
     * Infiere el canal de envío basándose en las etapas reales del flujo.
     * Útil para recalcular el canal de flujos existentes o mostrar el canal correcto en la UI.
     */
    public function inferirCanalEnvio(): CanalEnvio
    {
        $resolver = app(CanalEnvioResolver::class);

        // Prioridad 1: Usar etapas de la base de datos
        $etapasDb = $this->flujoEtapas()->get(['tipo_mensaje'])->toArray();

        if (!empty($etapasDb)) {
            return $resolver->resolveFromStages($etapasDb);
        }

        // Prioridad 2: Usar estructura guardada en config_structure
        if (!empty($this->config_structure)) {
            return $resolver->resolveFromStructure($this->config_structure);
        }

        // Fallback: usar el valor guardado o default
        return CanalEnvio::tryFrom($this->canal_envio) ?? CanalEnvio::EMAIL;
    }

    /**
     * Recalcula y actualiza el canal_envio basándose en las etapas.
     * 
     * @return bool True si se actualizó, false si no hubo cambios
     */
    public function recalcularCanalEnvio(): bool
    {
        $canalInferido = $this->inferirCanalEnvio();

        if ($this->canal_envio === $canalInferido->value) {
            return false;
        }

        $this->canal_envio = $canalInferido->value;
        $this->save();

        return true;
    }

    /**
     * Accessor para obtener el canal de envío como enum.
     */
    public function getCanalEnvioEnumAttribute(): CanalEnvio
    {
        return CanalEnvio::tryFrom($this->canal_envio) ?? CanalEnvio::EMAIL;
    }

    /**
     * Accessor para obtener el label del canal de envío.
     */
    public function getCanalEnvioLabelAttribute(): string
    {
        return $this->canal_envio_enum->label();
    }

    /**
     * Accessor para obtener el canal inferido de las etapas (sin guardarlo).
     * Útil para mostrar en la UI el canal "real" basado en las etapas.
     */
    public function getCanalEnvioInferidoAttribute(): string
    {
        return $this->inferirCanalEnvio()->value;
    }
}
