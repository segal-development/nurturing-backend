<?php

namespace Tests\Unit\Services;

use App\Models\Flujo;
use App\Services\GuardedTransition;
use Tests\TestCase;

/**
 * TDD RED → GREEN: offsetAcumulado no debe añadir baseline +3 para Clientes Ingreso.
 *
 * Regresión producción flujo 49: el gate temporal bloqueaba a 65 prospectos nuevos
 * porque offsetAcumulado devolvía 3 para la primera etapa (tiempo_espera=0) en lugar
 * de 0. La causa: baseline `$esClientesIngreso ? 3 : 0` en GuardedTransition.
 *
 * Contexto del fix:
 *   - flujo Clientes-Ingreso: fecha_inicio = fecha_ingreso + 3 (lo pone el sync/job)
 *   - gate: now() >= fecha_inicio + offsetAcumulado(etapa)
 *   - primera etapa tiempo_espera=0 → offsetAcumulado debe ser 0 → elegible desde fecha_inicio
 *   - labels correctos: 3/4/5/10/18 días desde ingreso = fecha_inicio + 0/1/2/7/15
 *   - el +3 ya está absorbido en fecha_inicio, NO debe sumarse en offsetAcumulado
 *
 * Usa Flujo::make() (sin DB) ya que offsetAcumulado sólo lee config_structure y origen.
 */
class GuardedTransitionOffsetAcumuladoTest extends TestCase
{
    private GuardedTransition $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = app(GuardedTransition::class);
    }

    /**
     * T-OA-1 (RED antes del fix): flujo Clientes-Ingreso, primera etapa tiempo_espera=0
     * → offsetAcumulado debe ser 0, NO 3.
     *
     * Este test falla con el código incorrecto (devuelve 3) y pasa después del fix (devuelve 0).
     */
    /** @test */
    public function offset_primera_etapa_clientes_ingreso_es_cero(): void
    {
        $flujo = new Flujo([
            'origen' => 'Grupo Deudas - Clientes Ingreso',
            'config_structure' => $this->buildClientesIngresoStructure(),
        ]);

        // Primera etapa del flujo: tiempo_espera=0 → offset acumulado debe ser 0
        $offset = $this->guard->offsetAcumulado($flujo, 'etapa-dia3');

        $this->assertSame(
            0,
            $offset,
            'La primera etapa de Clientes-Ingreso (tiempo_espera=0) debe tener offset=0. ' .
            'El +3 ya está absorbido en fecha_inicio (ingreso+3), no debe sumarse aquí.'
        );
    }

    /**
     * T-OA-2: segunda etapa tiempo_espera=1 → offset debe ser 0+1=1, no 3+1=4.
     */
    /** @test */
    public function offset_segunda_etapa_clientes_ingreso_es_solo_suma_de_tiempos(): void
    {
        $flujo = new Flujo([
            'origen' => 'Grupo Deudas - Clientes Ingreso',
            'config_structure' => $this->buildClientesIngresoStructure(),
        ]);

        // Segunda etapa: tiempo_espera=1 → offset = 0 (primera) + 1 = 1
        $offset = $this->guard->offsetAcumulado($flujo, 'etapa-dia4');

        $this->assertSame(
            1,
            $offset,
            'Etapa con tiempo_espera=1 debe tener offset=1 (solo suma de tiempo_espera). ' .
            'Sin baseline +3.'
        );
    }

    /**
     * T-OA-3: flujo con origen distinto (SEGMENTO) no se afecta — sigue siendo 0 para
     * primera etapa. Confirma no-regresión.
     */
    /** @test */
    public function offset_primera_etapa_flujo_segmento_es_cero(): void
    {
        $flujo = new Flujo([
            'origen' => 'Grupo Deudas - Segmento',
            'config_structure' => $this->buildSegmentoStructure(),
        ]);

        $offset = $this->guard->offsetAcumulado($flujo, 'etapa-s1');

        $this->assertSame(
            0,
            $offset,
            'Flujo Segmento: primera etapa tiempo_espera=0 debe tener offset=0.'
        );
    }

    /**
     * T-OA-4: flujo Clientes-Ingreso, tercera etapa acumulada = 0+1+2=3.
     * Verifica que el acumulado es correcto a lo largo de la cadena.
     */
    /** @test */
    public function offset_tercera_etapa_clientes_ingreso_acumula_correctamente(): void
    {
        $flujo = new Flujo([
            'origen' => 'Grupo Deudas - Clientes Ingreso',
            'config_structure' => $this->buildClientesIngresoStructure(),
        ]);

        // Tercera etapa: tiempo_espera=2 → offset = 0 + 1 + 2 = 3
        $offset = $this->guard->offsetAcumulado($flujo, 'etapa-dia5');

        $this->assertSame(
            3,
            $offset,
            'Tercera etapa (tiempo_espera=2) debe tener offset acumulado=3 (0+1+2).'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Estructura representativa de flujo 49 (Clientes Ingreso).
     * Labels reales: día 3 / día 4 / día 5 / día 10 / día 18 desde ingreso.
     * Desde fecha_inicio (= ingreso+3): 0 / 1 / 2 / 7 / 15 días.
     * tiempo_espera: 0 / 1 / 2 / 7 / 8.
     */
    private function buildClientesIngresoStructure(): array
    {
        return [
            'initial_node' => 'start-1',
            'stages' => [
                ['id' => 'start-1',   'type' => 'start', 'tiempo_espera' => 0],
                ['id' => 'etapa-dia3', 'type' => 'email', 'tiempo_espera' => 0],
                ['id' => 'etapa-dia4', 'type' => 'email', 'tiempo_espera' => 1],
                ['id' => 'etapa-dia5', 'type' => 'email', 'tiempo_espera' => 2],
                ['id' => 'etapa-dia10','type' => 'email', 'tiempo_espera' => 7],
                ['id' => 'etapa-dia18','type' => 'email', 'tiempo_espera' => 8],
                ['id' => 'end-1',      'type' => 'end',   'tiempo_espera' => 0],
            ],
            'branches' => [
                ['source_node_id' => 'start-1',    'target_node_id' => 'etapa-dia3'],
                ['source_node_id' => 'etapa-dia3', 'target_node_id' => 'etapa-dia4'],
                ['source_node_id' => 'etapa-dia4', 'target_node_id' => 'etapa-dia5'],
                ['source_node_id' => 'etapa-dia5', 'target_node_id' => 'etapa-dia10'],
                ['source_node_id' => 'etapa-dia10','target_node_id' => 'etapa-dia18'],
                ['source_node_id' => 'etapa-dia18','target_node_id' => 'end-1'],
            ],
        ];
    }

    /**
     * Estructura simple para flujo Segmento (sin baseline especial).
     */
    private function buildSegmentoStructure(): array
    {
        return [
            'initial_node' => 'start-1',
            'stages' => [
                ['id' => 'start-1', 'type' => 'start', 'tiempo_espera' => 0],
                ['id' => 'etapa-s1', 'type' => 'email', 'tiempo_espera' => 0],
                ['id' => 'etapa-s2', 'type' => 'email', 'tiempo_espera' => 30],
                ['id' => 'end-1',    'type' => 'end',   'tiempo_espera' => 0],
            ],
            'branches' => [
                ['source_node_id' => 'start-1',  'target_node_id' => 'etapa-s1'],
                ['source_node_id' => 'etapa-s1', 'target_node_id' => 'etapa-s2'],
                ['source_node_id' => 'etapa-s2', 'target_node_id' => 'end-1'],
            ],
        ];
    }
}
