<?php

namespace App\DTOs;

use App\Models\Flujo;

/**
 * DTO que encapsula los criterios para seleccionar prospectos.
 *
 * En lugar de pasar un array con 350k+ IDs al Job (que consume memoria y
 * hace el payload enorme), pasamos los criterios de la query.
 * El Job construye la query y procesa en chunks sin cargar todo en memoria.
 */
final readonly class CriteriosSeleccionProspectos
{
    /**
     * @param  string  $origen  Origen de la importación (csv, api, etc.)
     * @param  int|null  $tipoProspectoId  ID del tipo de prospecto (null = todos)
     * @param  bool  $selectAllFromOrigin  Si debe seleccionar todos del origen
     * @param  array  $prospectoIds  IDs específicos de prospectos (para selección manual)
     * @param  array  $loteIds  IDs de lotes específicos
     * @param  array  $metadataFilters  Filtros sobre campos de metadata (ej: ['nivel_deuda' => 'alta'])
     */
    public function __construct(
        public string $origen,
        public ?int $tipoProspectoId,
        public bool $selectAllFromOrigin,
        public array $prospectoIds = [],
        public array $loteIds = [],
        public array $metadataFilters = [],
    ) {}

    /**
     * Crea criterios desde un Flujo para seleccionar todos los prospectos del origen.
     */
    public static function fromFlujoSelectAll(Flujo $flujo): self
    {
        // Si el tipo es "Todos", no filtrar por tipo
        $tipoProspectoId = null;
        if ($flujo->tipoProspecto && ! $flujo->tipoProspecto->esTipoTodos()) {
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
     * Indica si se debe filtrar por lotes específicos.
     */
    public function usarFiltroLotes(): bool
    {
        return ! empty($this->loteIds);
    }

    /**
     * Indica si se debe filtrar por campos de metadata.
     */
    public function usarFiltroMetadata(): bool
    {
        return ! empty($this->metadataFilters);
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
            'lote_ids' => $this->loteIds,
            'metadata_filters' => $this->metadataFilters,
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
            loteIds: $data['lote_ids'] ?? [],
            metadataFilters: $data['metadata_filters'] ?? [],
        );
    }
}
