<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Importacion;
use App\Models\Lote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Comando para forzar la finalización de importaciones que quedaron stuck.
 * 
 * Uso:
 *   php artisan importaciones:force-complete          # Detectar y completar automáticamente
 *   php artisan importaciones:force-complete --id=3   # Forzar una importación específica
 *   php artisan importaciones:force-complete --dry-run # Solo mostrar qué haría
 */
class ForceCompleteImportations extends Command
{
    protected $signature = 'importaciones:force-complete 
                            {--id= : ID específico de importación a completar}
                            {--dry-run : Solo mostrar qué haría sin ejecutar}
                            {--threshold=95 : Porcentaje mínimo de registros exitosos para considerar completado}';

    protected $description = 'Fuerza la finalización de importaciones que procesaron >95% de registros pero quedaron en estado procesando';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $specificId = $this->option('id');
        $threshold = (float) $this->option('threshold');

        $this->info('=== Force Complete Importations ===');
        $this->info("Threshold: {$threshold}%");
        if ($dryRun) {
            $this->warn('Modo dry-run: no se ejecutarán cambios');
        }
        $this->newLine();

        // Obtener importaciones a procesar
        $query = Importacion::where('estado', 'procesando');
        
        if ($specificId) {
            $query->where('id', $specificId);
        }

        $importaciones = $query->get();

        if ($importaciones->isEmpty()) {
            $this->info('No se encontraron importaciones en estado "procesando".');
            return self::SUCCESS;
        }

        $this->info("Encontradas {$importaciones->count()} importación(es) en procesando:");
        $this->newLine();

        $completed = 0;
        $skipped = 0;

        foreach ($importaciones as $importacion) {
            $result = $this->processImportacion($importacion, $threshold, $dryRun);
            if ($result) {
                $completed++;
            } else {
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("=== Resumen ===");
        $this->info("Completadas: {$completed}");
        $this->info("Omitidas: {$skipped}");

        return self::SUCCESS;
    }

    private function processImportacion(Importacion $importacion, float $threshold, bool $dryRun): bool
    {
        $total = $importacion->total_registros ?? 0;
        $exitosos = $importacion->registros_exitosos ?? 0;
        $fallidos = $importacion->registros_fallidos ?? 0;
        $procesados = $exitosos + $fallidos;

        // Calcular porcentaje
        $porcentaje = $total > 0 ? ($procesados / $total) * 100 : 0;

        $this->line("Importación #{$importacion->id}: {$importacion->nombre_archivo}");
        $this->line("  - Total: {$total}, Exitosos: {$exitosos}, Fallidos: {$fallidos}");
        $this->line("  - Procesados: {$procesados} ({$porcentaje}%)");
        $this->line("  - Updated at: {$importacion->updated_at}");

        // Verificar si cumple el threshold
        if ($porcentaje < $threshold) {
            $this->warn("  -> OMITIDA: Solo {$porcentaje}% procesado (threshold: {$threshold}%)");
            return false;
        }

        // Verificar si el archivo existe (si existe, debería seguir procesando)
        $archivoExiste = $this->checkFileExists($importacion);
        $this->line("  - Archivo en GCS: " . ($archivoExiste ? 'SÍ existe' : 'NO existe'));

        if ($archivoExiste) {
            $this->warn("  -> OMITIDA: El archivo aún existe, puede estar procesando");
            return false;
        }

        // Marcar como completada
        if ($dryRun) {
            $this->info("  -> [DRY-RUN] Se marcaría como COMPLETADA");
            return true;
        }

        $this->markAsCompleted($importacion);
        $this->info("  -> COMPLETADA exitosamente");
        return true;
    }

    private function checkFileExists(Importacion $importacion): bool
    {
        if (empty($importacion->ruta_archivo)) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Storage::disk('gcs')->exists($importacion->ruta_archivo);
        } catch (\Exception $e) {
            $this->warn("  - Error verificando archivo: {$e->getMessage()}");
            return false;
        }
    }

    private function markAsCompleted(Importacion $importacion): void
    {
        $importacion->update([
            'estado' => 'completado',
            'metadata' => array_merge($importacion->metadata ?? [], [
                'completado_en' => now()->toISOString(),
                'completado_por' => 'force_complete_command',
                'nota' => 'Forzado manualmente - proceso terminó pero no guardó estado',
            ]),
        ]);

        Log::info('ForceCompleteImportations: Importación marcada como completada', [
            'importacion_id' => $importacion->id,
            'registros_exitosos' => $importacion->registros_exitosos,
            'total_registros' => $importacion->total_registros,
        ]);

        // Actualizar el lote si existe
        $this->updateLote($importacion);
    }

    private function updateLote(Importacion $importacion): void
    {
        $importacion->refresh();
        
        if (!$importacion->lote_id) {
            return;
        }

        $lote = Lote::find($importacion->lote_id);
        if (!$lote) {
            return;
        }

        $importaciones = $lote->importaciones()->get();
        
        $totalRegistros = $importaciones->sum('total_registros');
        $registrosExitosos = $importaciones->sum('registros_exitosos');
        $registrosFallidos = $importaciones->sum('registros_fallidos');
        
        $todasCompletadas = $importaciones->every(fn ($imp) => in_array($imp->estado, ['completado', 'fallido']));
        $algunaFallida = $importaciones->contains(fn ($imp) => $imp->estado === 'fallido');
        
        $estadoLote = $todasCompletadas 
            ? ($algunaFallida ? 'fallido' : 'completado')
            : 'procesando';

        $lote->update([
            'total_registros' => $totalRegistros,
            'registros_exitosos' => $registrosExitosos,
            'registros_fallidos' => $registrosFallidos,
            'estado' => $estadoLote,
            'cerrado_en' => $todasCompletadas ? now() : null,
        ]);

        $this->line("  - Lote #{$lote->id} actualizado a estado: {$estadoLote}");
    }
}
