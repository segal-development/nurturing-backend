<?php

namespace App\Console\Commands;

use App\Jobs\AgregarEnviosMensualesJob;
use Illuminate\Console\Command;

/**
 * Comando para ejecutar la agregación de envíos mensuales.
 *
 * Uso:
 *   php artisan envios:agregar-mensuales              # Mes anterior
 *   php artisan envios:agregar-mensuales --mes=1 --anio=2026
 *   php artisan envios:agregar-mensuales --forzar     # Recalcula aunque ya exista
 *   php artisan envios:agregar-mensuales --sync       # Ejecuta sincrónicamente
 *   php artisan envios:agregar-mensuales --todos      # Todos los meses históricos
 */
class AgregarEnviosMensuales extends Command
{
    protected $signature = 'envios:agregar-mensuales
                            {--mes= : Mes a procesar (1-12)}
                            {--anio= : Año a procesar}
                            {--forzar : Forzar recálculo aunque ya exista}
                            {--sync : Ejecutar sincrónicamente (no queue)}
                            {--todos : Procesar todos los meses históricos}';

    protected $description = 'Agrega estadísticas mensuales de envíos para reportes rápidos';

    public function handle(): int
    {
        if ($this->option('todos')) {
            return $this->procesarTodos();
        }

        $anio = $this->option('anio') ?? now()->subMonth()->year;
        $mes = $this->option('mes') ?? now()->subMonth()->month;
        $forzar = $this->option('forzar') ?? false;
        $sync = $this->option('sync') ?? false;

        $this->info("📊 Procesando envíos de {$mes}/{$anio}...");

        $job = new AgregarEnviosMensualesJob(
            anio: (int) $anio,
            mes: (int) $mes,
            forzarRecalculo: $forzar
        );

        if ($sync) {
            $this->info('Ejecutando sincrónicamente...');
            $job->handle();
            $this->info('✅ Agregación completada');
        } else {
            dispatch($job);
            $this->info('✅ Job encolado correctamente');
        }

        return Command::SUCCESS;
    }

    /**
     * Procesa todos los meses desde el primer envío hasta el mes anterior.
     */
    private function procesarTodos(): int
    {
        $this->info('📊 Procesando todos los meses históricos...');

        // Obtener fecha del primer envío
        $primerEnvio = \App\Models\Envio::orderBy('created_at')->first();

        if (!$primerEnvio) {
            $this->warn('No hay envíos en la base de datos.');
            return Command::SUCCESS;
        }

        $fechaInicio = $primerEnvio->created_at->startOfMonth();
        $fechaFin = now()->subMonth()->endOfMonth();

        $mesesAProcesar = [];
        $fecha = $fechaInicio->copy();

        while ($fecha <= $fechaFin) {
            $mesesAProcesar[] = [
                'anio' => $fecha->year,
                'mes' => $fecha->month,
            ];
            $fecha->addMonth();
        }

        $this->info("Procesando {$mesesAProcesar} meses desde {$fechaInicio->format('Y-m')} hasta {$fechaFin->format('Y-m')}");

        $forzar = $this->option('forzar') ?? false;
        $sync = $this->option('sync') ?? false;

        $bar = $this->output->createProgressBar(count($mesesAProcesar));
        $bar->start();

        foreach ($mesesAProcesar as $periodo) {
            $job = new AgregarEnviosMensualesJob(
                anio: $periodo['anio'],
                mes: $periodo['mes'],
                forzarRecalculo: $forzar
            );

            if ($sync) {
                $job->handle();
            } else {
                dispatch($job);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('✅ Todos los meses procesados');

        return Command::SUCCESS;
    }
}
