<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Comando para verificar que toda la configuración de envíos esté correcta.
 *
 * Uso:
 * - Manual: php artisan envio:verify-config
 * - En deploy: agregar al script de deploy antes de reiniciar workers
 * - Health check: puede usarse como readiness probe
 */
class VerifyEnvioConfig extends Command
{
    protected $signature = 'envio:verify-config
                            {--test-api : Hace una llamada de prueba a las APIs}
                            {--fail-on-warning : Retorna exit code 1 si hay warnings}';

    protected $description = 'Verifica que la configuración de envíos (SMS/Email) esté correcta';

    private array $errors = [];

    private array $warnings = [];

    public function handle(): int
    {
        $this->info('');
        $this->info('=== Verificación de Configuración de Envíos ===');
        $this->info('');

        $this->checkSmsConfig();
        $this->checkEmailConfig();
        $this->checkQueueConfig();
        $this->checkCircuitBreakerConfig();

        if ($this->option('test-api')) {
            $this->testSmsApi();
            $this->testEmailApi();
        }

        $this->printResults();

        if (count($this->errors) > 0) {
            Log::error('VerifyEnvioConfig: Configuración inválida', [
                'errors' => $this->errors,
                'warnings' => $this->warnings,
            ]);

            return Command::FAILURE;
        }

        if ($this->option('fail-on-warning') && count($this->warnings) > 0) {
            return Command::FAILURE;
        }

        $this->info('');
        $this->info('Configuración verificada correctamente.');

        return Command::SUCCESS;
    }

    private function checkSmsConfig(): void
    {
        $this->info('SMS Configuration:');

        $token = config('services.sms.api_token');
        if (empty($token)) {
            $this->errors[] = 'SMS_API_TOKEN no está configurado';
            $this->error('  [ERROR] SMS_API_TOKEN: NO CONFIGURADO');
        } else {
            $this->line('  [OK] SMS_API_TOKEN: '.substr($token, 0, 10).'...');
        }

        // Verificar que AthenaCampaignService pueda leer la config
        $serviceToken = app(\App\Services\AthenaCampaignService::class)->getSmsToken();
        if (empty($serviceToken)) {
            $this->errors[] = 'AthenaCampaignService no puede leer SMS token (posible cache desactualizado)';
            $this->error('  [ERROR] AthenaCampaignService: Token NULL - Ejecutar config:cache');
        } else {
            $this->line('  [OK] AthenaCampaignService: Token cargado');
        }
    }

    private function checkEmailConfig(): void
    {
        $this->info('');
        $this->info('Email Configuration:');

        $apiKey = config('services.athenacampaign.api_key');
        if (empty($apiKey)) {
            $this->errors[] = 'ATHENACAMPAIGN_API_KEY no está configurado';
            $this->error('  [ERROR] ATHENACAMPAIGN_API_KEY: NO CONFIGURADO');
        } else {
            $this->line('  [OK] ATHENACAMPAIGN_API_KEY: '.substr($apiKey, 0, 10).'...');
        }

        $baseUrl = config('services.athenacampaign.base_url');
        if (empty($baseUrl)) {
            $this->warnings[] = 'ATHENACAMPAIGN_BASE_URL no está configurado (usando default)';
            $this->warn('  [WARN] ATHENACAMPAIGN_BASE_URL: Usando default');
        } else {
            $this->line('  [OK] ATHENACAMPAIGN_BASE_URL: '.$baseUrl);
        }
    }

    private function checkQueueConfig(): void
    {
        $this->info('');
        $this->info('Queue Configuration:');

        $connection = config('queue.default');
        $this->line("  [OK] Queue connection: {$connection}");

        $tries = config('envios.queue.tries', 3);
        $timeout = config('envios.queue.timeout', 60);
        $this->line("  [OK] Tries: {$tries}, Timeout: {$timeout}s");
    }

