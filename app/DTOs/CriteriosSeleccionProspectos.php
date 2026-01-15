<?php

namespace App\DTOs;

use App\Models\Flujo;
use App\Models\TipoProspecto;

/**
 * DTO que encapsula los criterios para seleccionar prospectos.
 *
 * En lugar de pasar un array con 350k+ IDs al Job (que consume memoria y
 * hace el payload enorme), pasamos los criterios de la query.
 * El Job construye la query y procesa en chunks sin cargar todo en memoria.
 */
final readonly class CriteriosSeleccionProspectos
{
    public function __construct(
        public string $origen,
        public ?int $tipoProspectoId,
        public bool $selectAllFromOrigin,
        public array $prospectoIds = [],
    ) {}

    /**
     * Crea criterios desde un Flujo para seleccionar todos los prospectos del origen.
     */
    public static function fromFlujoSelectAll(Flujo $flujo): self
    {
        // Si el tipo es "Todos", no filtrar por tipo
        $tipoProspectoId = null;
        if ($flujo->tipoProspecto && !$flujo->tipoProspecto->esTipoTodos()) {
            $tipoProspectoId = $flujo->tipo_prospecto_id;
        }

        return new self(
            origen: $flujo->origen,
            tipoProspectoId: $tipoProspectoId,
            selectAllFromOrigin: true,
            prospectoIds: [],
        );
    }

    /**
     * Crea criterios con IDs específicos (para selección manual pequeña).
     */
    public static function fromProspectoIds(array $prospectoIds): self
    {
        return new self(
            origen: '',
            tipoProspectoId: null,
            selectAllFromOrigin: false,
            prospectoIds: $prospectoIds,
        );
    }

    /**
     * Indica si se debe usar query por criterios o por IDs.
     */
    public function usarQueryPorCriterios(): bool
    {
        return $this->selectAllFromOrigin && empty($this->prospectoIds);
    }

    /**
     * Convierte a array para serialización en el Job.
     */
    public function toArray(): array
    {
        return [
            'origen' => $this->origen,
            'tipo_prospecto_id' => $this->tipoProspectoId,
            'select_all_from_origin' => $this->selectAllFromOrigin,
            'prospecto_ids' => $this->prospectoIds,
        ];
    }

    /**
     * Reconstruye desde array (cuando el Job se deserializa).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            origen: $data['origen'] ?? '',
            tipoProspectoId: $data['tipo_prospecto_id'] ?? null,
            selectAllFromOrigin: $data['select_all_from_origin'] ?? false,
            prospectoIds: $data['prospecto_ids'] ?? [],
        );
    }
}
