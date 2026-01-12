<?php

namespace Tests\Feature;

use App\Http\Controllers\FlujoController;
use Tests\TestCase;

/**
 * Tests para el endpoint de progreso de flujos.
 * Estos tests verifican la lógica del controlador sin depender de la base de datos.
 */
class FlujoProgresoTest extends TestCase
{
    /** @test */
    public function metodo_generar_mensaje_progreso_existe(): void
    {
        $controller = new FlujoController(
            app(\App\Services\CanalEnvioResolver::class)
        );

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generarMensajeProgreso');

        $this->assertTrue($method->isPrivate());
    }

    /** @test */
    public function generar_mensaje_progreso_retorna_mensaje_fallido(): void
    {
        $controller = new FlujoController(
            app(\App\Services\CanalEnvioResolver::class)
        );

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generarMensajeProgreso');
        $method->setAccessible(true);

        $mensaje = $method->invoke($controller, 'fallido', null);

        $this->assertStringContainsString('falló', $mensaje);
    }

    /** @test */
    public function generar_mensaje_progreso_retorna_mensaje_completado(): void
    {
        $controller = new FlujoController(
            app(\App\Services\CanalEnvioResolver::class)
        );

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generarMensajeProgreso');
        $method->setAccessible(true);

        $progreso = [
            'completado' => true,
            'duracion_segundos' => 70,
        ];

        $mensaje = $method->invoke($controller, 'completado', $progreso);

        $this->assertStringContainsString('completado', $mensaje);
        $this->assertStringContainsString('70', $mensaje);
    }

    /** @test */
    public function generar_mensaje_progreso_retorna_mensaje_procesando(): void
    {
        $controller = new FlujoController(
            app(\App\Services\CanalEnvioResolver::class)
        );

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generarMensajeProgreso');
        $method->setAccessible(true);

        $progreso = [
            'procesados' => 150000,
            'total' => 350000,
            'porcentaje' => 42.86,
            'segundos_restantes_estimados' => 40,
        ];

        $mensaje = $method->invoke($controller, 'procesando', $progreso);

        $this->assertStringContainsString('150,000', $mensaje);
        $this->assertStringContainsString('350,000', $mensaje);
        $this->assertStringContainsString('42.86%', $mensaje);
        $this->assertStringContainsString('40', $mensaje);
    }

    /** @test */
    public function generar_mensaje_progreso_retorna_mensaje_procesando_sin_tiempo_restante(): void
    {
        $controller = new FlujoController(
            app(\App\Services\CanalEnvioResolver::class)
        );

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generarMensajeProgreso');
        $method->setAccessible(true);

        $progreso = [
            'procesados' => 100,
            'total' => 1000,
            'porcentaje' => 10,
            'segundos_restantes_estimados' => null,
        ];

        $mensaje = $method->invoke($controller, 'procesando', $progreso);

        $this->assertStringContainsString('100', $mensaje);
        $this->assertStringContainsString('1,000', $mensaje);
        $this->assertStringNotContainsString('restantes', $mensaje);
    }

    /** @test */
    public function generar_mensaje_progreso_retorna_mensaje_esperando(): void
    {
        $controller = new FlujoController(
            app(\App\Services\CanalEnvioResolver::class)
        );

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generarMensajeProgreso');
        $method->setAccessible(true);

        $mensaje = $method->invoke($controller, 'pendiente', null);

        $this->assertStringContainsString('Esperando', $mensaje);
    }

    /** @test */
    public function generar_mensaje_progreso_con_progreso_completado_en_metadata(): void
    {
        $controller = new FlujoController(
            app(\App\Services\CanalEnvioResolver::class)
        );

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generarMensajeProgreso');
        $method->setAccessible(true);

        $progreso = [
            'completado' => true,
            'duracion_segundos' => 120,
        ];

        // Aunque el estado sea 'procesando', si completado es true, debe mostrar mensaje de completado
        $mensaje = $method->invoke($controller, 'procesando', $progreso);

        $this->assertStringContainsString('completado', $mensaje);
    }

    /** @test */
    public function endpoint_progreso_esta_registrado_en_rutas(): void
    {
        $routes = app('router')->getRoutes();
        $found = false;

        foreach ($routes as $route) {
            if (str_contains($route->uri(), 'flujos/{flujo}/progreso')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'La ruta flujos/{flujo}/progreso debe existir');
    }

    /** @test */
    public function endpoint_progreso_usa_metodo_get(): void
    {
        $routes = app('router')->getRoutes();

        foreach ($routes as $route) {
            if (str_contains($route->uri(), 'flujos/{flujo}/progreso')) {
                $this->assertContains('GET', $route->methods());
                break;
            }
        }
    }

    /** @test */
    public function flujo_controller_tiene_metodo_progreso(): void
    {
        $this->assertTrue(
            method_exists(FlujoController::class, 'progreso'),
            'FlujoController debe tener método progreso'
        );
    }

    /** @test */
    public function metodo_progreso_retorna_json_response(): void
    {
        $reflection = new \ReflectionMethod(FlujoController::class, 'progreso');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('Illuminate\Http\JsonResponse', $returnType->getName());
    }
}
