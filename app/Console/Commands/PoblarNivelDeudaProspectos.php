<?php

namespace App\Console\Commands;

use App\Models\Prospecto;
use App\Services\SysgalApiSyncService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Comando para poblar nivel_deuda en prospectos de SYSGAL existentes.
 *
 * Solo procesa prospectos que vienen de lotes SYSGAL (tienen monto_deuda de la API).
 * Calcula nivel_deuda basado en monto_deuda existente.
 */
class PoblarNivelDeudaProspectos extends Command
{
    protected $signature = 'prospectos:poblar-nivel-deuda
                            {--dry-run : Solo mostrar qué se haría sin hacer cambios}
                            {--all : Procesar TODOS los prospectos, no solo Sysgal}
                            {--chunk=1000 : Tamaño del chunk para procesamiento}';

    protected $description = 'Calcula y guarda nivel_deuda en metadata de prospectos de SYSGAL basado en monto_deuda';

    private const BATCH_SIZE = 500;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $processAll = $this->option('all');
        $chunkSize = (int) $this->option('chunk');

        $this->info('=== Poblar nivel_deuda en prospectos ===');
        $this->newLine();

        if ($processAll) {
            $this->warn('⚠️  Modo --all: Procesando TODOS los prospectos (no solo Sysgal)');
        } else {
            $this->info('📌 Solo prospectos de SYSGAL (lotes SG_NA_* y SG_NC_*)');
        }
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔍 Modo DRY-RUN: No se harán cambios');
            $this->newLine();
        }

        // Contar prospectos que necesitan actualización
        $totalSinNivel = $this->contarProspectosSinNivelDeuda($processAll);
        $totalConMonto = $this->contarProspectosConMontoDeuda($processAll);

        $this->info('📊 Estadísticas actuales:');
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Prospectos con monto_deuda', number_format($totalConMonto)],
                ['Prospectos sin nivel_deuda en metadata', number_format($totalSinNivel)],
            ]
        );
        $this->newLine();

        if ($totalSinNivel === 0) {
            $this->info('✅ Todos los prospectos ya tienen nivel_deuda. Nada que hacer.');

            return self::SUCCESS;
        }

        if (! $dryRun && ! $this->confirm("¿Procesar {$totalSinNivel} prospectos?")) {
            $this->warn('Operación cancelada.');

            return self::SUCCESS;
        }

        // Procesar en chunks
        $processed = 0;
        $updated = 0;
        $errors = 0;
        $stats = ['baja' => 0, 'media' => 0, 'alta' => 0, 'sin_informacion' => 0];

        $this->info('🔄 Procesando prospectos...');
        $progressBar = $this->output->createProgressBar($totalSinNivel);
        $progressBar->start();

        $updateBatch = [];

        // Query prospectos de SYSGAL que no tienen nivel_deuda en metadata
        $this->buildBaseQuery($processAll)
            ->orderBy('id')
            ->chunk($chunkSize, function ($prospectos) use (
                &$processed,
                &$updated,
                &$errors,
                &$stats,
                &$updateBatch,
                $progressBar,
                $dryRun
            ) {
                foreach ($prospectos as $prospecto) {
                    $processed++;

                    try {
                        $montoDeuda = $prospecto->monto_deuda ?? 0;
                        $nivelDeuda = SysgalApiSyncService::calcularNivelDeuda((float) $montoDeuda);

                        // Merge con metadata existente
                        $metadata = $prospecto->metadata ?? [];
                        $metadata['nivel_deuda'] = $nivelDeuda;
                        $metadata['nivel_deuda_calculado_at'] = now()->toISOString();

                        if (! $dryRun) {
                            $updateBatch[] = [
                                'id' => $prospecto->id,
                                'metadata' => json_encode($metadata),
                                'updated_at' => now(),
                            ];

                            // Flush batch
                            if (count($updateBatch) >= self::BATCH_SIZE) {
                                $this->flushBatch($updateBatch);
                                $updateBatch = [];
                            }
                        }

                        $stats[$nivelDeuda]++;
                        $updated++;
                    } catch (\Exception $e) {
                        $errors++;
                    }

                    $progressBar->advance();
                }
            });

        // Flush remaining batch
        if (! $dryRun && count($updateBatch) > 0) {
            $this->flushBatch($updateBatch);
        }

        $progressBar->finish();
        $this->newLine(2);

        // Mostrar resultados
        $this->info('📈 Resultados:');
        $this->table(
            ['Nivel Deuda', 'Cantidad'],
            [
                ['Baja (< $700k)', number_format($stats['baja'])],
                ['Media ($700k - $1.5M)', number_format($stats['media'])],
                ['Alta (> $1.5M)', number_format($stats['alta'])],
                ['Sin información', number_format($stats['sin_informacion'])],
            ]
        );

        $this->newLine();
        $this->info("✅ Procesados: {$processed}");
        $this->info("✅ Actualizados: {$updated}");

        if ($errors > 0) {
            $this->warn("⚠️ Errores: {$errors}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('🔍 Este fue un DRY-RUN. Ejecuta sin --dry-run para aplicar cambios.');
        }

        return self::SUCCESS;
    }

    /**
     * Construye la query base filtrando por SYSGAL si corresponde.
     */
    private function buildBaseQuery(bool $processAll): Builder
    {
        $query = Prospecto::query()
            ->whereRaw("(metadata->>'nivel_deuda' IS NULL OR metadata->>'nivel_deuda' = '')");

        if (! $processAll) {
            // Solo prospectos de lotes Sysgal (SG_NA_* y SG_NC_*)
            $query->whereHas('importacion.lote', function ($q) {
                $q->where('nombre', 'like', 'SG_NA_%')
                    ->orWhere('nombre', 'like', 'SG_NC_%');
            });
        }

        return $query;
    }

    private function contarProspectosSinNivelDeuda(bool $processAll): int
    {
        return $this->buildBaseQuery($processAll)->count();
    }

    private function contarProspectosConMontoDeuda(bool $processAll): int
    {
        $query = Prospecto::query()->where('monto_deuda', '>', 0);

        if (! $processAll) {
            $query->whereHas('importacion.lote', function ($q) {
                $q->where('nombre', 'like', 'SG_NA_%')
                    ->orWhere('nombre', 'like', 'SG_NC_%');
            });
        }

        return $query->count();
    }

    private function flushBatch(array $batch): void
    {
        foreach ($batch as $item) {
            DB::table('prospectos')
                ->where('id', $item['id'])
                ->update([
                    'metadata' => DB::raw("'{$item['metadata']}'::jsonb"),
                    'updated_at' => $item['updated_at'],
                ]);
        }
    }
}
