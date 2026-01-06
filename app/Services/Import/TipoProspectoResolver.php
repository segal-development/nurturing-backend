<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\TipoProspecto;
use Illuminate\Support\Collection;

/**
 * Resuelve el tipo de prospecto basado en el monto de deuda.
 * Cachea los tipos en memoria para evitar queries repetidas.
 * 
 * Single Responsibility: Solo resuelve tipos de prospecto.
 */
final class TipoProspectoResolver
{
    /** @var Collection<TipoProspecto> */
    private Collection $tipos;
    
    private bool $loaded = false;

    public function __construct()
    {
        $this->tipos = collect();
    }

    /**
     * Carga los tipos de prospecto activos.
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->tipos = TipoProspecto::activos()->ordenados()->get();
        $this->loaded = true;
    }

    /**
     * Encuentra el tipo de prospecto para un monto dado.
     */
    public function resolveByMonto(float $monto): ?TipoProspecto
    {
        if (!$this->loaded) {
            $this->load();
        }

        foreach ($this->tipos as $tipo) {
            if ($tipo->enRango($monto)) {
                return $tipo;
            }
        }

        return null;
    }

    /**
     * Obtiene el ID del tipo de prospecto para un monto.
     */
    public function resolveIdByMonto(float $monto): ?int
    {
        $tipo = $this->resolveByMonto($monto);
        return $tipo?->id;
    }

    public function getTiposCount(): int
    {
        return $this->tipos->count();
    }
}
