<?php

namespace App\Console\Commands;

use App\Models\ExternalApiSource;
use App\Models\Prospecto;
use App\Services\GrupoDeudaApiSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Reconcilia los datos crudos de la API de Sysgal contra lo que ingerimos.
 *
 * Read-only: NO persiste nada. Trae las filas del endpoint, las clasifica con el
 * MISMO criterio que el sync y las cruza por Id contra nuestros prospectos. El total
 * de la API queda 100% explicado: cada fila cae en exactamente una categoría, de modo
 * que la suma del desglose iguala el Total de Sysgal.
 *
 * Uso:
 *   php artisan nurturing:reconcile-sysgal --desde=2026-05-01 --hasta=2026-05-22
 *   php artisan nurturing:reconcile-sysgal --endpoint=clientes-ingreso --desde=2026-05-01
 *   php artisan nurturing:reconcile-sysgal --json
 *
 * IMPORTANTE: correr desde la VM nurturing-worker (IP whitelisteada en SYSGAL).
 * Desde otro host la API responde 403.
 */
class ReconcileSysgalCommand extends Command
{
    protected $signature = 'nurturing:reconcile-sysgal
                            {--endpoint=contratos : Endpoint a reconciliar (contratos, clientes-ingreso)}
                            {--desde= : Fecha desde (Y-m-d). Default: hoy 00:00}
                            {--hasta= : Fecha hasta (Y-m-d). Default: ahora}
                            {--json : Salida en JSON para consumo automático}';

    protected $description = 'Reconcilia la API de Sysgal contra los prospectos ingeridos (read-only)';

    private GrupoDeudaApiSyncService $syncService;

    public function __construct()
    {
        parent::__construct();
        $this->syncService = new GrupoDeudaApiSyncService;
    }

