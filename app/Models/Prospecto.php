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
        'email_invalido',
        'email_invalido_motivo',
        'email_invalido_at',
        'telefono',
        'url_informe',
        'tipo_prospecto_id',
        'estado',
        'monto_deuda',
        'fecha_ultimo_contacto',
        'fila_excel',
        'metadata',
        'preferencias_comunicacion',
        'fecha_desuscripcion',
    ];

    protected function casts(): array
    {
        return [
            'monto_deuda' => 'integer',
            'fecha_ultimo_contacto' => 'datetime',
            'metadata' => 'array',
            'preferencias_comunicacion' => 'array',
            'fecha_desuscripcion' => 'datetime',
            'email_invalido' => 'boolean',
            'email_invalido_at' => 'datetime',
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

    public function isDesuscrito(): bool
    {
        return $this->estado === 'desuscrito';
    }

    /**
     * Verifica si el prospecto puede recibir comunicaciones por un canal específico.
     */
    public function puedeRecibirComunicacion(string $canal = 'todos'): bool
    {
        if ($this->estado === 'desuscrito') {
            return false;
        }

        if (!$this->preferencias_comunicacion) {
            return true;
        }

        if ($canal === 'todos') {
            return ($this->preferencias_comunicacion['email'] ?? true) 
                || ($this->preferencias_comunicacion['sms'] ?? true);
        }

        return $this->preferencias_comunicacion[$canal] ?? true;
    }

    /**
     * Scope para excluir prospectos desuscritos.
     */
    public function scopeNoDesuscritos($query)
    {
        return $query->where('estado', '!=', 'desuscrito');
    }

    /**
     * Scope para prospectos desuscritos.
     */
    public function scopeDesuscritos($query)
    {
        return $query->where('estado', 'desuscrito');
    }

    /**
     * Scope para prospectos que pueden recibir emails.
     */
    public function scopePuedenRecibirEmail($query)
    {
        return $query->where('estado', '!=', 'desuscrito')
            ->where(function ($q) {
                $q->whereNull('preferencias_comunicacion')
                  ->orWhereRaw("(preferencias_comunicacion->>'email')::boolean = true");
            });
    }

    /**
     * Scope para prospectos que pueden recibir SMS.
     */
    public function scopePuedenRecibirSms($query)
    {
        return $query->where('estado', '!=', 'desuscrito')
            ->where(function ($q) {
                $q->whereNull('preferencias_comunicacion')
                  ->orWhereRaw("(preferencias_comunicacion->>'sms')::boolean = true");
            });
    }

    // =========================================================================
    // EMAIL INVÁLIDO - Scopes y Métodos
    // =========================================================================

    /**
     * Scope para prospectos con email válido (no marcado como inválido).
     */
    public function scopeConEmailValido($query)
    {
        return $query->where(function ($q) {
            $q->where('email_invalido', false)
              ->orWhereNull('email_invalido');
        });
    }

    /**
     * Scope para prospectos con email inválido.
     */
    public function scopeConEmailInvalido($query)
    {
        return $query->where('email_invalido', true);
    }

    /**
     * Scope para prospectos que pueden recibir emails (no desuscritos Y email válido).
     * Este es el scope a usar para envíos masivos.
     */
    public function scopeAptoParaEnvioEmail($query)
    {
        return $query->where('estado', '!=', 'desuscrito')
            ->where(function ($q) {
                $q->where('email_invalido', false)
                  ->orWhereNull('email_invalido');
            })
            ->whereNotNull('email')
            ->where('email', '!=', '');
    }

    /**
     * Verifica si el prospecto tiene un email válido para envíos.
     */
    public function tieneEmailValido(): bool
    {
        return !empty($this->email) 
            && !$this->email_invalido 
            && $this->estado !== 'desuscrito';
    }

    /**
     * Marca el email como inválido con un motivo.
     * 
     * @param string $motivo Razón por la que el email es inválido
     */
    public function marcarEmailInvalido(string $motivo): void
    {
        $this->update([
            'email_invalido' => true,
            'email_invalido_motivo' => $motivo,
            'email_invalido_at' => now(),
        ]);
    }

    /**
     * Rehabilita un email previamente marcado como inválido.
     * Útil si el usuario corrige su email.
     */
    public function rehabilitarEmail(): void
    {
        $this->update([
            'email_invalido' => false,
            'email_invalido_motivo' => null,
            'email_invalido_at' => null,
        ]);
    }

    /**
     * Verifica si el email fue marcado como inválido.
     */
    public function isEmailInvalido(): bool
    {
        return (bool) $this->email_invalido;
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
