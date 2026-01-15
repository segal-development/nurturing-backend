<?php

namespace App\Jobs;

use App\Models\Envio;
use App\Models\EnvioMensual;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job para agregar estadísticas mensuales de envíos.
 *
 * Pre-calcula totales mensuales para evitar COUNT(*) sobre millones de registros.
 * Diseñado para ejecutarse mensualmente via scheduler.
 *
 * Estrategia de agregación:
 * 1. Totales globales (flujo_id=NULL, origen=NULL) -> para dashboard general
 * 2. Por flujo (flujo_id=X, origen=NULL) -> para reportes por flujo
 * 3. Por origen (flujo_id=NULL, origen=X) -> para reportes por fuente
 *
 * @see EnvioMensual
 */
class AgregarEnviosMensualesJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $timeout = 1800; // 30 minutos
    public int $tries = 3;
    public int $uniqueFor = 1800;

    private const CHUNK_SIZE = 10000;

    public function __construct(
        public int $anio,
        public int $mes,
        public bool $forzarRecalculo = false
    ) {}

    public function uniqueId(): string
    {
        return "agregar-envios-mensuales-{$this->anio}-{$this->mes}";
    }

    public function handle(): void
    {
        $inicio = now();

        Log::info('📊 Iniciando agregación de envíos mensuales', [
            'anio' => $this->anio,
            'mes' => $this->mes,
            'forzar_recalculo' => $this->forzarRecalculo,
        ]);

        // Si ya existe y no forzamos recálculo, verificar si es necesario
        if (!$this->forzarRecalculo && $this->yaFueAgregado()) {
            Log::info('⏭️ Mes ya agregado, saltando...', [
                'anio' => $this->anio,
                'mes' => $this->mes,
            ]);
            return;
        }

        // Limpiar datos previos si estamos recalculando
        if ($this->forzarRecalculo) {
            $this->limpiarDatosPrevios();
        }

        // Rango de fechas del mes
        $fechaInicio = now()->setYear($this->anio)->setMonth($this->mes)->startOfMonth();
        $fechaFin = $fechaInicio->copy()->endOfMonth();

        // 1. Agregar totales globales
        $this->agregarTotalesGlobales($fechaInicio, $fechaFin);

        // 2. Agregar por flujo
        $this->agregarPorFlujo($fechaInicio, $fechaFin);

        // 3. Agregar por origen
        $this->agregarPorOrigen($fechaInicio, $fechaFin);

        $duracion = now()->diffInSeconds($inicio);

        Log::info('✅ Agregación de envíos mensuales completada', [
            'anio' => $this->anio,
            'mes' => $this->mes,
            'duracion_segundos' => $duracion,
        ]);
    }

    /**
     * Verifica si el mes ya fue agregado.
     */
    private function yaFueAgregado(): bool
    {
        return EnvioMensual::where('anio', $this->anio)
            ->where('mes', $this->mes)
            ->whereNull('flujo_id')
            ->whereNull('origen')
            ->exists();
    }

    /**
     * Limpia datos previos del mes para recalcular.
     */
    private function limpiarDatosPrevios(): void
    {
        EnvioMensual::where('anio', $this->anio)
            ->where('mes', $this->mes)
            ->delete();

        Log::info('🗑️ Datos previos eliminados para recálculo', [
            'anio' => $this->anio,
            'mes' => $this->mes,
        ]);
    }

    /**
     * Agrega totales globales del mes.
     */
    private function agregarTotalesGlobales($fechaInicio, $fechaFin): void
    {
        $stats = $this->calcularEstadisticas(
            Envio::query()
                ->whereBetween('created_at', [$fechaInicio, $fechaFin])
        );

        EnvioMensual::create([
            'anio' => $this->anio,
            'mes' => $this->mes,
            'flujo_id' => null,
            'origen' => null,
            ...$stats,
            'agregado_en' => now(),
        ]);

        Log::info('📈 Totales globales agregados', [
            'anio' => $this->anio,
            'mes' => $this->mes,
            'total_envios' => $stats['total_envios'],
        ]);
    }

    /**
     * Agrega estadísticas por flujo.
     */
    private function agregarPorFlujo($fechaInicio, $fechaFin): void
    {
        // Obtener flujos únicos que tienen envíos en el período
        $flujoIds = Envio::query()
            ->whereBetween('created_at', [$fechaInicio, $fechaFin])
            ->whereNotNull('flujo_id')
            ->distinct()
            ->pluck('flujo_id');

        foreach ($flujoIds as $flujoId) {
            $stats = $this->calcularEstadisticas(
                Envio::query()
                    ->whereBetween('created_at', [$fechaInicio, $fechaFin])
                    ->where('flujo_id', $flujoId)
            );

            EnvioMensual::create([
                'anio' => $this->anio,
                'mes' => $this->mes,
                'flujo_id' => $flujoId,
                'origen' => null,
                ...$stats,
                'agregado_en' => now(),
            ]);
        }

        Log::info('📈 Estadísticas por flujo agregadas', [
            'anio' => $this->anio,
            'mes' => $this->mes,
            'flujos_procesados' => $flujoIds->count(),
        ]);
    }

    /**
     * Agrega estadísticas por origen.
     */
    private function agregarPorOrigen($fechaInicio, $fechaFin): void
    {
        // Obtener orígenes únicos via importaciones de prospectos
        $origenes = DB::table('envios')
            ->join('prospectos', 'envios.prospecto_id', '=', 'prospectos.id')
            ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
            ->whereBetween('envios.created_at', [$fechaInicio, $fechaFin])
            ->distinct()
            ->pluck('importaciones.origen');

        foreach ($origenes as $origen) {
            if (empty($origen)) {
                continue;
            }

            $stats = $this->calcularEstadisticasPorOrigen($fechaInicio, $fechaFin, $origen);

            EnvioMensual::create([
                'anio' => $this->anio,
                'mes' => $this->mes,
                'flujo_id' => null,
                'origen' => $origen,
                ...$stats,
                'agregado_en' => now(),
            ]);
        }

        Log::info('📈 Estadísticas por origen agregadas', [
            'anio' => $this->anio,
            'mes' => $this->mes,
            'origenes_procesados' => $origenes->count(),
        ]);
    }

    /**
     * Calcula estadísticas para una query de envíos.
     */
    private function calcularEstadisticas($query): array
    {
        $result = $query->selectRaw('
            COUNT(*) as total_envios,
            SUM(CASE WHEN canal = \'email\' THEN 1 ELSE 0 END) as total_emails,
            SUM(CASE WHEN canal = \'sms\' THEN 1 ELSE 0 END) as total_sms,
            SUM(CASE WHEN estado IN (\'enviado\', \'abierto\', \'clickeado\') THEN 1 ELSE 0 END) as enviados_exitosos,
            SUM(CASE WHEN estado = \'fallido\' THEN 1 ELSE 0 END) as enviados_fallidos,
            SUM(CASE WHEN estado IN (\'abierto\', \'clickeado\') THEN 1 ELSE 0 END) as emails_abiertos,
            SUM(CASE WHEN estado = \'clickeado\' THEN 1 ELSE 0 END) as emails_clickeados
        ')->first();

        return [
            'total_envios' => (int) ($result->total_envios ?? 0),
            'total_emails' => (int) ($result->total_emails ?? 0),
            'total_sms' => (int) ($result->total_sms ?? 0),
            'enviados_exitosos' => (int) ($result->enviados_exitosos ?? 0),
            'enviados_fallidos' => (int) ($result->enviados_fallidos ?? 0),
            'emails_abiertos' => (int) ($result->emails_abiertos ?? 0),
            'emails_clickeados' => (int) ($result->emails_clickeados ?? 0),
            // Costos los dejamos en 0 por ahora - se pueden agregar después si hay tabla de costos
            'costo_total_emails' => 0,
            'costo_total_sms' => 0,
            'costo_total' => 0,
        ];
    }

    /**
     * Calcula estadísticas filtradas por origen (requiere join).
     */
    private function calcularEstadisticasPorOrigen($fechaInicio, $fechaFin, string $origen): array
    {
        $result = DB::table('envios')
            ->join('prospectos', 'envios.prospecto_id', '=', 'prospectos.id')
            ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
            ->whereBetween('envios.created_at', [$fechaInicio, $fechaFin])
            ->where('importaciones.origen', $origen)
            ->selectRaw('
                COUNT(*) as total_envios,
                SUM(CASE WHEN envios.canal = \'email\' THEN 1 ELSE 0 END) as total_emails,
                SUM(CASE WHEN envios.canal = \'sms\' THEN 1 ELSE 0 END) as total_sms,
                SUM(CASE WHEN envios.estado IN (\'enviado\', \'abierto\', \'clickeado\') THEN 1 ELSE 0 END) as enviados_exitosos,
                SUM(CASE WHEN envios.estado = \'fallido\' THEN 1 ELSE 0 END) as enviados_fallidos,
                SUM(CASE WHEN envios.estado IN (\'abierto\', \'clickeado\') THEN 1 ELSE 0 END) as emails_abiertos,
                SUM(CASE WHEN envios.estado = \'clickeado\' THEN 1 ELSE 0 END) as emails_clickeados
            ')
            ->first();

        return [
            'total_envios' => (int) ($result->total_envios ?? 0),
            'total_emails' => (int) ($result->total_emails ?? 0),
            'total_sms' => (int) ($result->total_sms ?? 0),
            'enviados_exitosos' => (int) ($result->enviados_exitosos ?? 0),
            'enviados_fallidos' => (int) ($result->enviados_fallidos ?? 0),
            'emails_abiertos' => (int) ($result->emails_abiertos ?? 0),
            'emails_clickeados' => (int) ($result->emails_clickeados ?? 0),
            'costo_total_emails' => 0,
            'costo_total_sms' => 0,
            'costo_total' => 0,
        ];
    }

    /**
     * Maneja fallos del job.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('❌ Error en agregación de envíos mensuales', [
            'anio' => $this->anio,
            'mes' => $this->mes,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
