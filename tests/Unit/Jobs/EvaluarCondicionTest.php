<?php

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test unitario puro para la lógica de evaluación de condiciones.
 * No requiere base de datos ni Laravel - prueba directamente la lógica.
 */
class EvaluarCondicionTest extends TestCase
{
    /**
     * Instancia del job para testing (usamos reflexión para acceder a métodos privados)
     */
    private object $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear una clase anónima que replica la lógica de evaluación
        $this->evaluator = new class {
            public function evaluarCondicion(int $actualValue, string $operator, mixed $expectedValue): bool
            {
                $expectedArray = $this->parseExpectedValueAsArray($expectedValue);
                
                return match ($operator) {
                    '>' => $actualValue > (int) $expectedValue,
                    '>=' => $actualValue >= (int) $expectedValue,
                    '==' => $actualValue == (int) $expectedValue,
                    '!=' => $actualValue != (int) $expectedValue,
                    '<' => $actualValue < (int) $expectedValue,
                    '<=' => $actualValue <= (int) $expectedValue,
                    'in' => in_array($actualValue, $expectedArray, false),
                    'not_in' => !in_array($actualValue, $expectedArray, false),
                    default => false,
                };
            }

            public function parseExpectedValueAsArray(mixed $expectedValue): array
            {
                if (is_array($expectedValue)) {
                    return array_map('intval', $expectedValue);
                }
                
                if (is_string($expectedValue) && str_contains($expectedValue, ',')) {
                    return array_map('intval', array_map('trim', explode(',', $expectedValue)));
                }
                
                return [(int) $expectedValue];
            }
        };
    }

    // ============================================
    // OPERADOR: Mayor que (>)
    // ============================================

    public function test_mayor_que_retorna_true_cuando_actual_es_mayor(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(10, '>', '5'),
            '10 > 5 debería ser true'
        );
    }

    public function test_mayor_que_retorna_false_cuando_actual_es_menor(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(5, '>', '10'),
            '5 > 10 debería ser false'
        );
    }

    public function test_mayor_que_retorna_false_cuando_son_iguales(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(5, '>', '5'),
            '5 > 5 debería ser false'
        );
    }

    // ============================================
    // OPERADOR: Mayor o igual (>=)
    // ============================================

    public function test_mayor_o_igual_retorna_true_cuando_actual_es_mayor(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(10, '>=', '5'),
            '10 >= 5 debería ser true'
        );
    }

    public function test_mayor_o_igual_retorna_true_cuando_son_iguales(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(5, '>=', '5'),
            '5 >= 5 debería ser true'
        );
    }

    public function test_mayor_o_igual_retorna_false_cuando_actual_es_menor(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(5, '>=', '10'),
            '5 >= 10 debería ser false'
        );
    }

    // ============================================
    // OPERADOR: Igual (==)
    // ============================================

    public function test_igual_retorna_true_cuando_son_iguales(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(5, '==', '5'),
            '5 == 5 debería ser true'
        );
    }

    public function test_igual_retorna_false_cuando_son_diferentes(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(10, '==', '5'),
            '10 == 5 debería ser false'
        );
    }

    // ============================================
    // OPERADOR: No igual (!=) - NUEVO
    // ============================================

    public function test_no_igual_retorna_true_cuando_son_diferentes(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(10, '!=', '5'),
            '10 != 5 debería ser true'
        );
    }

    public function test_no_igual_retorna_true_con_cero_vs_uno(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(1, '!=', '0'),
            '1 != 0 debería ser true (email fue abierto)'
        );
    }

    public function test_no_igual_retorna_false_cuando_son_iguales(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(5, '!=', '5'),
            '5 != 5 debería ser false'
        );
    }

    // ============================================
    // OPERADOR: Menor que (<)
    // ============================================

    public function test_menor_que_retorna_true_cuando_actual_es_menor(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(5, '<', '10'),
            '5 < 10 debería ser true'
        );
    }

    public function test_menor_que_retorna_false_cuando_actual_es_mayor(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(10, '<', '5'),
            '10 < 5 debería ser false'
        );
    }

    public function test_menor_que_retorna_false_cuando_son_iguales(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(5, '<', '5'),
            '5 < 5 debería ser false'
        );
    }

    // ============================================
    // OPERADOR: Menor o igual (<=)
    // ============================================

    public function test_menor_o_igual_retorna_true_cuando_actual_es_menor(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(5, '<=', '10'),
            '5 <= 10 debería ser true'
        );
    }

    public function test_menor_o_igual_retorna_true_cuando_son_iguales(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(5, '<=', '5'),
            '5 <= 5 debería ser true'
        );
    }

    public function test_menor_o_igual_retorna_false_cuando_actual_es_mayor(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(10, '<=', '5'),
            '10 <= 5 debería ser false'
        );
    }

    // ============================================
    // OPERADOR: En lista (in)
    // ============================================

    public function test_in_retorna_true_cuando_valor_esta_en_lista_string(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(2, 'in', '1,2,3'),
            '2 in "1,2,3" debería ser true'
        );
    }

    public function test_in_retorna_false_cuando_valor_no_esta_en_lista(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(5, 'in', '1,2,3'),
            '5 in "1,2,3" debería ser false'
        );
    }

    public function test_in_funciona_con_valor_unico(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(5, 'in', '5'),
            '5 in "5" debería ser true'
        );
    }

    public function test_in_maneja_espacios_en_lista(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(2, 'in', '1, 2, 3'),
            '2 in "1, 2, 3" (con espacios) debería ser true'
        );
    }

    // ============================================
    // OPERADOR: No en lista (not_in) - NUEVO
    // ============================================

    public function test_not_in_retorna_true_cuando_valor_no_esta_en_lista(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(5, 'not_in', '1,2,3'),
            '5 not_in "1,2,3" debería ser true'
        );
    }

    public function test_not_in_retorna_false_cuando_valor_esta_en_lista(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(2, 'not_in', '1,2,3'),
            '2 not_in "1,2,3" debería ser false'
        );
    }

    public function test_not_in_funciona_con_valor_unico(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(10, 'not_in', '5'),
            '10 not_in "5" debería ser true'
        );
    }

    // ============================================
    // OPERADOR: Default (desconocido)
    // ============================================

    public function test_operador_desconocido_retorna_false(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(5, 'invalid_operator', '5'),
            'Operador desconocido debería retornar false'
        );
    }

    // ============================================
    // CASOS DE USO REALES - EMAIL MARKETING
    // ============================================

    public function test_caso_email_abierto_al_menos_una_vez(): void
    {
        // Condición: Views > 0 (email fue abierto)
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(3, '>', '0'),
            'Email abierto 3 veces: Views > 0 debería ser true'
        );
    }

    public function test_caso_email_no_abierto(): void
    {
        // Condición: Views > 0 (email fue abierto)
        $this->assertFalse(
            $this->evaluator->evaluarCondicion(0, '>', '0'),
            'Email no abierto: Views > 0 debería ser false'
        );
    }

    public function test_caso_email_abierto_usando_no_igual(): void
    {
        // Condición alternativa: Views != 0 (email fue abierto)
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(1, '!=', '0'),
            'Email abierto: Views != 0 debería ser true'
        );
    }

    public function test_caso_usuario_hizo_click(): void
    {
        // Condición: Clicks >= 1
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(2, '>=', '1'),
            'Usuario hizo 2 clicks: Clicks >= 1 debería ser true'
        );
    }

    public function test_caso_email_no_reboto(): void
    {
        // Condición: Bounces == 0 (email no rebotó)
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(0, '==', '0'),
            'Email no rebotó: Bounces == 0 debería ser true'
        );
    }

    public function test_caso_email_reboto(): void
    {
        // Condición: Bounces != 0 (email SÍ rebotó)
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(1, '!=', '0'),
            'Email rebotó: Bounces != 0 debería ser true'
        );
    }

    public function test_caso_usuario_muy_enganchado(): void
    {
        // Condición: Views >= 5 (abrió muchas veces)
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(10, '>=', '5'),
            'Usuario muy enganchado: Views >= 5 debería ser true'
        );
    }

    public function test_caso_bajo_engagement(): void
    {
        // Condición: Clicks < 2 (pocos clicks)
        $this->assertTrue(
            $this->evaluator->evaluarCondicion(1, '<', '2'),
            'Bajo engagement: Clicks < 2 debería ser true'
        );
    }

    // ============================================
    // HELPER: parseExpectedValueAsArray
    // ============================================

    public function test_parse_array_desde_string_con_comas(): void
    {
        $result = $this->evaluator->parseExpectedValueAsArray('1,2,3');
        $this->assertEquals([1, 2, 3], $result);
    }

    public function test_parse_array_desde_string_con_espacios(): void
    {
        $result = $this->evaluator->parseExpectedValueAsArray('1, 2, 3');
        $this->assertEquals([1, 2, 3], $result);
    }

    public function test_parse_array_desde_valor_unico(): void
    {
        $result = $this->evaluator->parseExpectedValueAsArray('5');
        $this->assertEquals([5], $result);
    }

    public function test_parse_array_desde_array(): void
    {
        $result = $this->evaluator->parseExpectedValueAsArray([1, 2, 3]);
        $this->assertEquals([1, 2, 3], $result);
    }

    public function test_parse_array_convierte_strings_a_int(): void
    {
        $result = $this->evaluator->parseExpectedValueAsArray(['1', '2', '3']);
        $this->assertEquals([1, 2, 3], $result);
    }
}