    public function handle(): int
    {
        $endpoint = (string) $this->option('endpoint');
        $supported = [
            GrupoDeudaApiSyncService::ENDPOINT_CONTRATOS_NUEVOS,
            GrupoDeudaApiSyncService::ENDPOINT_CLIENTES_INGRESO,
        ];

        if (! in_array($endpoint, $supported, true)) {
            $this->error("Endpoint no soportado: {$endpoint}. Válidos: ".implode(', ', $supported));

            return self::FAILURE;
        }

        $mapping = $this->syncService->reconciliationMapping($endpoint);

        $source = ExternalApiSource::where('name', $mapping['source_name'])->first();
        if ($source === null) {
            $this->error("No existe la fuente '{$mapping['source_name']}'. ¿Está seedeada?");

            return self::FAILURE;
        }

        $desde = $this->option('desde')
            ? Carbon::parse($this->option('desde'))->startOfDay()
            : now()->startOfDay();
        $hasta = $this->option('hasta')
            ? Carbon::parse($this->option('hasta'))->endOfDay()
            : now();

        if (! $this->option('json')) {
            $this->info("Reconciliando '{$endpoint}' [{$desde->format('Y-m-d H:i')} → {$hasta->format('Y-m-d H:i')}]");
        }

        try {
            $rows = $this->syncService->fetchRowsForReconciliation($source, $endpoint, $desde, $hasta);
        } catch (\Throwable $e) {
            $this->error('Error llamando a Sysgal: '.$e->getMessage());
            $this->warn('Si es un 403: este host no está en el whitelist de SYSGAL. Corré el comando desde la VM nurturing-worker.');

            return self::FAILURE;
        }

        $resultado = $this->reconciliar($endpoint, $source, $mapping, $rows);

        if ($this->option('json')) {
            $this->line((string) json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($resultado);

        return $resultado['cuadra'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{meta_endpoint: string, id_field: string, source_name: string}  $mapping
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function reconciliar(string $endpoint, ExternalApiSource $source, array $mapping, array $rows): array
    {
        $total = count($rows);

        // IDs que SÍ tenemos en BD para este endpoint (cruce por metadata->id_field).
        $idsEnBd = Prospecto::query()
            ->where('metadata->endpoint', $mapping['meta_endpoint'])
            ->pluck('metadata->'.$mapping['id_field'])
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (string) $v)
            ->flip();

        $tally = [
            'ingresado' => 0,
            GrupoDeudaApiSyncService::RECON_SIN_CONTACTO => 0,
            GrupoDeudaApiSyncService::RECON_SIN_NOMBRE => 0,
            GrupoDeudaApiSyncService::RECON_SIN_TIPO => 0,
            'duplicado' => 0,
            'faltante' => 0,
        ];

        $contactablesNoIngresados = [];

        foreach ($rows as $row) {
            $id = $row['Id'] ?? null;

            if ($id !== null && $idsEnBd->has((string) $id)) {
                $tally['ingresado']++;

                continue;
            }

            $categoria = $this->syncService->classifyApiRow($endpoint, $row, $source);

            if ($categoria !== GrupoDeudaApiSyncService::RECON_CONTACTABLE) {
                $tally[$categoria]++;

                continue;
            }

            // Contactable pero su Id no está en BD: puede ser duplicado (misma persona,
            // otro contrato) o un faltante real. Se resuelve por email/teléfono.
            $contactablesNoIngresados[] = [
                'id' => $id,
                'contacto' => $this->syncService->extractContact($endpoint, $row, $source),
            ];
        }

        $faltantes = $this->resolverContactables($contactablesNoIngresados, $tally);

        $explicado = array_sum($tally);

        return [
            'endpoint' => $endpoint,
            'sysgal_total' => $total,
            'desglose' => $tally,
            'explicado' => $explicado,
            'sin_explicar' => $total - $explicado,
            'cuadra' => $explicado === $total,
            'faltantes_count' => $tally['faltante'],
            'faltantes_ids' => array_slice(array_values(array_filter(array_column($faltantes, 'id'))), 0, 50),
        ];
    }

    /**
     * Divide los contactables-no-ingresados en duplicados (ya existe un prospecto con
     * ese email/teléfono) vs faltantes reales. Muta $tally por referencia.
     *
     * @param  array<int, array{id: mixed, contacto: array{email: ?string, telefono: ?string}}>  $items
     * @param  array<string, int>  $tally
     * @return array<int, array{id: mixed, contacto: array{email: ?string, telefono: ?string}}> Faltantes reales
     */
    private function resolverContactables(array $items, array &$tally): array
    {
        if (empty($items)) {
            return [];
        }

        $emails = collect($items)->pluck('contacto.email')->filter()->unique()->values()->all();
        $telefonos = collect($items)->pluck('contacto.telefono')->filter()->unique()->values()->all();

        // Sin emails ni teléfonos no hay forma de matchear: todos son faltantes.
        if (empty($emails) && empty($telefonos)) {
            foreach ($items as $item) {
                $tally['faltante']++;
            }

            return $items;
        }

        $existentes = Prospecto::query()
            ->where(function ($q) use ($emails, $telefonos) {
                if (! empty($emails)) {
                    $q->orWhereIn('email', $emails);
                }
                if (! empty($telefonos)) {
                    $q->orWhereIn('telefono', $telefonos);
                }
            })
            ->get(['email', 'telefono']);

        $emailSet = $existentes->pluck('email')->filter()->flip();
        $telSet = $existentes->pluck('telefono')->filter()->flip();

        $faltantes = [];

        foreach ($items as $item) {
            $email = $item['contacto']['email'];
            $telefono = $item['contacto']['telefono'];

            $esDuplicado = ($email !== null && $emailSet->has($email))
                || ($telefono !== null && $telSet->has($telefono));

            if ($esDuplicado) {
                $tally['duplicado']++;
            } else {
                $tally['faltante']++;
                $faltantes[] = $item;
            }
        }

        return $faltantes;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function render(array $r): void
    {
        $d = $r['desglose'];

        $this->table(
            ['Categoría', 'Cantidad'],
            [
                ['✅ Ingresados (cruce por Id)', $d['ingresado']],
                ['⏭️  Sin email/teléfono', $d[GrupoDeudaApiSyncService::RECON_SIN_CONTACTO]],
                ['⏭️  Sin nombre', $d[GrupoDeudaApiSyncService::RECON_SIN_NOMBRE]],
                ['⏭️  Sin tipo (monto fuera de rango)', $d[GrupoDeudaApiSyncService::RECON_SIN_TIPO]],
                ['🔁 Duplicados (misma persona)', $d['duplicado']],
                ['🔴 FALTANTES (sin explicación)', $d['faltante']],
                ['─────────────────────────────', '────'],
                ['Σ Explicado', $r['explicado']],
                ['Sysgal Total (API)', $r['sysgal_total']],
            ]
        );

        if ($r['cuadra']) {
            $this->info("✅ CUADRA: los {$r['sysgal_total']} registros de Sysgal están 100% explicados.");
        } else {
            $this->error("❌ NO CUADRA: {$r['sin_explicar']} registros sin explicar. Hay un caso de clasificación no contemplado.");
        }

        if ($r['faltantes_count'] > 0) {
            $this->newLine();
            $this->warn("🔴 {$r['faltantes_count']} registros contactables que NO ingresaron y NO son duplicados.");
            $this->warn('Estos son el problema real de ingesta. IDs (primeros 50):');
            $this->line(implode(', ', $r['faltantes_ids']));
        }
    }
}
