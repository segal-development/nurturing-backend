<?php

namespace App\Jobs;

use App\Models\Envio;
use App\Models\FlujoCondicion;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionCondicion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoJob;
use App\Services\AthenaCampaignService;
use App\Services\CondicionEvaluatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para verificar condiciones y filtrar prospectos por rama.
 * 
 * Este job evalúa CADA PROSPECTO individualmente y los separa en ramas Sí/No.
 * 
 * Ejemplo:
 * - 100 prospectos reciben email
 * - Condición: ¿Abrió email?
 * - 20 abrieron → van a rama Sí (reciben email de seguimiento)
 * - 80 no abrieron → van a rama No (reciben email de recordatorio)
 */
class VerificarCondicionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutos (reducido para evitar locks)

    public $tries = 3;

    public $backoff = [60, 300, 900]; // Reintentos: 1min, 5min, 15min

    /**
     * Create a new job instance.
     * 
     * @param int $flujoEjecucionId ID de la ejecución del flujo
     * @param int $etapaEjecucionId ID de la etapa de la condición
     * @param array $condicion Datos de la condición (target_node_id, source_node_id)
     * @param int $messageId ID del mensaje en AthenaCampaign (para fallback)
     * @param array|null $prospectoIds IDs de prospectos a evaluar (si null, usa los de la ejecución)
     */
    public function __construct(
        public int $flujoEjecucionId,
        public int $etapaEjecucionId,
        public array $condicion,
        public int $messageId,
        public ?array $prospectoIds = null
    ) {
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(CondicionEvaluatorService $evaluatorService): void
    {
        Log::info('VerificarCondicionJob: Iniciado', [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'message_id' => $this->messageId,
            'condition_node_id' => $this->condicion['target_node_id'] ?? 'unknown',
            'prospectos_count' => $this->prospectoIds ? count($this->prospectoIds) : 'from_ejecucion',
        ]);

        try {
            // 1. Obtener modelos
            $ejecucion = FlujoEjecucion::findOrFail($this->flujoEjecucionId);
            $etapaEjecucion = FlujoEjecucionEtapa::findOrFail($this->etapaEjecucionId);

            // 2. Obtener configuración de la condición
            $conditionNodeId = $this->condicion['target_node_id'];
            $sourceNodeId = $this->condicion['source_node_id'] ?? null;
            
            $flujoCondicion = FlujoCondicion::find($conditionNodeId);
            
            if ($flujoCondicion) {
                $checkParam = $flujoCondicion->check_param;
                $checkOperator = $flujoCondicion->check_operator;
                $checkValue = $flujoCondicion->check_value;
            } else {
                // Fallback a datos del array
                $conditionData = $this->condicion['data'] ?? [];
                $checkParam = $conditionData['check_param'] ?? 'Views';
                $checkOperator = $conditionData['check_operator'] ?? '>';
                $checkValue = $conditionData['check_value'] ?? '0';
                
                Log::warning('VerificarCondicionJob: Condición no encontrada en BD, usando fallback', [
                    'condition_node_id' => $conditionNodeId,
                ]);
            }

            // 3. Obtener prospectos a evaluar
            // Prioridad: parámetro del job > etapa > ejecución
            $prospectoIds = $this->prospectoIds 
                ?? $etapaEjecucion->prospectos_ids 
                ?? $ejecucion->prospectos_ids 
                ?? [];

            if (empty($prospectoIds)) {
                Log::warning('VerificarCondicionJob: No hay prospectos para evaluar', [
                    'flujo_ejecucion_id' => $this->flujoEjecucionId,
                ]);
                return;
            }

            // 4. Obtener la etapa de email anterior (para buscar envíos)
            $etapaEmailAnterior = $this->obtenerEtapaEmailAnterior($ejecucion, $sourceNodeId);

            if (!$etapaEmailAnterior) {
                Log::error('VerificarCondicionJob: No se encontró etapa de email anterior', [
                    'flujo_ejecucion_id' => $this->flujoEjecucionId,
                    'source_node_id' => $sourceNodeId,
                ]);
                
                // Marcar la etapa como fallida
                $etapaEjecucion->update([
                    'estado' => 'failed',
                    'error_mensaje' => 'No se encontró etapa de email anterior para evaluar condición',
                ]);
                return;
            }

            Log::info('VerificarCondicionJob: Evaluando prospectos por condición', [
                'total_prospectos' => count($prospectoIds),
                'etapa_email_id' => $etapaEmailAnterior->id,
                'check_param' => $checkParam,
                'check_operator' => $checkOperator,
                'check_value' => $checkValue,
            ]);

            // 5. ✅ EVALUACIÓN POR PROSPECTO - El cambio principal
            $resultado = $evaluatorService->evaluarPorProspecto(
                $prospectoIds,
                $etapaEmailAnterior->id,
                $checkParam,
                $checkOperator,
                $checkValue
            );

            // 6. Registrar condición evaluada con detalle por prospecto
            $condicionEjecucion = FlujoEjecucionCondicion::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'etapa_id' => $this->etapaEjecucionId,
                'condition_node_id' => $conditionNodeId,
                'check_param' => $checkParam,
                'check_operator' => $checkOperator,
                'check_value' => (string) $checkValue,
                'check_result_value' => $resultado['estadisticas']['si'], // Cuántos cumplieron
                'resultado' => $resultado['estadisticas']['si'] > 0 ? 'mixed' : 'no', // Resultado general
                'fecha_verificacion' => now(),
                'response_athenacampaign' => [
                    '_source' => 'local_per_prospect',
                    'estadisticas' => $resultado['estadisticas'],
                ],
                // Nuevos campos de filtrado
                'prospectos_rama_si' => $resultado['rama_si'],
                'prospectos_rama_no' => $resultado['rama_no'],
                'total_evaluados' => $resultado['estadisticas']['evaluados'],
                'total_rama_si' => $resultado['estadisticas']['si'],
                'total_rama_no' => $resultado['estadisticas']['no'],
            ]);

            // 7. Actualizar etapa de condición como completada
            $etapaEjecucion->update([
                'estado' => 'completed',
                'ejecutado' => true,
                'fecha_ejecucion' => now(),
            ]);

            // 8. Registrar job como completado
            FlujoJob::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'job_type' => 'verificar_condicion',
                'job_id' => $this->job?->uuid() ?? null,
                'job_data' => [
                    'etapa_id' => $this->etapaEjecucionId,
                    'condition_node_id' => $conditionNodeId,
                    'message_id' => $this->messageId,
                    'total_evaluados' => $resultado['estadisticas']['evaluados'],
                    'rama_si' => $resultado['estadisticas']['si'],
                    'rama_no' => $resultado['estadisticas']['no'],
                ],
                'estado' => 'completed',
                'fecha_queued' => now(),
                'fecha_procesado' => now(),
            ]);

            // 9. ✅ PROGRAMAR AMBAS RAMAS con prospectos filtrados
            $this->programarAmbasRamas($ejecucion, $condicionEjecucion, $resultado);

            Log::info('VerificarCondicionJob: Completado exitosamente', [
                'condicion_id' => $condicionEjecucion->id,
                'prospectos_rama_si' => count($resultado['rama_si']),
                'prospectos_rama_no' => count($resultado['rama_no']),
            ]);

        } catch (\Exception $e) {
            Log::error('VerificarCondicionJob: Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Registrar job como fallido
            FlujoJob::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'job_type' => 'verificar_condicion',
                'job_id' => $this->job?->uuid() ?? null,
                'job_data' => [
                    'etapa_id' => $this->etapaEjecucionId,
                    'condition_node_id' => $this->condicion['target_node_id'] ?? 'unknown',
                    'message_id' => $this->messageId,
                ],
                'estado' => 'failed',
                'fecha_queued' => now(),
                'error_details' => $e->getMessage(),
                'intentos' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    /**
     * Programa ambas ramas (Sí y No) con sus prospectos filtrados.
     */
    private function programarAmbasRamas(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionCondicion $condicion,
        array $resultado
    ): void {
        $flujoData = $ejecucion->flujo->flujo_data ?? [];
        $branches = $flujoData['branches'] ?? [];

        // Programar rama Sí (si hay prospectos)
        if (!empty($resultado['rama_si'])) {
            $this->programarRama(
                $ejecucion,
                $condicion->condition_node_id,
                'yes',
                $resultado['rama_si'],
                $branches,
                $flujoData
            );
        } else {
            Log::info('VerificarCondicionJob: Rama Sí vacía, no se programa', [
                'condition_node_id' => $condicion->condition_node_id,
            ]);
        }

        // Programar rama No (si hay prospectos)
        if (!empty($resultado['rama_no'])) {
            $this->programarRama(
                $ejecucion,
                $condicion->condition_node_id,
                'no',
                $resultado['rama_no'],
                $branches,
                $flujoData
            );
        } else {
            Log::info('VerificarCondicionJob: Rama No vacía, no se programa', [
                'condition_node_id' => $condicion->condition_node_id,
            ]);
        }

        // Si ambas ramas están vacías o no tienen siguiente nodo, completar la ejecución
        // Esto se maneja dentro de programarRama
    }

    /**
     * Programa una rama específica (yes o no) con sus prospectos.
     */
    private function programarRama(
        FlujoEjecucion $ejecucion,
        string $conditionNodeId,
        string $rama,
        array $prospectoIds,
        array $branches,
        array $flujoData
    ): void {
        // Buscar conexión para esta rama
        $siguienteConexion = collect($branches)->first(function ($branch) use ($conditionNodeId, $rama) {
            $sourceHandle = $branch['source_handle'] ?? '';
            
            $handleMatchesRama = $sourceHandle === $rama 
                || str_ends_with($sourceHandle, '-' . $rama);
            
            return $branch['source_node_id'] === $conditionNodeId && $handleMatchesRama;
        });

        if (!$siguienteConexion) {
            Log::info("VerificarCondicionJob: No hay conexión para rama {$rama}", [
                'condition_node_id' => $conditionNodeId,
            ]);
            return;
        }

        $siguienteNodeId = $siguienteConexion['target_node_id'];

        // Verificar si es un nodo final
        if (str_starts_with($siguienteNodeId, 'end-')) {
            Log::info("VerificarCondicionJob: Rama {$rama} termina en nodo final", [
                'end_node_id' => $siguienteNodeId,
                'prospectos_finalizados' => count($prospectoIds),
            ]);
            return;
        }

        // Buscar datos del siguiente nodo (puede ser stage o condition)
        $stages = $flujoData['stages'] ?? [];
        $conditions = $flujoData['conditions'] ?? [];
        
        $siguienteStage = collect($stages)->firstWhere('id', $siguienteNodeId);
        
        if (!$siguienteStage) {
            $siguienteStage = collect($conditions)->firstWhere('id', $siguienteNodeId);
        }

        if (!$siguienteStage) {
            Log::warning("VerificarCondicionJob: No se encontró el nodo {$siguienteNodeId}", [
                'rama' => $rama,
            ]);
            return;
        }

        // Calcular fecha de ejecución
        $tiempoEspera = $siguienteStage['tiempo_espera'] ?? 0;
        $fechaProgramada = now()->addDays($tiempoEspera);

        // Crear etapa con prospectos filtrados
        $nuevaEtapa = FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'etapa_id' => null,
            'node_id' => $siguienteNodeId,
            'prospectos_ids' => $prospectoIds, // ✅ Solo estos prospectos
            'fecha_programada' => $fechaProgramada,
            'estado' => 'pending',
        ]);

        Log::info("VerificarCondicionJob: Etapa programada para rama {$rama}", [
            'etapa_id' => $nuevaEtapa->id,
            'node_id' => $siguienteNodeId,
            'prospectos_count' => count($prospectoIds),
            'fecha_programada' => $fechaProgramada,
        ]);

        // Despachar job con prospectos filtrados
        EnviarEtapaJob::dispatch(
            $ejecucion->id,
            $nuevaEtapa->id,
            $siguienteStage,
            $prospectoIds, // ✅ Solo estos prospectos
            $branches
        )->delay($fechaProgramada);

        // Actualizar la ejecución con el próximo nodo de la rama principal (Sí tiene prioridad)
        if ($rama === 'yes') {
            $ejecucion->update([
                'estado' => 'in_progress',
                'proximo_nodo' => $siguienteNodeId,
                'fecha_proximo_nodo' => $fechaProgramada,
            ]);
        }
    }

    /**
     * Obtiene la etapa de email anterior para buscar envíos.
     * 
     * Estrategia de búsqueda (en orden de prioridad):
     * 1. Por source_etapa_id guardado en response_athenacampaign de la condición
     * 2. Por source_node_id si es un stage (no condition)
     * 3. Fallback: última etapa ejecutada con message_id
     */
    private function obtenerEtapaEmailAnterior(
        FlujoEjecucion $ejecucion,
        ?string $sourceNodeId
    ): ?FlujoEjecucionEtapa {
        // 1. Intentar obtener source_etapa_id del response de la condición
        $etapaCondicion = FlujoEjecucionEtapa::find($this->etapaEjecucionId);
        $responseData = $etapaCondicion?->response_athenacampaign ?? [];
        
        if (!empty($responseData['source_etapa_id'])) {
            $etapa = FlujoEjecucionEtapa::find($responseData['source_etapa_id']);
            if ($etapa && $etapa->message_id) {
                Log::info('VerificarCondicionJob: Usando source_etapa_id del response', [
                    'source_etapa_id' => $responseData['source_etapa_id'],
                    'message_id' => $etapa->message_id,
                ]);
                return $etapa;
            }
        }
        
        // 2. Si source_node_id es un stage (no una condición), buscar por node_id
        if ($sourceNodeId && str_starts_with($sourceNodeId, 'stage-')) {
            $etapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
                ->where('node_id', $sourceNodeId)
                ->where('ejecutado', true)
                ->whereNotNull('message_id')
                ->first();
            
            if ($etapa) {
                Log::info('VerificarCondicionJob: Usando etapa por source_node_id', [
                    'source_node_id' => $sourceNodeId,
                    'message_id' => $etapa->message_id,
                ]);
                return $etapa;
            }
        }
        
        // 3. Fallback: buscar la última etapa ejecutada con message_id
        // Esto cubre casos donde la condición viene justo después de una etapa
        $etapa = FlujoEjecucionEtapa::where('flujo_ejecucion_id', $ejecucion->id)
            ->where('ejecutado', true)
            ->whereNotNull('message_id')
            ->where('id', '!=', $this->etapaEjecucionId) // Excluir la condición actual
            ->orderBy('fecha_ejecucion', 'desc')
            ->first();
        
        if ($etapa) {
            Log::info('VerificarCondicionJob: Usando fallback - última etapa ejecutada', [
                'etapa_id' => $etapa->id,
                'node_id' => $etapa->node_id,
                'message_id' => $etapa->message_id,
            ]);
        }
        
        return $etapa;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Exception $exception): void
    {
        Log::error('VerificarCondicionJob: Falló permanentemente', [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'message_id' => $this->messageId,
            'error' => $exception->getMessage(),
        ]);

        FlujoJob::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'job_type' => 'verificar_condicion',
            'job_id' => $this->job?->uuid() ?? null,
            'job_data' => [
                'etapa_id' => $this->etapaEjecucionId,
                'message_id' => $this->messageId,
            ],
            'estado' => 'failed',
            'fecha_queued' => now(),
            'error_details' => "Job falló después de {$this->tries} intentos: {$exception->getMessage()}",
            'intentos' => $this->tries,
        ]);
    }
}
