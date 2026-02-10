<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalApiSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'endpoint_url',
        'auth_type',
        'auth_token',
        'headers',
        'field_mapping',
        'clasificacion_field',
        'clasificacion_values',
        'sync_filters',
        'sync_frequency',
        'lote_prefix',
        'is_active',
        'last_synced_at',
        'last_sync_count',
        'last_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'field_mapping' => 'array',
            'clasificacion_values' => 'array',
            'sync_filters' => 'array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'auth_token' => 'encrypted',
        ];
    }

    /**
     * Importaciones generadas desde esta fuente externa.
     */
    public function importaciones(): HasMany
    {
        return $this->hasMany(Importacion::class);
    }

    /**
     * Lotes generados desde esta fuente externa.
     */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /**
     * Scope para fuentes activas.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Verifica si la fuente está activa.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Obtiene el mapeo de campos, con valores por defecto si no está configurado.
     * El mapeo define cómo los campos de la API se traducen a campos de Prospecto.
     */
    public function getFieldMappingWithDefaults(): array
    {
        $defaults = [
            'nombre' => 'nombre',
            'rut' => 'rut',
            'email' => 'email',
            'telefono' => 'telefono',
            'monto_deuda' => 'monto_deuda',
            'url_informe' => 'url_informe',
        ];

        return array_merge($defaults, $this->field_mapping ?? []);
    }

    /**
     * Obtiene los headers HTTP para la petición, incluyendo autenticación.
     */
    public function getRequestHeaders(): array
    {
        $headers = $this->headers ?? [];

        // Agregar header de autenticación según el tipo
        switch ($this->auth_type) {
            case 'bearer':
                $headers['Authorization'] = 'Bearer '.$this->auth_token;
                break;
            case 'api_key':
                $headers['X-API-Key'] = $this->auth_token;
                break;
            case 'basic':
                $headers['Authorization'] = 'Basic '.$this->auth_token;
                break;
        }

        return $headers;
    }

    /**
     * Marca la fuente como sincronizada exitosamente.
     */
    public function markAsSynced(int $count): void
    {
        $this->update([
            'last_synced_at' => now(),
            'last_sync_count' => $count,
            'last_sync_error' => null,
        ]);
    }

    /**
     * Marca la fuente con error de sincronización.
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'last_synced_at' => now(),
            'last_sync_error' => $error,
        ]);
    }

    /**
     * Genera el nombre del lote para un valor de clasificación.
     * Ej: "IC_form_2026-02-10"
     */
    public function generarNombreLote(string $clasificacionValue): string
    {
        $prefix = $this->lote_prefix ?: strtoupper(substr($this->name, 0, 3));
        $fecha = now()->format('Y-m-d');

        return "{$prefix}_{$clasificacionValue}_{$fecha}";
    }

    /**
     * Verifica si la fuente tiene configurado un campo de clasificación.
     */
    public function tieneClasificacion(): bool
    {
        return ! empty($this->clasificacion_field);
    }

    /**
     * Obtiene o crea un lote para un valor de clasificación específico.
     */
    public function obtenerOCrearLote(string $clasificacionValue, int $userId): Lote
    {
        $nombreLote = $this->generarNombreLote($clasificacionValue);

        return Lote::firstOrCreate(
            [
                'external_api_source_id' => $this->id,
                'clasificacion_value' => $clasificacionValue,
                'nombre' => $nombreLote,
            ],
            [
                'user_id' => $userId,
                'estado' => 'abierto',
            ]
        );
    }
}
