<?php

namespace Tests\Unit\Services;

use App\Services\SysgalApiSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests unitarios para SysgalApiSyncService.
 *
 * Cubre la lógica de:
 * - Parseo de monto de deuda desde la API
 * - Clasificación por nivel de deuda (baja/media/alta)
 */
class SysgalApiSyncServiceTest extends TestCase
{
    private SysgalApiSyncService $service;

    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SysgalApiSyncService;
        $this->reflection = new ReflectionClass($this->service);
    }

    /**
     * Helper para invocar métodos privados.
     */
    private function invokePrivateMethod(string $methodName, array $args = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);

        return $method->invokeArgs($this->service, $args);
    }

    // ========================================
    // Tests para parsearMontoDeuda()
    // ========================================

    public function test_parsear_monto_deuda_con_string_numerico(): void
    {
        $result = $this->invokePrivateMethod('parsearMontoDeuda', ['2500400']);

        $this->assertSame(2500400, $result);
    }

    public function test_parsear_monto_deuda_con_numero_entero(): void
    {
        $result = $this->invokePrivateMethod('parsearMontoDeuda', [1500000]);

        $this->assertSame(1500000, $result);
    }

    public function test_parsear_monto_deuda_con_string_con_puntos(): void
    {
        // Algunos sistemas devuelven "2.500.400"
        $result = $this->invokePrivateMethod('parsearMontoDeuda', ['2.500.400']);

        $this->assertSame(2500400, $result);
    }

    public function test_parsear_monto_deuda_con_null(): void
    {
        $result = $this->invokePrivateMethod('parsearMontoDeuda', [null]);

        $this->assertSame(0, $result);
    }

    public function test_parsear_monto_deuda_con_string_vacio(): void
    {
        $result = $this->invokePrivateMethod('parsearMontoDeuda', ['']);

        $this->assertSame(0, $result);
    }

    public function test_parsear_monto_deuda_con_cero_string(): void
    {
        $result = $this->invokePrivateMethod('parsearMontoDeuda', ['0']);

        $this->assertSame(0, $result);
    }

    public function test_parsear_monto_deuda_con_espacios(): void
    {
        $result = $this->invokePrivateMethod('parsearMontoDeuda', ['2 500 400']);

        $this->assertSame(2500400, $result);
    }

    // ========================================
    // Tests para calcularNivelDeuda()
    // ========================================

    public function test_calcular_nivel_deuda_baja(): void
    {
        // Menos de 700k = baja
        $this->assertSame('baja', $this->invokePrivateMethod('calcularNivelDeuda', [500000]));
        $this->assertSame('baja', $this->invokePrivateMethod('calcularNivelDeuda', [699999]));
        $this->assertSame('baja', $this->invokePrivateMethod('calcularNivelDeuda', [100000]));
    }

    public function test_calcular_nivel_deuda_media(): void
    {
        // Entre 700k y 1.5M = media
        $this->assertSame('media', $this->invokePrivateMethod('calcularNivelDeuda', [700000]));
        $this->assertSame('media', $this->invokePrivateMethod('calcularNivelDeuda', [1000000]));
        $this->assertSame('media', $this->invokePrivateMethod('calcularNivelDeuda', [1499999]));
    }

    public function test_calcular_nivel_deuda_alta(): void
    {
        // Más de 1.5M = alta
        $this->assertSame('alta', $this->invokePrivateMethod('calcularNivelDeuda', [1500000]));
        $this->assertSame('alta', $this->invokePrivateMethod('calcularNivelDeuda', [2500400]));
        $this->assertSame('alta', $this->invokePrivateMethod('calcularNivelDeuda', [10000000]));
    }

    public function test_calcular_nivel_deuda_sin_informacion(): void
    {
        // Cero o negativo = sin_informacion
        $this->assertSame('sin_informacion', $this->invokePrivateMethod('calcularNivelDeuda', [0]));
        $this->assertSame('sin_informacion', $this->invokePrivateMethod('calcularNivelDeuda', [-100]));
    }

    // ========================================
    // Tests de límites (edge cases)
    // ========================================

    public function test_limite_exacto_700k(): void
    {
        // 700k exacto debe ser media, no baja
        $this->assertSame('media', $this->invokePrivateMethod('calcularNivelDeuda', [700000]));
    }

    public function test_limite_exacto_1500k(): void
    {
        // 1.5M exacto debe ser alta, no media
        $this->assertSame('alta', $this->invokePrivateMethod('calcularNivelDeuda', [1500000]));
    }

    public function test_un_peso_bajo_700k(): void
    {
        // 699999 debe ser baja
        $this->assertSame('baja', $this->invokePrivateMethod('calcularNivelDeuda', [699999]));
    }

    public function test_un_peso_bajo_1500k(): void
    {
        // 1499999 debe ser media
        $this->assertSame('media', $this->invokePrivateMethod('calcularNivelDeuda', [1499999]));
    }

    // ========================================
    // Tests de integración de flujo completo
    // ========================================

    public function test_flujo_completo_parseo_y_clasificacion(): void
    {
        // Simular el flujo completo: API devuelve string -> parsear -> clasificar
        $montoApi = '2500400'; // String como viene de la API

        $montoParsed = $this->invokePrivateMethod('parsearMontoDeuda', [$montoApi]);
        $nivel = $this->invokePrivateMethod('calcularNivelDeuda', [$montoParsed]);

        $this->assertSame(2500400, $montoParsed);
        $this->assertSame('alta', $nivel);
    }

    public function test_flujo_completo_con_monto_bajo(): void
    {
        $montoApi = '350000';

        $montoParsed = $this->invokePrivateMethod('parsearMontoDeuda', [$montoApi]);
        $nivel = $this->invokePrivateMethod('calcularNivelDeuda', [$montoParsed]);

        $this->assertSame(350000, $montoParsed);
        $this->assertSame('baja', $nivel);
    }

    public function test_flujo_completo_con_monto_medio(): void
    {
        $montoApi = '950000';

        $montoParsed = $this->invokePrivateMethod('parsearMontoDeuda', [$montoApi]);
        $nivel = $this->invokePrivateMethod('calcularNivelDeuda', [$montoParsed]);

        $this->assertSame(950000, $montoParsed);
        $this->assertSame('media', $nivel);
    }
}
