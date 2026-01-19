<?php

namespace App\Console\Commands;

use App\Services\AlertasService;
use Illuminate\Console\Command;

/**
 * Comando para probar el sistema de alertas.
 * 
 * Uso:
 *   php artisan alertas:test              # Envía alerta de prueba (info)
 *   php artisan alertas:test --warning    # Envía alerta de warning
 *   php artisan alertas:test --critical   # Envía alerta crítica (SMS + Email)
 *   php artisan alertas:test --resumen    # Envía resumen diario
 */
class TestAlertasCommand extends Command
{
    protected $signature = 'alertas:test 
                            {--warning : Enviar alerta de warning}
                            {--critical : Enviar alerta crítica (SMS + Email)}
                            {--resumen : Enviar resumen diario}';

    protected $description = 'Probar el sistema de alertas enviando una alerta de prueba';

    public function handle(AlertasService $alertasService): int
    {
        $this->info('Probando sistema de alertas...');
        $this->newLine();

        // Mostrar configuración actual
        $this->table(
            ['Configuración', 'Valor'],
            [
                ['Emails', config('envios.alerts.emails')],
                ['SMS Numbers', config('envios.alerts.sms_numbers')],
                ['Critical Enabled', config('envios.alerts.enabled.critical') ? 'Sí' : 'No'],
                ['Warning Enabled', config('envios.alerts.enabled.warning') ? 'Sí' : 'No'],
                ['Info Enabled', config('envios.alerts.enabled.info') ? 'Sí' : 'No'],
                ['Cooldown (min)', config('envios.alerts.cooldown_minutes')],
            ]
        );

        $this->newLine();

        try {
            if ($this->option('critical')) {
                $this->warn('Enviando alerta CRÍTICA (SMS + Email)...');
                $alertasService->alertaCritica(
                    '🧪 Prueba de Alerta Crítica',
                    'Esta es una prueba del sistema de alertas críticas. Si recibiste este mensaje, el sistema funciona correctamente.',
                    [
                        'tipo' => 'prueba',
                        'iniciado_por' => 'comando artisan',
                        'ambiente' => config('app.env'),
                    ]
                );
                $this->info('✓ Alerta crítica enviada');

            } elseif ($this->option('warning')) {
                $this->warn('Enviando alerta WARNING...');
                $alertasService->alertaWarning(
                    '🧪 Prueba de Alerta Warning',
                    'Esta es una prueba del sistema de alertas de warning. Si recibiste este mensaje, el sistema funciona correctamente.',
                    [
                        'tipo' => 'prueba',
                        'iniciado_por' => 'comando artisan',
                    ]
                );
                $this->info('✓ Alerta warning enviada');

            } elseif ($this->option('resumen')) {
                $this->info('Enviando resumen diario...');
                $alertasService->enviarResumenDiario();
                $this->info('✓ Resumen diario enviado');

            } else {
                $this->info('Enviando alerta INFO (default)...');
                $alertasService->alertaInfo(
                    '🧪 Prueba de Alerta Info',
                    'Esta es una prueba del sistema de alertas informativas. Si recibiste este mensaje, el sistema funciona correctamente.',
                    [
                        'tipo' => 'prueba',
                        'iniciado_por' => 'comando artisan',
                    ]
                );
                $this->info('✓ Alerta info enviada');
            }

            $this->newLine();
            $this->info('Prueba completada. Revisa los logs y correos/SMS.');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
