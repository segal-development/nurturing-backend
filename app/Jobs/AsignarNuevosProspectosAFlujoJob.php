<?php

namespace App\Jobs;

use App\Models\Flujo;
use App\Models\Prospecto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job para asignar automáticamente nuevos prospectos a flujos activos.
 *
 * Este job busca flujos con `auto_asignar_nuevos = true` y asigna
 * prospectos del mismo origen que aún no están en el flujo.
 *
 * Los nuevos prospectos siempre empiezan desde la Etapa 1.
 *
 * Se ejecuta los viernes a las 7am (después del sync de Sysgal/IC).
 */
class AsignarNuevosProspectosAFlujoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800; // 30 minutos

    public int $tries = 3;

    private const BATCH_SIZE = 500;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info('=== Iniciando AsignarNuevosProspectosAFlujoJob ===');

        // Buscar flujos activos con auto_asignar_nuevos = true
        $flujos = Flujo::where('activo', true)
            ->where('auto_asignar_nuevos', true)
            ->get();

        if ($flujos->isEmpty()) {
            Log::info('No hay flujos con auto_asignar_nuevos activo');

            return;
        }

        Log::info("Encontrados {$flujos->count()} flujos con auto-asignación activa");

        $totalAsignados = 0;

        foreach ($flujos as $flujo) {
            $asignados = $this->procesarFlujo($flujo);
            $totalAsignados += $asignados;
        }

        Log::info('=== AsignarNuevosProspectosAFlujoJob completado ===', [
            'flujos_procesados' => $flujos->count(),
            'total_asignados' => $totalAsignados,
        ]);
    }

    /**
     * Procesa un flujo y asigna los prospectos nuevos que correspondan.
     */
    private function procesarFlujo(Flujo $flujo): int
    {
        Log::info("Procesando flujo: {$flujo->nombre}", [
            'flujo_id' => $flujo->id,
            'origen' => $flujo->origen,
        ]);

        // Obtener la primera etapa del flujo
        $primeraEtapa = $flujo->flujoEtapas()->orderBy('orden')->first();

        if (! $primeraEtapa) {
            Log::warning("Flujo {$flujo->id} no tiene etapas configuradas, saltando");

            return 0;
        }

        // Buscar prospectos del mismo origen que NO están en este flujo
        $prospectosNuevos = $this->buscarProspectosNuevos($flujo);

        if ($prospectosNuevos->isEmpty()) {
            Log::info("No hay prospectos nuevos para flujo {$flujo->id}");

            return 0;
        }

        Log::info("Encontrados {$prospectosNuevos->count()} prospectos nuevos para flujo {$flujo->id}");

        // Asignar en batches
        $asignados = $this->asignarProspectos($flujo, $prospectosNuevos, $primeraEtapa->id);

        Log::info("Asignados {$asignados} prospectos al flujo {$flujo->id}");

        return $asignados;
    }

    /**
     * Busca prospectos del mismo origen que aún no están en el flujo.
     *
     * OPTIMIZADO: Usa NOT EXISTS subquery en vez de pluck + whereNotIn.
     * Antes: Cargaba todos los IDs de prospectos_en_flujo en memoria (300k+ IDs)
     * Ahora: La DB maneja la exclusión internamente, sin cargar IDs en PHP
     */
    private function buscarProspectosNuevos(Flujo $flujo)
    {
        // Usar NOT EXISTS subquery - más eficiente que whereNotIn con arrays grandes
        // La DB puede optimizar esto con índices, sin cargar IDs en memoria PHP
        return Prospecto::query()
            ->whereHas('importacion', function ($q) use ($flujo) {
                $q->where('origen', $flujo->origen);
            })
            ->whereDoesntHave('prospectosEnFlujo', function ($q) use ($flujo) {
                $q->where('flujo_id', $flujo->id);
            })
            ->where('estado', 'activo')
            ->select('id', 'email', 'telefono')
            ->get();
    }

    /**
     * Asigna los prospectos al flujo en la primera etapa.
     */
    private function asignarProspectos(Flujo $flujo, $prospectos, int $primeraEtapaId): int
    {
        $asignados = 0;
        $now = now();

        // Determinar canal basado en el flujo
        $canalAsignado = $this->determinarCanal($flujo);

        // Procesar en batches para evitar memory issues
        $prospectos->chunk(self::BATCH_SIZE)->each(function ($batch) use ($flujo, $primeraEtapaId, $canalAsignado, $now, &$asignados) {
            $inserts = [];

            foreach ($batch as $prospecto) {
                $inserts[] = [
                    'flujo_id' => $flujo->id,
                    'prospecto_id' => $prospecto->id,
                    'canal_asignado' => $canalAsignado,
                    'estado' => 'pendiente',
                    'etapa_actual_id' => $primeraEtapaId,
                    'fecha_inicio' => $now,
                    'completado' => false,
                    'cancelado' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            try {
                DB::table('prospecto_en_flujo')->insert($inserts);
                $asignados += count($inserts);
            } catch (\Exception $e) {
                Log::error('Error insertando batch de prospectos', [
                    'flujo_id' => $flujo->id,
                    'error' => $e->getMessage(),
                ]);

                // Insertar uno por uno si falla el batch
                foreach ($inserts as $insert) {
                    try {
                        DB::table('prospecto_en_flujo')->insert($insert);
                        $asignados++;
                    } catch (\Exception $individualError) {
                        Log::debug('Error insertando prospecto individual', [
                            'prospecto_id' => $insert['prospecto_id'],
                            'error' => $individualError->getMessage(),
                        ]);
                    }
                }
            }
        });

        return $asignados;
    }

    /**
     * Determina el canal a asignar basándose en el flujo.
     */
    private function determinarCanal(Flujo $flujo): string
    {
        return match ($flujo->canal_envio) {
            'email' => 'email',
            'sms' => 'sms',
            'ambos' => 'email', // Default para flujos mixtos
            default => 'email',
        };
    }
}
