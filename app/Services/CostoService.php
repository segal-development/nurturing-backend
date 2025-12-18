<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Envio;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use Illuminate\Support\Facades\DB;

/**
 * Service for calculating and managing flow execution costs.
 * 
 * Handles:
 * - Estimated costs before execution (based on etapas × prospectos)
 * - Real costs after execution (based on actual envios)
 * - Cost breakdowns by channel (email/sms)
 * - Dashboard statistics
 */
class CostoService
{
    private float $emailCosto;
    private float $smsCosto;

    public function __construct()
    {
        $config = Configuracion::get();
        $this->emailCosto = (float) $config->email_costo;
        $this->smsCosto = (float) $config->sms_costo;
    }

    /**
     * Get current pricing configuration.
     */
    public function getPrecios(): array
    {
        return [
            'email' => $this->emailCosto,
            'sms' => $this->smsCosto,
        ];
    }

    /**
     * Calculate cost for a single node/etapa based on its type.
     */
    public function getCostoNodo(string $tipoMensaje): float
    {
        return match (strtolower($tipoMensaje)) {
            'email' => $this->emailCosto,
            'sms' => $this->smsCosto,
            default => 0.0,
        };
    }

    /**
     * Calculate estimated cost for a flow before execution.
     * 
     * @param Flujo $flujo The flow to estimate
     * @param int $cantidadProspectos Number of prospects that will receive messages
     * @return array Detailed cost breakdown
     */
    public function calcularCostoEstimado(Flujo $flujo, int $cantidadProspectos): array
    {
        $etapas = DB::table('flujo_etapas')
            ->where('flujo_id', $flujo->id)
            ->select('id', 'tipo_mensaje', 'label')
            ->get();

        $totalEmails = 0;
        $totalSms = 0;
        $detalleEtapas = [];

        foreach ($etapas as $etapa) {
            $tipo = strtolower($etapa->tipo_mensaje ?? 'email');
            $costoUnitario = $this->getCostoNodo($tipo);
            $costoEtapa = $costoUnitario * $cantidadProspectos;

            if ($tipo === 'email') {
                $totalEmails++;
            } elseif ($tipo === 'sms') {
                $totalSms++;
            }

            $detalleEtapas[] = [
                'etapa_id' => $etapa->id,
                'label' => $etapa->label,
                'tipo' => $tipo,
                'costo_unitario' => $costoUnitario,
                'cantidad_envios' => $cantidadProspectos,
                'costo_total' => $costoEtapa,
            ];
        }

        $costoEmails = $totalEmails * $cantidadProspectos * $this->emailCosto;
        $costoSms = $totalSms * $cantidadProspectos * $this->smsCosto;
        $costoTotal = $costoEmails + $costoSms;

        return [
            'flujo_id' => $flujo->id,
            'flujo_nombre' => $flujo->nombre,
            'cantidad_prospectos' => $cantidadProspectos,
            'precios' => $this->getPrecios(),
            'resumen' => [
                'total_etapas_email' => $totalEmails,
                'total_etapas_sms' => $totalSms,
                'costo_emails' => round($costoEmails, 2),
                'costo_sms' => round($costoSms, 2),
                'costo_total' => round($costoTotal, 2),
            ],
            'detalle_etapas' => $detalleEtapas,
        ];
    }

    /**
     * Calculate real cost for a completed execution based on actual envios.
     * 
     * @param FlujoEjecucion $ejecucion The completed execution
     * @return array Detailed cost breakdown
     */
    public function calcularCostoReal(FlujoEjecucion $ejecucion): array
    {
        // Count actual sent emails and SMS
        $envioStats = Envio::where('flujo_id', $ejecucion->flujo_id)
            ->where('created_at', '>=', $ejecucion->created_at)
            ->where(function ($query) use ($ejecucion) {
                if ($ejecucion->fecha_fin) {
                    $query->where('created_at', '<=', $ejecucion->fecha_fin);
                }
            })
            ->whereIn('estado', ['enviado', 'abierto', 'clickeado']) // Only count successful sends
            ->select(
                DB::raw("COUNT(CASE WHEN canal = 'email' THEN 1 END) as total_emails"),
                DB::raw("COUNT(CASE WHEN canal = 'sms' THEN 1 END) as total_sms")
            )
            ->first();

        $totalEmails = $envioStats->total_emails ?? 0;
        $totalSms = $envioStats->total_sms ?? 0;

        $costoEmails = $totalEmails * $this->emailCosto;
        $costoSms = $totalSms * $this->smsCosto;
        $costoTotal = $costoEmails + $costoSms;

        return [
            'ejecucion_id' => $ejecucion->id,
            'flujo_id' => $ejecucion->flujo_id,
            'precios' => $this->getPrecios(),
            'total_emails_enviados' => $totalEmails,
            'total_sms_enviados' => $totalSms,
            'costo_emails' => round($costoEmails, 2),
            'costo_sms' => round($costoSms, 2),
            'costo_real' => round($costoTotal, 2),
        ];
    }

