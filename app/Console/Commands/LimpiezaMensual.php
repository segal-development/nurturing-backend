<?php

namespace App\Console\Commands;

use App\Jobs\LimpiezaMensualJob;
use App\Models\Envio;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use Illuminate\Console\Command;

/**
 * Comando para ejecutar la limpieza mensual de datos.
 *
 * Uso:
 *   php artisan datos:limpiar              # Ejecuta limpieza (dry-run por defecto)
 *   php artisan datos:limpiar --ejecutar   # Ejecuta limpieza real
 *   php artisan datos:limpiar --sync       # Ejecuta sincrónicamente
 *   php artisan datos:limpiar --stats      # Solo muestra estadísticas
 */
class LimpiezaMensual extends Command
{
    protected $signature = 'datos:limpiar
                            {--ejecutar : Ejecutar la limpieza (sin esto es dry-run)}
                            {--sync : Ejecutar sincrónicamente (no queue)}
                            {--stats : Solo mostrar estadísticas sin ejecutar}
                            {--meses=3 : Meses de retención}';

    protected $description = 'Limpieza mensual de prospectos y envíos antiguos';

    private const MESES_RETENCION_DEFAULT = 3;

    public function handle(): int
    {
        $meses = (int) $this->option('meses') ?: self::MESES_RETENCION_DEFAULT;
        $fechaCorte = now()->subMonths($meses);

        $this->info("🧹 Limpieza de datos - Retención: {$meses} meses");
        $this->info("📅 Fecha de corte: {$fechaCorte->toDateString()}");
        $this->newLine();

        // Mostrar estadísticas
        $stats = $this->calcularEstadisticas($fechaCorte);
        $this->mostrarEstadisticas($stats);

        if ($this->option('stats')) {
            return Command::SUCCESS;
        }

        $dryRun = ! $this->option('ejecutar');

        if ($dryRun) {
            $this->newLine();
            $this->warn('⚠️  Modo DRY-RUN: No se ejecutará ninguna acción.');
            $this->warn('    Usa --ejecutar para aplicar los cambios.');

            return Command::SUCCESS;
        }

        // Confirmar antes de ejecutar
        if (! $this->confirm('¿Confirmas que querés ejecutar la limpieza?')) {
            $this->info('Operación cancelada.');

            return Command::SUCCESS;
        }

        $sync = $this->option('sync');
        $job = new LimpiezaMensualJob(dryRun: false);

        if ($sync) {
            $this->info('Ejecutando sincrónicamente...');
            $job->handle();
            $this->info('✅ Limpieza completada');
        } else {
            dispatch($job);
            $this->info('✅ Job de limpieza encolado');
        }

        return Command::SUCCESS;
    }

    /**
     * Calcula estadísticas de lo que se limpiará.
     */
    private function calcularEstadisticas(\Carbon\Carbon $fechaCorte): array
    {
        return [
            'prospectos_a_archivar' => Prospecto::query()
                ->where('estado', 'activo')
                ->where(function ($q) use ($fechaCorte) {
                    $q->where('fecha_ultimo_contacto', '<', $fechaCorte)
                        ->orWhere(function ($q2) use ($fechaCorte) {
                            $q2->whereNull('fecha_ultimo_contacto')
                                ->where('created_at', '<', $fechaCorte);
                        });
                })
                ->count(),

            'envios_a_eliminar' => Envio::where('created_at', '<', $fechaCorte)->count(),

            'prospecto_en_flujo_a_eliminar' => ProspectoEnFlujo::query()
                ->where('updated_at', '<', $fechaCorte)
                ->where(function ($q) {
                    $q->where('completado', true)
                        ->orWhere('cancelado', true);
                })
                ->count(),

            'total_prospectos' => Prospecto::count(),
            'total_envios' => Envio::count(),
            'total_prospecto_en_flujo' => ProspectoEnFlujo::count(),

            'prospectos_archivados_actual' => Prospecto::where('estado', 'archivado')->count(),
        ];
    }

    /**
     * Muestra las estadísticas en formato tabla.
     */
    private function mostrarEstadisticas(array $stats): void
    {
        $this->table(
            ['Métrica', 'Total', 'A limpiar', '% del total'],
            [
                [
                    'Prospectos activos → archivados',
                    number_format($stats['total_prospectos']),
                    number_format($stats['prospectos_a_archivar']),
                    $this->porcentaje($stats['prospectos_a_archivar'], $stats['total_prospectos']),
                ],
                [
                    'Envíos → eliminar',
                    number_format($stats['total_envios']),
                    number_format($stats['envios_a_eliminar']),
                    $this->porcentaje($stats['envios_a_eliminar'], $stats['total_envios']),
                ],
                [
                    'ProspectoEnFlujo → eliminar',
                    number_format($stats['total_prospecto_en_flujo']),
                    number_format($stats['prospecto_en_flujo_a_eliminar']),
                    $this->porcentaje($stats['prospecto_en_flujo_a_eliminar'], $stats['total_prospecto_en_flujo']),
                ],
            ]
        );

        $this->newLine();
        $this->info('📊 Prospectos ya archivados: '.number_format($stats['prospectos_archivados_actual']));
    }

    private function porcentaje(int $parte, int $total): string
    {
        if ($total === 0) {
            return '0%';
        }

        return round(($parte / $total) * 100, 1).'%';
    }
}
