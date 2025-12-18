<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantillaMensaje extends Model
{
    use HasFactory;

    protected $table = 'plantillas_mensaje';

    protected $fillable = [
        'nombre',
        'asunto',
        'contenido',
        'tipo_canal',
        'variables_disponibles',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'variables_disponibles' => 'array',
            'activo' => 'boolean',
        ];
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaFlujo::class);
    }

    public function envios(): HasMany
    {
        return $this->hasMany(Envio::class);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorCanal($query, string $canal)
    {
        return $query->where('tipo_canal', $canal);
    }

    public function renderizar(array $datos): string
    {
        $contenido = $this->contenido;

        foreach ($datos as $variable => $valor) {
            $contenido = str_replace("{{$variable}}", (string) $valor, $contenido);
        }

        return $contenido;
    }

    public function renderizarAsunto(array $datos): ?string
    {
        if (! $this->asunto) {
            return null;
        }

        $asunto = $this->asunto;

        foreach ($datos as $variable => $valor) {
            $asunto = str_replace("{{$variable}}", (string) $valor, $asunto);
        }

        return $asunto;
    }

    public function getVariablesArray(): array
    {
        if (! $this->variables_disponibles) {
            return [
                'nombre',
                'email',
                'monto_deuda',
                'origen',
            ];
        }

        return $this->variables_disponibles;
    }
}