    /**
     * Update execution record with final costs.
     * Call this when a flow execution completes.
     */
    public function actualizarCostosEjecucion(FlujoEjecucion $ejecucion): FlujoEjecucion
    {
        $costos = $this->calcularCostoReal($ejecucion);

        $ejecucion->update([
            'costo_real' => $costos['costo_real'],
            'costo_emails' => $costos['costo_emails'],
            'costo_sms' => $costos['costo_sms'],
            'total_emails_enviados' => $costos['total_emails_enviados'],
            'total_sms_enviados' => $costos['total_sms_enviados'],
        ]);

        return $ejecucion->fresh();
    }

    /**
     * Save estimated cost when starting an execution.
     */
    public function guardarCostoEstimado(FlujoEjecucion $ejecucion, float $costoEstimado): void
    {
        $ejecucion->update(['costo_estimado' => $costoEstimado]);
    }

    /**
     * Get cost dashboard statistics.
     * 
     * @param string|null $fechaInicio Start date (Y-m-d)
     * @param string|null $fechaFin End date (Y-m-d)
     */
    public function getDashboardStats(?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $fechaInicio = $fechaInicio ?? now()->startOfMonth()->toDateString();
        $fechaFin = $fechaFin ?? now()->endOfMonth()->toDateString();

        // Total costs from completed executions
        $totalStats = FlujoEjecucion::where('estado', 'completed')
            ->whereBetween('created_at', [$fechaInicio, $fechaFin . ' 23:59:59'])
            ->select(
                DB::raw('SUM(costo_real) as costo_total'),
                DB::raw('SUM(costo_emails) as costo_emails'),
                DB::raw('SUM(costo_sms) as costo_sms'),
                DB::raw('SUM(total_emails_enviados) as total_emails'),
                DB::raw('SUM(total_sms_enviados) as total_sms'),
                DB::raw('COUNT(*) as total_ejecuciones')
            )
            ->first();

        // Costs by day
        $costosPorDia = FlujoEjecucion::where('estado', 'completed')
            ->whereBetween('created_at', [$fechaInicio, $fechaFin . ' 23:59:59'])
            ->whereNotNull('costo_real')
            ->select(
                DB::raw('DATE(created_at) as fecha'),
                DB::raw('SUM(costo_real) as costo_total'),
                DB::raw('SUM(costo_emails) as costo_emails'),
                DB::raw('SUM(costo_sms) as costo_sms'),
                DB::raw('COUNT(*) as ejecuciones')
            )
            ->groupBy('fecha')
            ->orderBy('fecha', 'desc')
            ->get();

        // Costs by flow
        $costosPorFlujo = FlujoEjecucion::where('estado', 'completed')
            ->whereBetween('flujo_ejecuciones.created_at', [$fechaInicio, $fechaFin . ' 23:59:59'])
            ->whereNotNull('costo_real')
            ->join('flujos', 'flujo_ejecuciones.flujo_id', '=', 'flujos.id')
            ->select(
                'flujos.id as flujo_id',
                'flujos.nombre as flujo_nombre',
                DB::raw('SUM(costo_real) as costo_total'),
                DB::raw('SUM(costo_emails) as costo_emails'),
                DB::raw('SUM(costo_sms) as costo_sms'),
                DB::raw('SUM(total_emails_enviados) as total_emails'),
                DB::raw('SUM(total_sms_enviados) as total_sms'),
                DB::raw('COUNT(*) as ejecuciones')
            )
            ->groupBy('flujos.id', 'flujos.nombre')
            ->orderByDesc('costo_total')
            ->get();

        // Estimated vs Real comparison for completed executions
        $comparacion = FlujoEjecucion::where('estado', 'completed')
            ->whereBetween('created_at', [$fechaInicio, $fechaFin . ' 23:59:59'])
            ->whereNotNull('costo_estimado')
            ->whereNotNull('costo_real')
            ->select(
                DB::raw('SUM(costo_estimado) as total_estimado'),
                DB::raw('SUM(costo_real) as total_real')
            )
            ->first();

        $diferenciaEstimadoReal = 0;
        if ($comparacion->total_estimado && $comparacion->total_real) {
            $diferenciaEstimadoReal = round(
                (($comparacion->total_real - $comparacion->total_estimado) / $comparacion->total_estimado) * 100,
                2
            );
        }

        return [
            'periodo' => [
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
            ],
            'precios_actuales' => $this->getPrecios(),
            'resumen' => [
                'costo_total' => round($totalStats->costo_total ?? 0, 2),
                'costo_emails' => round($totalStats->costo_emails ?? 0, 2),
                'costo_sms' => round($totalStats->costo_sms ?? 0, 2),
                'total_emails' => $totalStats->total_emails ?? 0,
                'total_sms' => $totalStats->total_sms ?? 0,
                'total_ejecuciones' => $totalStats->total_ejecuciones ?? 0,
            ],
            'comparacion_estimado_real' => [
                'total_estimado' => round($comparacion->total_estimado ?? 0, 2),
                'total_real' => round($comparacion->total_real ?? 0, 2),
                'diferencia_porcentaje' => $diferenciaEstimadoReal,
            ],
            'costos_por_dia' => $costosPorDia,
            'costos_por_flujo' => $costosPorFlujo,
        ];
    }
}
