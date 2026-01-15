<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prospecto extends Model
{
    use HasFactory;

    protected $fillable = [
        'importacion_id',
        'nombre',
        'rut',
        'email',
        'telefono',
        'url_informe',
        'tipo_prospecto_id',
        'estado',
        'monto_deuda',
        'fecha_ultimo_contacto',
        'fila_excel',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'monto_deuda' => 'integer',
            'fecha_ultimo_contacto' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(Importacion::class);
    }

    public function tipoProspecto(): BelongsTo
    {
        return $this->belongsTo(TipoProspecto::class);
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
        return $query->where('estado', 'activo');
    }

    public function scopeInactivos($query)
    {
        return $query->where('estado', 'inactivo');
    }

    public function scopeArchivados($query)
    {
        return $query->where('estado', 'archivado');
    }

    public function scopeNoArchivados($query)
    {
        return $query->where('estado', '!=', 'archivado');
    }

    public function scopeConvertidos($query)
    {
        return $query->where('estado', 'convertido');
    }

    public function scopePorTipo($query, int $tipoProspectoId)
    {
        return $query->where('tipo_prospecto_id', $tipoProspectoId);
    }

    public function scopePorOrigen($query, string $origen)
    {
        return $query->whereHas('importacion', function ($q) use ($origen) {
            $q->where('origen', $origen);
        });
    }

    public function isActivo(): bool
    {
        return $this->estado === 'activo';
    }

    public function isConvertido(): bool
    {
        return $this->estado === 'convertido';
    }

    public function isArchivado(): bool
    {
        return $this->estado === 'archivado';
    }

    public function getOrigenAttribute(): ?string
    {
        return $this->importacion?->origen;
    }

    /**
     * Mutator para url_informe: convierte strings vacíos a null
     */
    public function setUrlInformeAttribute(?string $value): void
    {
        $this->attributes['url_informe'] = empty($value) ? null : $value;
    }

    /**
     * Mutator para normalizar teléfonos con prefijo +56 (Chile)
     *
     * Normaliza el teléfono agregando +56 si no lo tiene.
     * Ejemplos:
     * - "912345678" -> "+56912345678"
     * - "56912345678" -> "+56912345678"
     * - "+56912345678" -> "+56912345678"
     */
    public function setTelefonoAttribute(?string $value): void
    {
        if (empty($value)) {
            $this->attributes['telefono'] = null;

            return;
        }

        // Limpiar espacios y caracteres especiales
        $telefono = preg_replace('/[\s\-\(\)]/u', '', $value);

        // Si ya tiene el prefijo +56, mantenerlo
        if (str_starts_with($telefono, '+56')) {
            $this->attributes['telefono'] = $telefono;

            return;
        }

        // Si tiene 56 al inicio (sin +), agregar el +
        if (str_starts_with($telefono, '56')) {
            $this->attributes['telefono'] = '+'.$telefono;

            return;
        }

        // Si es un número chileno (9 dígitos empezando con 9), agregar +56
        if (preg_match('/^9\d{8}$/', $telefono)) {
            $this->attributes['telefono'] = '+56'.$telefono;

            return;
        }

        // Para cualquier otro caso, guardar tal cual
        $this->attributes['telefono'] = $telefono;
    }

    /**
     * Accessor para obtener teléfono formateado
     * Retorna el teléfono con formato +56XXXXXXXXX
     */
    public function getFormattedTelefonoAttribute(): ?string
    {
        return $this->telefono;
    }

    /**
     * Obtener teléfono sin prefijo (solo números)
     * Útil para integraciones que no requieren el +56
     */
    public function getTelefonoSinPrefijoAttribute(): ?string
    {
        if (empty($this->telefono)) {
            return null;
        }

        // Remover el +56 si existe
        return str_replace(['+56', '+'], '', $this->telefono);
    }
}
