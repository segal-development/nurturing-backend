<?php

namespace App\Jobs;

use App\Models\FlujoCondicion;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionCondicion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\FlujoJob;
use App\Services\AthenaCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerificarCondicionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutos

    public $tries = 3;

    public $backoff = [60, 300, 900]; // Reintentos: 1min, 5min, 15min

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $flujoEjecucionId,
        public int $etapaEjecucionId,
        public array $condicion,
        public int $messageId
    ) {
        // ✅ Indica que el job debe despacharse solo DESPUÉS del commit de la transacción
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(AthenaCampaignService $athenaService): void
    {
        Log::info('VerificarCondicionJob: Iniciado', [
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_ejecucion_id' => $this->etapaEjecucionId,
            'message_id' => $this->messageId,
            'condition_node_id' => $this->condicion['target_node_id'] ?? 'unknown',
        ]);

        DB::beginTransaction();

        try {
            // 1. Obtener modelos
            $ejecucion = FlujoEjecucion::findOrFail($this->flujoEjecucionId);
            $etapaEjecucion = FlujoEjecucionEtapa::findOrFail($this->etapaEjecucionId);

            // 2. Obtener estadísticas de AthenaCampaign
            Log::info('VerificarCondicionJob: Consultando estadísticas', [
                'message_id' => $this->messageId,
            ]);

            $statsResponse = $athenaService->getStatistics($this->messageId);

            if ($statsResponse['error'] ?? true) {
                throw new \Exception('Error al obtener estadísticas de AthenaCampaign');
            }

            $stats = $statsResponse['mensaje'] ?? [];

            Log::info('VerificarCondicionJob: Estadísticas recibidas', [
                'stats' => $stats,
            ]);

            // 3. Extraer datos de la condición desde la BD
            $conditionNodeId = $this->condicion['target_node_id'];
            
            // Obtener la condición de la base de datos (tiene los check_* correctos)
            $flujoCondicion = FlujoCondicion::find($conditionNodeId);
            
            if ($flujoCondicion) {
                // Usar datos de la BD (fuente de verdad)
                $checkParam = $flujoCondicion->check_param;
                $checkOperator = $flujoCondicion->check_operator;
                $checkValue = $flujoCondicion->check_value;
                
                Log::info('VerificarCondicionJob: Usando datos de BD', [
                    'condition_node_id' => $conditionNodeId,
                    'condition_type' => $flujoCondicion->condition_type,
                ]);
            } else {
                // Fallback a datos del array (compatibilidad)
                $conditionData = $this->condicion['data'] ?? [];
                $checkParam = $conditionData['check_param'] ?? 'Views';
                $checkOperator = $conditionData['check_operator'] ?? '>';
                $checkValue = $conditionData['check_value'] ?? '0';
                
                Log::warning('VerificarCondicionJob: Condición no encontrada en BD, usando fallback', [
                    'condition_node_id' => $conditionNodeId,
                ]);
            }

            // 4. Obtener valor actual de las estadísticas
            $actualValue = $stats[$checkParam] ?? 0;

            Log::info('VerificarCondicionJob: Evaluando condición', [
                'check_param' => $checkParam,
                'check_operator' => $checkOperator,
                'check_value' => $checkValue,
                'actual_value' => $actualValue,
            ]);

            // 5. Evaluar condición
            $resultado = $this->evaluarCondicion($actualValue, $checkOperator, $checkValue);

            Log::info('VerificarCondicionJob: Resultado de condición', [
                'resultado' => $resultado ? 'yes' : 'no',
            ]);

            // 6. Registrar condición evaluada
            $condicionEjecucion = FlujoEjecucionCondicion::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'etapa_id' => $this->etapaEjecucionId,
                'condition_node_id' => $conditionNodeId,
                'check_param' => $checkParam,
                'check_operator' => $checkOperator,
                'check_value' => (string) $checkValue,
                'check_result_value' => $actualValue,
                'resultado' => $resultado ? 'yes' : 'no',
                'fecha_verificacion' => now(),
                'response_athenacampaign' => $statsResponse,
            ]);

            // 7. Registrar job como completado
            FlujoJob::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'job_type' => 'verificar_condicion',
                'job_id' => $this->job->uuid() ?? null,
                'job_data' => [
                    'etapa_id' => $this->etapaEjecucionId,
                    'condition_node_id' => $conditionNodeId,
                    'message_id' => $this->messageId,
                ],
                'estado' => 'completed',
                'fecha_queued' => now(),
                'fecha_procesado' => now(),
            ]);

            // 8. Determinar siguiente etapa según resultado
            $this->procesarSiguienteEtapa($ejecucion, $condicionEjecucion, $resultado);

            DB::commit();

            Log::info('VerificarCondicionJob: Completado exitosamente', [
                'condicion_id' => $condicionEjecucion->id,
                'resultado' => $resultado ? 'yes' : 'no',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('VerificarCondicionJob: Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Registrar job como fallido
            FlujoJob::create([
                'flujo_ejecucion_id' => $this->flujoEjecucionId,
                'job_type' => 'verificar_condicion',
                'job_id' => $this->job->uuid() ?? null,
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
     * Evalúa la condición según el operador
     */
    private function evaluarCondicion(int $actualValue, string $operator, mixed $expectedValue): bool
    {
        return match ($operator) {
            '>' => $actualValue > (int) $expectedValue,
            '>=' => $actualValue >= (int) $expectedValue,
            '==' => $actualValue == (int) $expectedValue,
            '<' => $actualValue < (int) $expectedValue,
            '<=' => $actualValue <= (int) $expectedValue,
            'in' => in_array($actualValue, (array) $expectedValue),
            default => false,
        };
    }

    /**
     * Procesa la siguiente etapa según el resultado de la condición
     */
    private function procesarSiguienteEtapa(
        FlujoEjecucion $ejecucion,
        FlujoEjecucionCondicion $condicion,
        bool $resultado
    ): void {
        // Buscar siguiente etapa según rama yes/no
        $ramaElegida = $resultado ? 'yes' : 'no';

        Log::info('VerificarCondicionJob: Buscando siguiente etapa', [
            'rama' => $ramaElegida,
            'condition_node_id' => $condicion->condition_node_id,
        ]);

        // Buscar conexión desde este nodo de condición
        $flujoData = $ejecucion->flujo->flujo_data ?? [];
        $branches = $flujoData['branches'] ?? [];

        $siguienteConexion = collect($branches)->first(function ($branch) use ($condicion, $ramaElegida) {
            $sourceHandle = $branch['source_handle'] ?? '';
            
            // El handle puede ser 'yes'/'no' o '{nodeId}-yes'/'{nodeId}-no'
            $handleMatchesRama = $sourceHandle === $ramaElegida 
                || str_ends_with($sourceHandle, '-' . $ramaElegida);
            
            return $branch['source_node_id'] === $condicion->condition_node_id
                && $handleMatchesRama;
        });

        if (! $siguienteConexion) {
            Log::info('VerificarCondicionJob: No hay siguiente etapa para esta rama', [
                'rama' => $ramaElegida,
            ]);

            return;
        }

        $siguienteNodeId = $siguienteConexion['target_node_id'];

        Log::info('VerificarCondicionJob: Siguiente nodo encontrado', [
            'siguiente_node_id' => $siguienteNodeId,
            'rama' => $ramaElegida,
        ]);

        // Buscar datos del siguiente nodo
        $stages = $flujoData['stages'] ?? [];
        $siguienteStage = collect($stages)->first(function ($stage) use ($siguienteNodeId) {
            return $stage['id'] === $siguienteNodeId;
        });

        if (! $siguienteStage) {
            Log::warning('VerificarCondicionJob: No se encontró el stage para el siguiente nodo', [
                'siguiente_node_id' => $siguienteNodeId,
            ]);

            return;
        }

        // Calcular fecha de ejecución (ahora + tiempo_espera o inmediatamente)
        $tiempoEspera = $siguienteStage['tiempo_espera'] ?? 0; // días
        $fechaProgramada = now()->addDays($tiempoEspera);

        // Crear registro de etapa de ejecución
        $nuevaEtapaEjecucion = FlujoEjecucionEtapa::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'etapa_id' => null,
            'node_id' => $siguienteNodeId,
            'fecha_programada' => $fechaProgramada,
            'estado' => 'pending',
        ]);

        Log::info('VerificarCondicionJob: Nueva etapa programada', [
            'etapa_ejecucion_id' => $nuevaEtapaEjecucion->id,
            'fecha_programada' => $fechaProgramada,
            'tiempo_espera_dias' => $tiempoEspera,
        ]);

        // Encolar job para enviar esta etapa
        EnviarEtapaJob::dispatch(
            $this->flujoEjecucionId,
            $nuevaEtapaEjecucion->id,
            $siguienteStage,
            $ejecucion->prospectos_ids,
            $branches
        )->delay($fechaProgramada);

        // ✅ ACTUALIZAR EJECUCIÓN: Mantener como in_progress con próximo nodo programado
        $ejecucion->update([
            'estado' => 'in_progress',
            'proximo_nodo' => $siguienteNodeId,
            'fecha_proximo_nodo' => $fechaProgramada,
        ]);

        Log::info('VerificarCondicionJob: EnviarEtapaJob programado', [
            'delay_dias' => $tiempoEspera,
            'estado_ejecucion' => 'in_progress',
            'proximo_nodo' => $siguienteNodeId,
        ]);
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

        // Registrar fallo permanente
        FlujoJob::create([
            'flujo_ejecucion_id' => $this->flujoEjecucionId,
            'job_type' => 'verificar_condicion',
            'job_id' => $this->job->uuid() ?? null,
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
