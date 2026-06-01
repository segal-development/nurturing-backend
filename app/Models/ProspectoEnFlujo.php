<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        'fecha_ingreso',
        'completado',
        'cancelado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_proxima_etapa' => 'datetime',
            'fecha_ingreso' => 'date',
            'completado' => 'boolean',
            'cancelado' => 'boolean',
        ];
    }

    /**
     * Punto único de creación de filas en prospecto_en_flujo.
     *
     * Encapsula: normalización de canal, cálculo de fecha_ingreso según origen del flujo,
     * defaults consistentes y estrategia batch con fallback individual ante fallo (ej. PK collision).
     *
     * @param  Flujo         $flujo          El flujo al que se asignan los prospectos.
     * @param  int[]         $prospectoIds   IDs ya filtrados de duplicados por el caller si aplica.
     * @param  string        $canal          Canal a asignar. 'ambos' se normaliza a 'email'.
     * @param  Carbon        $now            Momento de referencia compartido con fecha_inicio.
     * @param  string        $estado         'pendiente' | 'en_proceso' (default: 'pendiente').
     * @param  string|null   $ultimaEtapaNodeId  Solo Perpetua lo setea explícitamente (default: null).
     * @param  int           $chunkSize      Tamaño del batch interno (default: 1000).
     * @return int           Cantidad de filas insertadas exitosamente.
     */
    public static function crearBatch(
        Flujo $flujo,
        array $prospectoIds,
        string $canal,
        Carbon $now,
        string $estado = 'pendiente',
        ?string $ultimaEtapaNodeId = null,
        int $chunkSize = 1000,
    ): int {
        if (empty($prospectoIds)) {
            return 0;
        }

        // RF-6: filtrar prospectos que ya existen en el flujo (cualquier estado, no solo activos).
        // No hay unique constraint en (prospecto_id, flujo_id), así que la dedup debe hacerse
        // en PHP explícitamente — el INSERT no fallará solo ante un par duplicado.
        $existentes = DB::table('prospecto_en_flujo')
            ->where('flujo_id', $flujo->id)
            ->whereIn('prospecto_id', $prospectoIds)
            ->pluck('prospecto_id')
            ->toArray();

        $prospectoIds = array_values(array_diff($prospectoIds, $existentes));

        if (empty($prospectoIds)) {
            return 0;
        }

        // RF-3: normalizar canal
        $canalNormalizado = $canal === 'ambos' ? 'email' : $canal;

        // RF-2: fecha_ingreso delegada íntegramente a fechaIngresoInicial (string Y-m-d o null)
        $fechaIngreso = $flujo->fechaIngresoInicial($now);

        $nowStr = $now->toDateTimeString();

        $insertados = 0;

        foreach (array_chunk($prospectoIds, $chunkSize) as $chunk) {
            $rows = array_map(fn (int $pid) => [
                'flujo_id'             => $flujo->id,
                'prospecto_id'         => $pid,
                'canal_asignado'       => $canalNormalizado,
                'estado'               => $estado,
                'etapa_actual_id'      => null,
                'ultima_etapa_node_id' => $ultimaEtapaNodeId,
                'fecha_inicio'         => $nowStr,
                'fecha_ingreso'        => $fechaIngreso,
                'completado'           => false,
                'cancelado'            => false,
                'created_at'           => $nowStr,
                'updated_at'           => $nowStr,
            ], $chunk);

            try {
                // RF-5: intento batch
                DB::table('prospecto_en_flujo')->insert($rows);
                $insertados += count($rows);
            } catch (\Throwable $e) {
                // RF-5: fallback individual — swallow por fila, no aborta el resto
                foreach ($rows as $row) {
                    try {
                        DB::table('prospecto_en_flujo')->insert($row);
                        $insertados++;
                    } catch (\Throwable $rowException) {
                        Log::debug('crearBatch: fila ignorada en fallback individual', [
                            'prospecto_id' => $row['prospecto_id'],
                            'flujo_id'     => $row['flujo_id'],
                            'error'        => $rowException->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $insertados;
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