    private function checkCircuitBreakerConfig(): void
    {
        $this->info('');
        $this->info('Circuit Breaker Configuration:');

        $threshold = config('envios.circuit_breaker.failure_threshold', 10);
        $window = config('envios.circuit_breaker.failure_window', 60);
        $recovery = config('envios.circuit_breaker.recovery_time', 60);

        $this->line("  [OK] Failure threshold: {$threshold} failures");
        $this->line("  [OK] Failure window: {$window}s");
        $this->line("  [OK] Recovery time: {$recovery}s");

        // Verificar estado actual del circuit breaker
        $smsCircuit = cache('envio-circuit:sms');
        $emailCircuit = cache('envio-circuit:email');

        if ($smsCircuit === 'open') {
            $this->warnings[] = 'Circuit breaker SMS está ABIERTO';
            $this->warn('  [WARN] SMS Circuit: ABIERTO');
        } else {
            $this->line('  [OK] SMS Circuit: cerrado');
        }

        if ($emailCircuit === 'open') {
            $this->warnings[] = 'Circuit breaker Email está ABIERTO';
            $this->warn('  [WARN] Email Circuit: ABIERTO');
        } else {
            $this->line('  [OK] Email Circuit: cerrado');
        }
    }

    private function testSmsApi(): void
    {
        $this->info('');
        $this->info('Testing SMS API connectivity...');

        try {
            $token = config('services.sms.api_token');
            if (empty($token)) {
                $this->error('  [SKIP] No se puede testear sin token');

                return;
            }

            // Hacer request de prueba (sin enviar realmente)
            // AthenaCampaign no tiene endpoint de health, pero podemos
            // verificar que responda con un request inválido controlado
            $response = Http::timeout(10)->get('https://api.athenacampaign.com/v1/sendmessage', [
                'TOKEN' => $token,
                'PHONE' => 'test',
                'MESSAGE' => 'test',
            ]);

            // Esperamos un error de validación (teléfono inválido), no un 403
            if ($response->status() === 403 || $response->status() === 401) {
                $this->errors[] = 'SMS API rechaza el token (HTTP '.$response->status().')';
                $this->error('  [ERROR] SMS API: Token rechazado (HTTP '.$response->status().')');
            } elseif ($response->successful() || $response->status() === 400) {
                // 400 = bad request por teléfono inválido = API funciona
                $this->line('  [OK] SMS API: Conectividad verificada');
            } else {
                $this->warnings[] = 'SMS API respondió con HTTP '.$response->status();
                $this->warn('  [WARN] SMS API: HTTP '.$response->status());
            }
        } catch (\Exception $e) {
            $this->errors[] = 'SMS API no responde: '.$e->getMessage();
            $this->error('  [ERROR] SMS API: '.$e->getMessage());
        }
    }

    private function testEmailApi(): void
    {
        $this->info('');
        $this->info('Testing Email API connectivity...');

        try {
            $apiKey = config('services.athenacampaign.api_key');
            $baseUrl = config('services.athenacampaign.base_url', 'https://apimail.athenacampaign.com');

            if (empty($apiKey)) {
                $this->error('  [SKIP] No se puede testear sin API key');

                return;
            }

            // Health check o request mínimo
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->get("{$baseUrl}/api/health");

            if ($response->successful()) {
                $this->line('  [OK] Email API: Conectividad verificada');
            } elseif ($response->status() === 404) {
                // No hay endpoint de health, pero la API responde
                $this->line('  [OK] Email API: Servidor responde');
            } else {
                $this->warnings[] = 'Email API respondió con HTTP '.$response->status();
                $this->warn('  [WARN] Email API: HTTP '.$response->status());
            }
        } catch (\Exception $e) {
            $this->errors[] = 'Email API no responde: '.$e->getMessage();
            $this->error('  [ERROR] Email API: '.$e->getMessage());
        }
    }

    private function printResults(): void
    {
        $this->info('');
        $this->info('=== Resumen ===');

        if (count($this->errors) > 0) {
            $this->error('Errores: '.count($this->errors));
            foreach ($this->errors as $error) {
                $this->error("  - {$error}");
            }
        }

        if (count($this->warnings) > 0) {
            $this->warn('Warnings: '.count($this->warnings));
            foreach ($this->warnings as $warning) {
                $this->warn("  - {$warning}");
            }
        }

        if (count($this->errors) === 0 && count($this->warnings) === 0) {
            $this->info('Sin errores ni warnings');
        }
    }
}
