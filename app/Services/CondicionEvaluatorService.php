<?php

namespace App\Services;

use App\Models\Envio;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para evaluar condiciones por prospecto individual.
 * 
 * En lugar de evaluar estadísticas globales (ej: "¿alguien abrió?"),
 * evalúa cada prospecto individualmente y los separa en ramas Sí/No.
 * 
 * Ejemplo:
 * - 100 prospectos reciben email
 * - Condición: ¿Abrió email? (Views > 0)
 * - 20 abrieron → prospectos_rama_si = [1, 5, 7, ...]
 * - 80 no abrieron → prospectos_rama_no = [2, 3, 4, 6, ...]
 */
class CondicionEvaluatorService
{
    /**
     * Evalúa una condición para cada prospecto y los separa en ramas.
     * 
     * @param array $prospectoIds IDs de prospectos a evaluar
     * @param int $etapaEmailId ID de la etapa de email anterior (para buscar envíos)
     * @param string $checkParam Parámetro a evaluar (Views, Clicks, Bounces)
     * @param string $checkOperator Operador de comparación (>, >=, ==, etc.)
     * @param mixed $checkValue Valor esperado
     * 
     * @return array{
     *   rama_si: array<int>,
     *   rama_no: array<int>,
     *   estadisticas: array{evaluados: int, si: int, no: int, sin_envio: int}
     * }
     */
    public function evaluarPorProspecto(
        array $prospectoIds,
        int $etapaEmailId,
        string $checkParam,
        string $checkOperator,
        mixed $checkValue
    ): array {
        $ramaSi = [];
        $ramaNo = [];
        $sinEnvio = 0;

        // Obtener todos los envíos de la etapa anterior para estos prospectos
        $envios = $this->obtenerEnviosPorEtapa($etapaEmailId, $prospectoIds);
        
        // Crear un mapa de prospecto_id => envio para búsqueda rápida
        $enviosPorProspecto = $envios->keyBy('prospecto_id');

        Log::info('CondicionEvaluatorService: Evaluando prospectos', [
            'total_prospectos' => count($prospectoIds),
            'envios_encontrados' => $envios->count(),
            'check_param' => $checkParam,
            'check_operator' => $checkOperator,
            'check_value' => $checkValue,
        ]);

        foreach ($prospectoIds as $prospectoId) {
            $envio = $enviosPorProspecto->get($prospectoId);

            if (!$envio) {
                // Si no hay envío registrado, va a rama No (conservador)
                $ramaNo[] = $prospectoId;
                $sinEnvio++;
                
                Log::debug('CondicionEvaluatorService: Prospecto sin envío → rama No', [
                    'prospecto_id' => $prospectoId,
                ]);
                continue;
            }

            // Evaluar la condición para este prospecto
            $cumpleCondicion = $this->evaluarProspectoIndividual(
                $envio,
                $checkParam,
                $checkOperator,
                $checkValue
            );

            if ($cumpleCondicion) {
                $ramaSi[] = $prospectoId;
            } else {
                $ramaNo[] = $prospectoId;
            }
        }

        $resultado = [
            'rama_si' => $ramaSi,
            'rama_no' => $ramaNo,
            'estadisticas' => [
                'evaluados' => count($prospectoIds),
                'si' => count($ramaSi),
                'no' => count($ramaNo),
                'sin_envio' => $sinEnvio,
            ],
        ];

        Log::info('CondicionEvaluatorService: Evaluación completada', [
            'total_evaluados' => $resultado['estadisticas']['evaluados'],
            'rama_si' => $resultado['estadisticas']['si'],
            'rama_no' => $resultado['estadisticas']['no'],
            'sin_envio' => $resultado['estadisticas']['sin_envio'],
        ]);

        return $resultado;
    }

    /**
     * Evalúa si un prospecto individual cumple la condición.
     * 
     * @param Envio $envio El envío del prospecto
     * @param string $checkParam Parámetro (Views, Clicks, Bounces)
     * @param string $checkOperator Operador (>, >=, ==, etc.)
     * @param mixed $checkValue Valor esperado
     * @return bool True si cumple la condición (va a rama Sí)
     */
    private function evaluarProspectoIndividual(
        Envio $envio,
        string $checkParam,
        string $checkOperator,
        mixed $checkValue
    ): bool {
        // Obtener el valor actual del prospecto según el parámetro
        $valorActual = $this->obtenerValorProspecto($envio, $checkParam);

        // Evaluar con el operador
        return $this->compararValores($valorActual, $checkOperator, $checkValue);
    }

    /**
     * Obtiene el valor de un parámetro para un prospecto específico.
     * 
     * @param Envio $envio
     * @param string $checkParam
     * @return int|bool El valor del parámetro
     */
    private function obtenerValorProspecto(Envio $envio, string $checkParam): int|bool
    {
        return match (strtolower($checkParam)) {
            // Para Views: 1 si abrió, 0 si no
            'views', 'email_opened', 'aperturas' => $envio->fecha_abierto !== null ? 1 : 0,
            
            // Para Clicks: 1 si clickeó, 0 si no
            'clicks', 'email_clicked' => $envio->fecha_clickeado !== null ? 1 : 0,
            
            // Para Bounces: 1 si rebotó, 0 si no
            'bounces', 'email_bounced' => $envio->estado === 'bounced' ? 1 : 0,
            
            // Para total de aperturas (número exacto)
            'total_aperturas' => $envio->total_aperturas ?? 0,
            
            // Para total de clicks (número exacto)
            'total_clicks' => $envio->total_clicks ?? 0,
            
            // Por defecto, retornar 0
            default => 0,
        };
    }

    /**
     * Compara dos valores según un operador.
     * 
     * @param int|bool $valorActual
     * @param string $operador
     * @param mixed $valorEsperado
     * @return bool
     */
    private function compararValores(int|bool $valorActual, string $operador, mixed $valorEsperado): bool
    {
        // Convertir valor esperado a entero para comparación
        $valorEsperado = (int) $valorEsperado;
        $valorActual = (int) $valorActual;

        return match ($operador) {
            '>' => $valorActual > $valorEsperado,
            '>=' => $valorActual >= $valorEsperado,
            '==' => $valorActual == $valorEsperado,
            '!=' => $valorActual != $valorEsperado,
            '<' => $valorActual < $valorEsperado,
            '<=' => $valorActual <= $valorEsperado,
            default => false,
        };
    }

    /**
     * Obtiene los envíos de una etapa para un conjunto de prospectos.
     * 
     * @param int $etapaEjecucionId ID de FlujoEjecucionEtapa
     * @param array $prospectoIds IDs de prospectos
     * @return Collection<Envio>
     */
    private function obtenerEnviosPorEtapa(int $etapaEjecucionId, array $prospectoIds): Collection
    {
        return Envio::where('flujo_ejecucion_etapa_id', $etapaEjecucionId)
            ->whereIn('prospecto_id', $prospectoIds)
            ->get();
    }

    /**
     * Evalúa una condición usando estadísticas globales (fallback).
     * 
     * Se usa cuando no hay envíos individuales registrados
     * o para compatibilidad con el comportamiento anterior.
     * 
     * @param array $stats Estadísticas globales de AthenaCampaign
     * @param string $checkParam
     * @param string $checkOperator
     * @param mixed $checkValue
     * @return bool
     */
    public function evaluarEstadisticasGlobales(
        array $stats,
        string $checkParam,
        string $checkOperator,
        mixed $checkValue
    ): bool {
        $valorActual = $stats[$checkParam] ?? 0;
        return $this->compararValores($valorActual, $checkOperator, $checkValue);
    }
}
