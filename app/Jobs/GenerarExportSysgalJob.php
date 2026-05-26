<?php

namespace App\Jobs;

use App\Models\ExternalApiSource;
use App\Services\GrupoDeudaApiSyncService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Genera, on-demand, el export/reconcile de SYSGAL (contratos o clientes-ingreso) para
 * un rango de fechas arbitrario que el usuario elige en el dashboard.
 *
 * Por qué un job (y no la API directo): la API/Cloud Run NO está whitelisteada en SYSGAL.
 * Solo la VM puede consultar SYSGAL. Este job se despacha a la cola 'default', que SOLO
 * procesa la VM (whitelisteada), así que la llamada a SYSGAL funciona. El resultado
 * (conteo + CSV) se deja en la tabla `cache` con key literal 'export-sysgal:{token}',
 * compartida con la API (Cloud SQL), que la lee para el status y la descarga.
 */
class GenerarExportSysgalJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(
        public string $token,
        public string $tipo,   // 'contratos' | 'clientes-ingreso'
        public string $desde,  // Y-m-d
        public string $hasta,  // Y-m-d
    ) {
        $this->onQueue('default'); // la VM procesa 'default' (única whitelisteada en SYSGAL)
    }

    public function handle(GrupoDeudaApiSyncService $svc): void
    {
        $sourceName = $this->tipo === 'contratos' ? 'grupo_deuda_contratos' : 'grupo_deuda_clientes_ingreso';
        $src = ExternalApiSource::where('name', $sourceName)->first();

        if (! $src) {
            $this->store(['estado' => 'error', 'error' => "Fuente {$sourceName} no encontrada"]);

            return;
        }

        $desde = Carbon::parse($this->desde)->startOfDay();
        $hasta = Carbon::parse($this->hasta)->endOfDay();

        $rows = $svc->fetchRowsForReconciliation($src, $this->tipo, $desde, $hasta);

        $this->store([
            'estado' => 'listo',
            'count' => count($rows),
            'tipo' => $this->tipo,
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'csv' => $this->buildCsv($this->tipo, $rows),
            'generado_at' => now()->toIso8601String(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->store([
            'estado' => 'error',
            'error' => 'No se pudo generar el export: '.$e->getMessage(),
        ]);
    }

    private function store(array $payload): void
    {
        DB::table('cache')->updateOrInsert(
            ['key' => 'export-sysgal:'.$this->token],
            ['value' => json_encode($payload), 'expiration' => now()->addHours(2)->timestamp]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildCsv(string $tipo, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel

        if ($tipo === 'contratos') {
            fputcsv($fh, ['Id', 'Cliente', 'Rut', 'Email', 'Telefono', 'Monto', 'Cuotas', 'Vigencia_Inicio', 'Vigencia_Termino', 'Vendedor', 'Vendedor_Email']);
            foreach ($rows as $r) {
                fputcsv($fh, [$r['Id'] ?? '', $r['Cliente'] ?? '', $r['Rut'] ?? '', $r['Email'] ?? '', $r['Telefono'] ?? '', $r['Monto'] ?? '', $r['Cuotas'] ?? '', $r['Vigencia']['Inicio'] ?? '', $r['Vigencia']['Termino'] ?? '', $r['Vendedor']['Nombre'] ?? '', $r['Vendedor']['Email'] ?? '']);
            }
        } else {
            fputcsv($fh, ['Id', 'Nombre', 'Email', 'Telefono', 'Abogado', 'Abogado_Email']);
            foreach ($rows as $r) {
                $nombre = trim(($r['Nombre'] ?? '').' '.($r['Apellido_Paterno'] ?? '').' '.($r['Apellido_Materno'] ?? ''));
                $abogado = trim(($r['Abogado']['Nombre'] ?? '').' '.($r['Abogado']['Apellido_Paterno'] ?? ''));
                fputcsv($fh, [$r['Id'] ?? '', $nombre, $r['Email'] ?? '', $r['Telefono'] ?? '', $abogado, $r['Abogado']['Email'] ?? '']);
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }
}
