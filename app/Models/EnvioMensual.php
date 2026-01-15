<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Modelo para estadísticas agregadas de envíos por mes.
 *
 * Esta tabla almacena contadores pre-calculados para evitar
 * hacer COUNT(*) sobre millones de registros en reportes.
 */
class EnvioMensual extends Model
{
    protected $table = 'envios_mensuales';

    protected $fillable = [
        'anio',
        'mes',
        'flujo_id',
        'origen',
        'total_envios',
        'total_emails',
        'total_sms',
        'enviados_exitosos',
        'enviados_fallidos',
        'emails_abiertos',
        'emails_clickeados',
        'costo_total_emails',
        'costo_total_sms',
        'costo_total',
        'agregado_en',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'total_envios' => 'integer',
            'total_emails' => 'integer',
            'total_sms' => 'integer',
            'enviados_exitosos' => 'integer',
            'enviados_fallidos' => 'integer',
            'emails_abiertos' => 'integer',
            'emails_clickeados' => 'integer',
            'costo_total_emails' => 'decimal:2',
            'costo_total_sms' => 'decimal:2',
            'costo_total' => 'decimal:2',
            'agregado_en' => 'datetime',
        ];
    }

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function flujo(): BelongsTo
    {
        return $this->belongsTo(Flujo::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Filtra por año.
     */
    public function scopeDelAnio($query, int $anio)
    {
        return $query->where('anio', $anio);
    }

    /**
     * Filtra por mes específico.
     */
    public function scopeDelMes($query, int $anio, int $mes)
    {
        return $query->where('anio', $anio)->where('mes', $mes);
    }

    /**
     * Filtra por rango de meses.
     */
    public function scopeEntreMeses($query, int $anioInicio, int $mesInicio, int $anioFin, int $mesFin)
    {
        return $query->where(function ($q) use ($anioInicio, $mesInicio, $anioFin, $mesFin) {
            $q->where(function ($q2) use ($anioInicio, $mesInicio) {
                $q2->where('anio', '>', $anioInicio)
                   ->orWhere(fn($q3) => $q3->where('anio', $anioInicio)->where('mes', '>=', $mesInicio));
            })->where(function ($q2) use ($anioFin, $mesFin) {
                $q2->where('anio', '<', $anioFin)
                   ->orWhere(fn($q3) => $q3->where('anio', $anioFin)->where('mes', '<=', $mesFin));
            });
        });
    }

    /**
     * Solo totales globales (sin desglose por flujo/origen).
     */
    public function scopeTotalesGlobales($query)
    {
        return $query->whereNull('flujo_id')->whereNull('origen');
    }

    /**
     * Desglose por flujo.
     */
    public function scopePorFlujo($query, int $flujoId)
    {
        return $query->where('flujo_id', $flujoId);
    }

    /**
     * Desglose por origen.
     */
    public function scopePorOrigen($query, string $origen)
    {
        return $query->where('origen', $origen);
    }

    // =========================================================================
    // MÉTODOS DE AGREGACIÓN
    // =========================================================================

    /**
     * Obtiene o crea un registro para el mes/flujo/origen especificado.
     */
    public static function obtenerOCrear(int $anio, int $mes, ?int $flujoId = null, ?string $origen = null): self
    {
        return self::firstOrCreate(
            [
                'anio' => $anio,
                'mes' => $mes,
                'flujo_id' => $flujoId,
                'origen' => $origen,
            ],
            [
                'total_envios' => 0,
                'total_emails' => 0,
                'total_sms' => 0,
                'enviados_exitosos' => 0,
                'enviados_fallidos' => 0,
                'emails_abiertos' => 0,
                'emails_clickeados' => 0,
                'costo_total_emails' => 0,
                'costo_total_sms' => 0,
                'costo_total' => 0,
            ]
        );
    }

    /**
     * Incrementa contadores atómicamente.
     */
    public function incrementarContadores(array $incrementos): void
    {
        $updates = [];

        foreach ($incrementos as $campo => $valor) {
            if ($valor > 0 && in_array($campo, $this->fillable)) {
                $updates[$campo] = DB::raw("{$campo} + {$valor}");
            }
        }

        if (!empty($updates)) {
            $updates['agregado_en'] = now();
            $this->update($updates);
        }
    }

    // =========================================================================
    // HELPERS PARA REPORTES
    // =========================================================================

    /**
     * Obtiene resumen anual.
     */
    public static function resumenAnual(int $anio): array
    {
        $datos = self::delAnio($anio)
            ->totalesGlobales()
            ->selectRaw('
                SUM(total_envios) as total_envios,
                SUM(total_emails) as total_emails,
                SUM(total_sms) as total_sms,
                SUM(enviados_exitosos) as enviados_exitosos,
                SUM(enviados_fallidos) as enviados_fallidos,
                SUM(emails_abiertos) as emails_abiertos,
                SUM(emails_clickeados) as emails_clickeados,
                SUM(costo_total) as costo_total
            ')
            ->first();

        return [
            'anio' => $anio,
            'total_envios' => (int) ($datos->total_envios ?? 0),
            'total_emails' => (int) ($datos->total_emails ?? 0),
            'total_sms' => (int) ($datos->total_sms ?? 0),
            'enviados_exitosos' => (int) ($datos->enviados_exitosos ?? 0),
            'enviados_fallidos' => (int) ($datos->enviados_fallidos ?? 0),
            'emails_abiertos' => (int) ($datos->emails_abiertos ?? 0),
            'emails_clickeados' => (int) ($datos->emails_clickeados ?? 0),
            'costo_total' => (float) ($datos->costo_total ?? 0),
        ];
    }

    /**
     * Obtiene tendencia mensual (últimos N meses).
     */
    public static function tendenciaMensual(int $meses = 12): array
    {
        $fechaInicio = now()->subMonths($meses - 1)->startOfMonth();

        return self::totalesGlobales()
            ->where(function ($q) use ($fechaInicio) {
                $q->where('anio', '>', $fechaInicio->year)
                  ->orWhere(fn($q2) => $q2->where('anio', $fechaInicio->year)->where('mes', '>=', $fechaInicio->month));
            })
            ->orderBy('anio')
            ->orderBy('mes')
            ->get()
            ->map(fn($item) => [
                'periodo' => sprintf('%d-%02d', $item->anio, $item->mes),
                'total_envios' => $item->total_envios,
                'total_emails' => $item->total_emails,
                'total_sms' => $item->total_sms,
                'costo_total' => $item->costo_total,
            ])
            ->toArray();
    }
}
