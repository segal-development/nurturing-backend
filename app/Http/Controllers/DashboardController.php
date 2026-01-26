<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\Envio;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\OfertaInfocom;
use App\Models\Prospecto;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Obtener todas las estadísticas del dashboard en un solo endpoint
     */
    public function stats(): JsonResponse
    {
        $stats = [
            // Stats Cards
            'total_prospectos' => $this->getTotalProspectos(),
            'envios_hoy' => $this->getEnviosHoy(),
            'envios_programados' => $this->getEnviosProgramados(),
            'ofertas_activas' => $this->getOfertasActivas(),
            'tasa_entrega' => $this->getTasaEntrega(),

            // Gráficos
            'prospectos_por_flujo' => $this->getProspectosPorFlujo(),
            'envios_por_dia' => $this->getEnviosPorDia(),

            // Calidad de lista (cacheado 5 min)
            'calidad_emails' => $this->getCalidadEmails(),
        ];

        return response()->json($stats);
    }

    /**
     * Total de prospectos en el sistema
     */
    private function getTotalProspectos(): int
    {
        return Prospecto::count();
    }

    /**
     * Mensajes enviados hoy
     */
    private function getEnviosHoy(): int
    {
        return Envio::whereDate('fecha_enviado', Carbon::today())->count();
    }

    /**
     * Mensajes programados (pendientes de envío)
     * 
     * Calcula el total de envíos programados basándose en:
     * 1. Etapas de ejecución pendientes × prospectos en cada ejecución
     * 2. Envíos en tabla envios con estado pendiente/programado (legacy)
     */
    private function getEnviosProgramados(): int
    {
        // Método 1: Contar etapas pendientes en ejecuciones activas
        // Cada etapa pendiente generará envíos para todos los prospectos de esa ejecución
        $enviosPorEtapasPendientes = DB::table('flujo_ejecucion_etapas as fee')
            ->join('flujo_ejecuciones as fe', 'fe.id', '=', 'fee.flujo_ejecucion_id')
            ->where('fee.estado', 'pending')
            ->whereIn('fe.estado', ['in_progress', 'paused'])
            ->selectRaw('SUM(jsonb_array_length(fe.prospectos_ids::jsonb)) as total')
            ->value('total') ?? 0;

        // Método 2: Envíos legacy con estado pendiente/programado
        $enviosLegacy = Envio::where('estado', 'programado')
            ->orWhere('estado', 'pendiente')
            ->count();

        return (int) $enviosPorEtapasPendientes + $enviosLegacy;
    }

    /**
     * Ofertas con estado activo
     */
    private function getOfertasActivas(): int
    {
        return OfertaInfocom::where('activo', true)->count();
    }

    /**
     * Tasa de entrega en los últimos 30 días
     */
    private function getTasaEntrega(): float
    {
        $fechaInicio = Carbon::now()->subDays(30);

        $total = Envio::where('fecha_enviado', '>=', $fechaInicio)->count();

        if ($total === 0) {
            return 0.0;
        }

        $exitosos = Envio::where('fecha_enviado', '>=', $fechaInicio)
            ->whereIn('estado', ['enviado', 'entregado', 'exitoso'])
            ->count();

        return round(($exitosos / $total) * 100, 1);
    }

    /**
     * Distribución de prospectos por flujo
     */
    private function getProspectosPorFlujo(): array
    {
        $data = DB::table('flujos as f')
            ->leftJoin('prospecto_en_flujo as pf', 'pf.flujo_id', '=', 'f.id')
            ->select('f.nombre as flujo', DB::raw('COUNT(pf.id) as cantidad'))
            ->groupBy('f.id', 'f.nombre')
            ->orderBy('cantidad', 'desc')
            ->get();

        return $data->map(function ($item) {
            return [
                'flujo' => $item->flujo,
                'cantidad' => (int) $item->cantidad,
            ];
        })->toArray();
    }

    /**
     * Envíos por día (últimos 7 días)
     */
    private function getEnviosPorDia(): array
    {
        $fechaInicio = Carbon::now()->subDays(6)->startOfDay();

        $data = DB::table('envios')
            ->select(
                DB::raw('DATE(fecha_enviado) as fecha'),
                DB::raw('COUNT(CASE WHEN estado IN (\'enviado\', \'entregado\', \'exitoso\') THEN 1 END) as exitosos'),
                DB::raw('COUNT(CASE WHEN estado IN (\'fallido\', \'error\', \'rechazado\') THEN 1 END) as fallidos')
            )
            ->where('fecha_enviado', '>=', $fechaInicio)
            ->whereNotNull('fecha_enviado')
            ->groupBy(DB::raw('DATE(fecha_enviado)'))
            ->orderBy('fecha', 'asc')
            ->get();

        // Generar array con todos los últimos 7 días (incluso si no hay datos)
        $resultado = [];
        for ($i = 6; $i >= 0; $i--) {
            $fecha = Carbon::now()->subDays($i)->format('Y-m-d');

            $envioDelDia = $data->firstWhere('fecha', $fecha);

            $resultado[] = [
                'fecha' => $fecha,
                'exitosos' => $envioDelDia ? (int) $envioDelDia->exitosos : 0,
                'fallidos' => $envioDelDia ? (int) $envioDelDia->fallidos : 0,
            ];
        }

        return $resultado;
    }

    /**
     * Calidad de emails (válidos, inválidos, desuscritos)
     * Cacheado por 5 minutos para optimizar performance
     */
    private function getCalidadEmails(): array
    {
        return Cache::remember('dashboard:calidad_emails', 300, function () {
            $stats = DB::table('prospectos')
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as con_email,
                    SUM(CASE WHEN email_invalido = true THEN 1 ELSE 0 END) as invalidos,
                    SUM(CASE WHEN estado = 'desuscrito' THEN 1 ELSE 0 END) as desuscritos
                ")
                ->first();

            $total = (int) ($stats->total ?? 0);
            $conEmail = (int) ($stats->con_email ?? 0);
            $invalidos = (int) ($stats->invalidos ?? 0);
            $desuscritos = (int) ($stats->desuscritos ?? 0);
            $validos = $conEmail - $invalidos;

            // Costo por email desde tabla configuracion
            $costoEmail = (float) (Configuracion::get()->email_costo ?? 1.00);
            $ahorroEstimado = round($invalidos * $costoEmail, 2);

            return [
                'total_prospectos' => $total,
                'con_email' => $conEmail,
                'sin_email' => $total - $conEmail,
                'emails_validos' => $validos,
                'emails_invalidos' => $invalidos,
                'desuscritos' => $desuscritos,
                'tasa_validez' => $conEmail > 0 ? round(($validos / $conEmail) * 100, 1) : 0,
                'ahorro_estimado' => $ahorroEstimado,
                'costo_email' => $costoEmail,
            ];
        });
    }
}
