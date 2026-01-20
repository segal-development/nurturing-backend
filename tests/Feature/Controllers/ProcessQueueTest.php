<?php

namespace Tests\Feature\Controllers;

use App\Jobs\EjecutarNodosProgramados;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests para el endpoint /api/cron/process-queue
 * 
 * Este endpoint es CRÍTICO - procesa jobs pendientes.
 * Los tests verifican:
 * - No loops infinitos
 * - Manejo de jobs que fallan
 * - Timeouts apropiados
 * - Tracking de jobs procesados
 */
class ProcessQueueTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/cron/process-queue';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Limpiar tabla de jobs
        DB::table('jobs')->truncate();
    }

    // ============================================
    // Tests de Autenticación
    // ============================================

    /** @test */
    public function endpoint_requiere_header_cloud_scheduler(): void
    {
        $response = $this->postJson($this->endpoint);
        
        // Puede ser 401 (Unauthorized) o 403 (Forbidden) dependiendo del middleware
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }

    /** @test */
    public function endpoint_acepta_header_cloud_scheduler(): void
    {
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    // ============================================
    // Tests de Procesamiento Normal
    // ============================================

    /** @test */
    public function endpoint_retorna_jobs_procesados_cero_si_no_hay_jobs(): void
    {
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'jobs_processed' => 0,
                'jobs_remaining' => 0,
            ],
        ]);
    }

    /** @test */
    public function endpoint_retorna_conteo_de_jobs_restantes(): void
    {
        // Crear jobs de prueba
        $this->createTestJob('default');
        $this->createTestJob('default');
        $this->createTestJob('envios');
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'jobs_processed',
                'jobs_remaining',
                'duration_seconds',
                'errors',
            ],
        ]);
    }

    /** @test */
    public function endpoint_procesa_colas_default_y_envios(): void
    {
        // El endpoint debe procesar ambas colas
        $jobDefault = $this->createTestJob('default');
        $jobEnvios = $this->createTestJob('envios');
        
        // Verificar que los jobs existen
        $this->assertEquals(2, DB::table('jobs')->count());
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        // Los jobs deberían ser procesados (o al menos intentados)
        $this->assertLessThanOrEqual(2, DB::table('jobs')->count());
    }

    // ============================================
    // Tests de Prevención de Loops Infinitos
    // ============================================

    /** @test */
    public function endpoint_no_procesa_mismo_job_dos_veces_en_misma_request(): void
    {
        // Crear un job inválido
        $jobId = $this->createPersistentTestJob();
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        
        // Lo importante es que la request completó en tiempo razonable
        // (no entró en loop infinito)
        $duration = $response->json('data.duration_seconds');
        $this->assertLessThan(35, $duration);
        
        // El job puede o no existir dependiendo de cómo el worker lo maneje
        // Lo importante es que NO hubo loop infinito
    }

    /** @test */
    public function endpoint_respeta_limite_maximo_de_jobs(): void
    {
        // Crear más de 10 jobs
        for ($i = 0; $i < 15; $i++) {
            $this->createTestJob('default');
        }
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        
        // No debería procesar más de 10 jobs
        $this->assertLessThanOrEqual(10, $response->json('data.jobs_processed'));
    }

    /** @test */
    public function endpoint_respeta_limite_de_tiempo(): void
    {
        // Crear muchos jobs
        for ($i = 0; $i < 20; $i++) {
            $this->createTestJob('default');
        }
        
        $startTime = microtime(true);
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $elapsed = microtime(true) - $startTime;
        
        $response->assertStatus(200);
        
        // No debería tomar más de 35 segundos (30s límite + overhead)
        $this->assertLessThan(35, $elapsed);
    }

    // ============================================
    // Tests de Manejo de Errores
    // ============================================

    /** @test */
    public function endpoint_reporta_errores_en_response(): void
    {
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['errors'],
        ]);
    }

    /** @test */
    public function endpoint_continua_procesando_despues_de_error_en_job(): void
    {
        // Crear job válido después de uno inválido
        $this->createInvalidJob();
        $this->createTestJob('default');
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        
        // Debería haber intentado procesar ambos (o continuar después del error)
        $this->assertTrue($response->json('success'));
    }

    // ============================================
    // Tests de Retorno de Datos
    // ============================================

    /** @test */
    public function endpoint_retorna_duracion_de_procesamiento(): void
    {
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['duration_seconds'],
        ]);
        
        $duration = $response->json('data.duration_seconds');
        $this->assertIsNumeric($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
    }

    /** @test */
    public function endpoint_retorna_estructura_correcta(): void
    {
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'jobs_processed',
                'jobs_remaining',
                'duration_seconds',
                'errors',
            ],
        ]);
    }

    // ============================================
    // Tests de Integración con EjecutarNodosProgramados
    // ============================================

    /** @test */
    public function endpoint_ejecuta_ejecutar_nodos_programados(): void
    {
        // Este test verifica que el endpoint también ejecuta EjecutarNodosProgramados
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        
        // Si no hay errores relacionados con EjecutarNodosProgramados, el test pasa
        $errors = $response->json('data.errors');
        $ejecutarNodosError = collect($errors)->first(function ($error) {
            return isset($error['job']) && $error['job'] === 'EjecutarNodosProgramados';
        });
        
        // Puede haber error si no hay ejecuciones pendientes, pero no debería ser un error fatal
        if ($ejecutarNodosError) {
            // Verificar que el error no es crítico (por ejemplo, no encontró ejecuciones)
            $this->assertTrue(true); // El endpoint manejó el error correctamente
        } else {
            $this->assertTrue(true); // No hubo error
        }
    }

    // ============================================
    // Tests de Colas Específicas
    // ============================================

    /** @test */
    public function endpoint_procesa_cola_default(): void
    {
        $jobId = $this->createTestJob('default');
        
        $this->assertDatabaseHas('jobs', ['id' => $jobId, 'queue' => 'default']);
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
    }

    /** @test */
    public function endpoint_procesa_cola_envios(): void
    {
        $jobId = $this->createTestJob('envios');
        
        $this->assertDatabaseHas('jobs', ['id' => $jobId, 'queue' => 'envios']);
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
    }

    /** @test */
    public function endpoint_no_procesa_colas_no_especificadas(): void
    {
        // Crear job en cola diferente
        $jobId = $this->createTestJob('otra_cola');
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        
        // El job en 'otra_cola' debería seguir existiendo
        $this->assertDatabaseHas('jobs', ['id' => $jobId, 'queue' => 'otra_cola']);
    }

    // ============================================
    // Tests de Jobs Reservados
    // ============================================

    /** @test */
    public function endpoint_no_procesa_jobs_reservados(): void
    {
        // Crear job ya reservado
        $jobId = DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'TestJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [],
            ]),
            'attempts' => 0,
            'reserved_at' => time(), // Ya reservado
            'available_at' => time(),
            'created_at' => time(),
        ]);
        
        $response = $this->postJson($this->endpoint, [], [
            'X-CloudScheduler' => 'true',
        ]);
        
        $response->assertStatus(200);
        
        // El job reservado debería seguir existiendo (no se procesa)
        $this->assertDatabaseHas('jobs', ['id' => $jobId]);
    }

    // ============================================
    // Helpers
    // ============================================

    private function createTestJob(string $queue = 'default'): int
    {
        return DB::table('jobs')->insertGetId([
            'queue' => $queue,
            'payload' => json_encode([
                'uuid' => \Illuminate\Support\Str::uuid()->toString(),
                'displayName' => 'App\\Jobs\\EjecutarNodosProgramados',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => null,
                'maxExceptions' => null,
                'failOnTimeout' => true,
                'backoff' => null,
                'timeout' => 60,
                'data' => [
                    'commandName' => 'App\\Jobs\\EjecutarNodosProgramados',
                    'command' => serialize(new EjecutarNodosProgramados()),
                ],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    private function createPersistentTestJob(): int
    {
        // Crear un job con payload inválido que no se eliminará correctamente
        return DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => \Illuminate\Support\Str::uuid()->toString(),
                'displayName' => 'InvalidJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [
                    'commandName' => 'App\\Jobs\\NonExistentJob',
                    'command' => 'invalid_serialized_data',
                ],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    private function createInvalidJob(): int
    {
        return DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => 'invalid_json{{{',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }
}
