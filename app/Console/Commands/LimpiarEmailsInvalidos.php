<?php

namespace App\Console\Commands;

use App\Services\EmailValidationService;
use Illuminate\Console\Command;

/**
 * Comando para limpiar emails inválidos de la base de datos.
 * 
 * Detecta y marca emails con:
 * - Formato inválido
 * - Dominios mal escritos (gimeil.com, guimei.con, etc.)
 * - Extensiones incorrectas (.con, .cpm)
 * 
 * Uso:
 *   php artisan emails:limpiar              # Ejecuta limpieza completa
 *   php artisan emails:limpiar --dry-run    # Solo muestra qué haría sin modificar
 *   php artisan emails:limpiar --stats      # Muestra estadísticas de calidad por origen
 */
class LimpiarEmailsInvalidos extends Command
{
    protected $signature = 'emails:limpiar 
                            {--dry-run : Solo muestra qué emails serían marcados sin modificar}
                            {--stats : Muestra estadísticas de calidad de emails por origen}
                            {--batch-size=1000 : Tamaño del batch para procesamiento}';

    protected $description = 'Detecta y marca emails inválidos en la base de datos de prospectos';

    public function __construct(private EmailValidationService $emailService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Si solo quieren ver estadísticas
        if ($this->option('stats')) {
            return $this->mostrarEstadisticas();
        }

        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        $this->info('');
        $this->info($dryRun 
            ? '🔍 MODO DRY-RUN: Analizando emails sin modificar...' 
            : '🧹 Iniciando limpieza de emails inválidos...');
        $this->info('');

        $startTime = microtime(true);

        if ($dryRun) {
            $resultado = $this->ejecutarDryRun($batchSize);
        } else {
            $resultado = $this->ejecutarLimpieza($batchSize);
        }

        $duration = round(microtime(true) - $startTime, 2);

        // Mostrar resumen
        $this->info('');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('                     📊 RESUMEN                           ');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Total analizados', number_format($resultado['total'])],
                ['Emails inválidos', number_format($resultado['invalidos'])],
                ['Tasa de invalidez', $resultado['total'] > 0 
                    ? round(($resultado['invalidos'] / $resultado['total']) * 100, 2) . '%' 
                    : '0%'],
                ['Tiempo', $duration . 's'],
            ]
        );

        // Mostrar sugerencias de corrección si hay
        if (!empty($resultado['sugerencias'])) {
            $this->info('');
            $this->info('💡 Emails con sugerencia de corrección (primeros 20):');
            $this->table(
                ['ID', 'Email Actual', 'Sugerencia', 'Motivo'],
                array_slice(array_map(function ($s) {
                    return [
                        $s['prospecto_id'],
                        $s['email_actual'],
                        $s['sugerencia'],
                        $s['motivo'],
                    ];
                }, $resultado['sugerencias']), 0, 20)
            );
        }

        // Mostrar motivos más comunes
        $this->mostrarMotivosComunes();

        $this->info('');
        
        if ($dryRun) {
            $this->warn('⚠️  MODO DRY-RUN: Ningún email fue modificado.');
            $this->info('    Ejecuta sin --dry-run para aplicar los cambios.');
        } else {
            $this->info('✅ Limpieza completada. Los emails inválidos fueron marcados.');
        }

        return Command::SUCCESS;
    }

    private function ejecutarDryRun(int $batchSize): array
    {
        $resultado = [
            'total' => 0,
            'invalidos' => 0,
            'sugerencias' => [],
        ];

        $progressBar = $this->output->createProgressBar();
        $progressBar->setFormat(' %current% procesados [%bar%] %message%');
        $progressBar->start();

        \App\Models\Prospecto::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(function ($q) {
                $q->where('email_invalido', false)
                  ->orWhereNull('email_invalido');
            })
            ->chunkById($batchSize, function ($prospectos) use (&$resultado, $progressBar) {
                foreach ($prospectos as $prospecto) {
                    $resultado['total']++;
                    
                    $validacion = $this->emailService->validar($prospecto->email);
                    
                    if (!$validacion['valid']) {
                        $resultado['invalidos']++;
                        
                        if ($validacion['sugerencia']) {
                            $resultado['sugerencias'][] = [
                                'prospecto_id' => $prospecto->id,
                                'email_actual' => $prospecto->email,
                                'sugerencia' => $validacion['sugerencia'],
                                'motivo' => $validacion['motivo'],
                            ];
                        }
                    }

                    if ($resultado['total'] % 1000 === 0) {
                        $progressBar->setProgress($resultado['total']);
                        $progressBar->setMessage("Inválidos: {$resultado['invalidos']}");
                    }
                }
            });

        $progressBar->finish();
        $this->info('');

        return $resultado;
    }

    private function ejecutarLimpieza(int $batchSize): array
    {
        $progressBar = $this->output->createProgressBar();
        $progressBar->setFormat(' %current% procesados [%bar%] %message%');
        $progressBar->start();

        $resultado = $this->emailService->limpiarEmailsInvalidos(
            $batchSize,
            function ($total, $invalidos) use ($progressBar) {
                $progressBar->setProgress($total);
                $progressBar->setMessage("Inválidos: {$invalidos}");
            }
        );

        $progressBar->finish();
        $this->info('');

        return $resultado;
    }

    private function mostrarEstadisticas(): int
    {
        $this->info('');
        $this->info('📊 Estadísticas de Calidad de Emails por Origen');
        $this->info('═══════════════════════════════════════════════════════════');

        $estadisticas = $this->emailService->obtenerEstadisticasCalidad();

        if (empty($estadisticas)) {
            $this->warn('No hay datos para mostrar.');
            return Command::SUCCESS;
        }

        $this->table(
            ['Origen', 'Total', 'Con Email', 'Válidos', 'Inválidos', 'Desuscritos', 'Tasa Validez'],
            array_map(function ($row) {
                return [
                    $row['origen'],
                    number_format($row['total_prospectos']),
                    number_format($row['con_email']),
                    number_format($row['emails_validos']),
                    number_format($row['emails_invalidos']),
                    number_format($row['desuscritos']),
                    $row['tasa_validez'] . '%',
                ];
            }, $estadisticas)
        );

        $this->mostrarMotivosComunes();

        return Command::SUCCESS;
    }

    private function mostrarMotivosComunes(): void
    {
        $motivos = $this->emailService->obtenerMotivosComunes();

        if (empty($motivos)) {
            return;
        }

        $this->info('');
        $this->info('🔍 Motivos de Invalidez Más Comunes:');
        $this->table(
            ['Motivo', 'Cantidad'],
            array_map(function ($m) {
                return [$m['email_invalido_motivo'], number_format($m['cantidad'])];
            }, $motivos)
        );
    }
}
