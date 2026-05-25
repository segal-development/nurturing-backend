<?php

use App\Services\Import\ImportacionRecoveryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes / Scheduled Tasks
|--------------------------------------------------------------------------
|
| Definición de comandos artisan y tareas programadas.
| El scheduler se ejecuta cada minuto via Cloud Scheduler.
|
*/

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

// ============================================================================
// RECOVERY AUTOMÁTICO DE IMPORTACIONES STUCK
// Se ejecuta cada minuto para detectar y recuperar importaciones abandonadas
// ============================================================================
Schedule::call(function () {
    Log::info('Scheduler: Iniciando verificación de importaciones stuck');

    $service = new ImportacionRecoveryService;
    $result = $service->recoverStuckImportations();

    if ($result['recovered'] > 0) {
        Log::warning('Scheduler: Recuperadas '.$result['recovered'].' importaciones stuck', [
            'importacion_ids' => $result['importaciones'],
        ]);
    }

    return $result;
})->everyMinute()
    ->name('importaciones:auto-recovery')
    ->withoutOverlapping();

// ============================================================================
// VERIFICACIÓN DE IMPORTACIONES PENDIENTES
// Detecta importaciones pendientes sin job en cola y las re-encola
// ============================================================================
Schedule::call(function () {
    $pendientes = \App\Models\Importacion::where('estado', 'pendiente')
        ->where('created_at', '<', now()->subMinutes(2)) // Más de 2 minutos pendiente
        ->whereNotNull('ruta_archivo')
        ->get();

    if ($pendientes->isEmpty()) {
        return ['requeued' => 0];
    }

    $requeued = [];

    foreach ($pendientes as $importacion) {
        // Verificar si ya hay un job en cola
        $hasJob = DB::table('jobs')
            ->where('payload', 'like', '%ProcesarImportacionJob%')
            ->where('payload', 'like', '%"importacionId";i:'.$importacion->id.';%')
            ->exists();

        if ($hasJob) {
            continue;
        }

        // Re-encolar
        $disk = $importacion->metadata['disk'] ?? 'gcs';

        \App\Jobs\ProcesarImportacionJob::dispatch(
            $importacion->id,
            $importacion->ruta_archivo,
            $disk
        );

        $requeued[] = $importacion->id;

        Log::warning('Scheduler: Re-encolada importación pendiente sin job', [
            'importacion_id' => $importacion->id,
        ]);
    }

    return ['requeued' => count($requeued), 'importaciones' => $requeued];
})->everyMinute()
    ->name('importaciones:check-pendientes')
    ->withoutOverlapping();

// ============================================================================
// ACTUALIZACIÓN DE ESTADO DE LOTES
// Recalcula totales de lotes con importaciones activas
// ============================================================================
Schedule::call(function () {
    $lotesActivos = \App\Models\Lote::whereIn('estado', ['abierto', 'procesando'])
        ->get();

    foreach ($lotesActivos as $lote) {
        $lote->recalcularTotales();
    }

    return ['lotes_actualizados' => $lotesActivos->count()];
})->everyMinute()
    ->name('lotes:recalcular-totales')
    ->withoutOverlapping();

// ============================================================================
// PROCESAR COLA DE JOBS
// Si hay jobs pendientes, los procesa directamente
// Esto es un fallback cuando el queue worker de Cloud Run no está corriendo
// ============================================================================
Schedule::command('queue:work --stop-when-empty --tries=1 --timeout=0 --max-jobs=10')
    ->everyMinute()
    ->name('queue:process-pending')
    ->withoutOverlapping()
    ->when(function () {
        // Solo ejecutar si hay jobs en cola
        return DB::table('jobs')->exists();
    });

// ============================================================================
// AGREGACIÓN MENSUAL DE ENVÍOS
// El día 1 de cada mes a las 3:00 AM, agrega estadísticas del mes anterior.
// Esto pre-calcula totales para evitar COUNT(*) sobre millones de registros.
// ============================================================================
Schedule::command('envios:agregar-mensuales')
    ->monthlyOn(1, '03:00')
    ->name('envios:agregar-mensuales')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Agregación mensual de envíos completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la agregación mensual de envíos');
    });

// ============================================================================
// LIMPIEZA MENSUAL DE DATOS
// El día 1 de cada mes a las 5:00 AM (después de agregación).
// Archiva prospectos inactivos y elimina envíos/registros viejos.
// Política de retención: 3 meses.
// ============================================================================
Schedule::command('datos:limpiar --ejecutar')
    ->monthlyOn(1, '05:00')
    ->name('datos:limpiar')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Limpieza mensual completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la limpieza mensual');
    });

// ============================================================================
// SINCRONIZACIÓN DE DESUSCRIPCIONES DESDE ATHENA
// Cada hora consulta la API de Athena para detectar nuevas desuscripciones.
// Las desuscripciones se registran en el sistema local para excluir prospectos.
// ============================================================================
Schedule::job(new \App\Jobs\SincronizarDesuscripcionesAthenaJob(7))
    ->hourly()
    ->name('athena:sincronizar-desuscripciones')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Sincronización de desuscripciones de Athena completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló sincronización de desuscripciones de Athena');
    });

// ============================================================================
// RESUMEN DIARIO DE MÉTRICAS
// DISABLED: La info ya está disponible en /metricas y /costos del dashboard.
// Si se necesita reactivar, descomentar el bloque de abajo.
// ============================================================================
// Schedule::job(new \App\Jobs\EnviarResumenDiarioJob())
//   ->dailyAt(sprintf('%02d:00', config('envios.alerts.daily_summary_hour', 8)))
//   ->name('alertas:resumen-diario')
//   ->withoutOverlapping()
//   ->onSuccess(function () {
//       Log::info('Scheduler: Resumen diario enviado correctamente');
//   })
//   ->onFailure(function () {
//       Log::error('Scheduler: Falló el envío del resumen diario');
//   });

// ============================================================================
// EJECUCIÓN DE NODOS PROGRAMADOS EN FLUJOS
// Cada minuto verifica si hay etapas de flujo listas para ejecutar
// (fecha_programada <= now) y las despacha para envío de emails/SMS.
// También verifica etapas en 'executing' para completarlas cuando terminan.
// ============================================================================
Schedule::job(new \App\Jobs\EjecutarNodosProgramados)
    ->everyMinute()
    ->name('flujos:ejecutar-nodos-programados')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Ejecución de nodos programados completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la ejecución de nodos programados');
    });

// ============================================================================
// VERIFICACIÓN DE SALUD DE APIs + REANUDAR ETAPAS PAUSADAS (circuit breaker)
// Cada 2 minutos. Consolidado desde bootstrap/app.php.
// ============================================================================
Schedule::job(\App\Jobs\VerificarSaludApiJob::class)
    ->everyTwoMinutes()
    ->name('verificar-salud-api')
    ->withoutOverlapping();

// ============================================================================
// RECUPERACIÓN DE ETAPAS ESTANCADAS
// Cada 10 minutos detecta etapas de flujo que quedaron estancadas
// (executing sin actividad) y las marca como completadas.
// Threshold: 30 minutos sin actividad.
// ============================================================================
Schedule::command('etapas:recover-stuck --minutes=30')
    ->everyTenMinutes()
    ->name('etapas:recover-stuck')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Verificación de etapas estancadas completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la verificación de etapas estancadas');
    });

// ============================================================================
// SINCRONIZACIÓN HORARIA DE CONTRATOS NUEVOS (Grupo Deudas)
// Cada hora sincroniza contratos firmados desde el último sync.
// Después del sync, dispara auto-asignación a flujos perpetuos (ver SyncGrupoDeudaCommand).
// ============================================================================
Schedule::command('sync:grupo-deuda --endpoint=contratos')
    ->hourly()
    ->name('grupo-deuda:sync-contratos-horario')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Sincronización horaria de contratos nuevos completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la sincronización horaria de contratos nuevos');
    });

// ============================================================================
// SINCRONIZACIÓN DIARIA DE CLIENTES POR FECHA INGRESO (Grupo Deudas)
// De lunes a viernes a las 7:10 AM trae clientes que firmaron hace 3 días.
// Después del sync, dispara auto-asignación a flujos de onboarding.
// Inicia: Lunes 11 de mayo 2026
// ============================================================================
Schedule::command('sync:grupo-deuda --endpoint=clientes-ingreso')
    ->weekdays()
    // Escalonado a 07:15 para no colisionar con los otros syncs de SYSGAL.
    // Llamadas en ráfaga desde la misma IP → SYSGAL devuelve 403 "No permitido".
    // Calendario SYSGAL: contratos :00, cuotas-vencer 07:05, clientes 07:15, cuotas-vencidas 07:25.
    ->dailyAt('07:15')
    ->name('grupo-deuda:sync-clientes-ingreso-diario')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Sincronización diaria de clientes ingreso completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la sincronización diaria de clientes ingreso');
    });

// ============================================================================
// SINCRONIZACIÓN DIARIA DE CUOTAS POR VENCER (Grupo Deudas)
// Diario 07:05 — escalonado para no colisionar con SYSGAL (evita 403 por ráfaga).
// Consolidado: antes era job en bootstrap/app.php a las 07:00.
// ============================================================================
Schedule::command('sync:grupo-deuda --endpoint=cuotas-vencer')
    ->dailyAt('07:05')
    ->name('grupo-deuda:sync-cuotas-vencer-diario')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Scheduler: Falló la sincronización diaria de cuotas por vencer');
    });

// ============================================================================
// SINCRONIZACIÓN DIARIA DE CUOTAS VENCIDAS (Grupo Deudas)
// Diario 07:25 — escalonado para no colisionar con SYSGAL (evita 403 por ráfaga).
// Consolidado: antes era job en bootstrap/app.php a las 07:00.
// ============================================================================
Schedule::command('sync:grupo-deuda --endpoint=cuotas-vencidas')
    ->dailyAt('07:25')
    ->name('grupo-deuda:sync-cuotas-vencidas-diario')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Scheduler: Falló la sincronización diaria de cuotas vencidas');
    });

// ============================================================================
// CACHE DE RECONCILIACIÓN SYSGAL (para el dashboard)
// Cada hora a los :40 (lejos de los syncs :00/:05/:15/:25 para no colisionar).
// Corre el reconcile contratos + clientes-ingreso del mes y deja el resumen en
// cache; el dashboard lo lee SIN pegarle en vivo a SYSGAL. Solo corre en la VM.
// ============================================================================
Schedule::command('nurturing:cache-reconciliacion')
    ->hourlyAt(40)
    ->name('cache-reconciliacion-sysgal')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Scheduler: Falló el cache de reconciliación SYSGAL');
    });

// ============================================================================
// SINCRONIZACIÓN SEMANAL DE APIs EXTERNAS (Informes Comerciales, Sysgal, etc.)
// Todos los viernes a las 6:00 AM sincroniza prospectos desde APIs externas.
// Usa sync incremental: solo trae registros nuevos desde el último sync.
// ============================================================================
Schedule::job(new \App\Jobs\SyncExternalApiJob(null, 1)) // null = todas las activas, 1 = user_id sistema
    ->weeklyOn(5, '06:00') // Viernes a las 6:00 AM
    ->name('external-api:sync-weekly')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Sincronización semanal de APIs externas completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la sincronización semanal de APIs externas');
    });

// ============================================================================
// AUTO-ASIGNACIÓN DE NUEVOS PROSPECTOS DE SYSGAL A FLUJOS POR NIVEL DE DEUDA
// Todos los viernes a las 7:00 AM (después del sync de APIs externas).
// Clasifica prospectos por nivel_deuda y los agrega a EJECUCIONES EXISTENTES:
// - baja, sin_informacion, null → SEGMENTO 1 (id: 39)
// - media                       → SEGMENTO 2 (id: 40)
// - alta                        → SEGMENTO 3 (id: 41)
// ============================================================================
Schedule::job(new \App\Jobs\AsignarProspectosSysgalJob)
    ->weeklyOn(5, '07:00') // Viernes a las 7:00 AM
    ->name('sysgal:auto-asignar-por-nivel-deuda')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Auto-asignación de prospectos Sysgal por nivel de deuda completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la auto-asignación de prospectos Sysgal');
    });

// ============================================================================
// AUTO-ASIGNACIÓN DE NUEVOS PROSPECTOS A FLUJOS CON auto_asignar_nuevos = true
// Cada hora a los :05 (después del sync de contratos del :00).
// Busca flujos activos con auto_asignar_nuevos=true y asigna prospectos nuevos
// que coincidan con lotes_ids (prioridad) o el origen del flujo.
// (Consolidado: antes corría duplicado en bootstrap/app.php hourlyAt(5) + diario 7:30.)
// ============================================================================
Schedule::job(new \App\Jobs\AsignarNuevosProspectosAFlujoJob)
    ->hourlyAt(5) // cada hora a los :05
    ->name('auto-asignar-nuevos-prospectos')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Auto-asignación de nuevos prospectos a flujos completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la auto-asignación de nuevos prospectos a flujos');
    });

// ============================================================================
// CATCH-UP DE PROSPECTOS REZAGADOS EN EJECUCIONES PERPETUAS
// Cada 5 minutos encuentra prospectos que están "atrás" del flujo principal
// (ultima_etapa_node_id anterior a la etapa actual de la ejecución) y los
// avanza a través de las etapas perdidas.
//
// Casos que maneja:
// - Prospectos nuevos (ultima_etapa = NULL) que necesitan empezar desde etapa 1
// - Prospectos importados a mitad del flujo que necesitan catch-up
// - Prospectos que fallaron en etapas anteriores y necesitan reintentar
//
// Usa queue 'catchup' separada para no interferir con envíos principales.
// ============================================================================
Schedule::job(new \App\Jobs\CatchUpProspectosJob)
    ->everyFiveMinutes()
    ->name('catch-up-prospectos')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Catch-up de prospectos rezagados completado');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló el catch-up de prospectos rezagados');
    });

// ============================================================================
// LIMPIEZA DIARIA DE PROSPECTOS COMPLETADOS EN FLUJOS PERPETUOS
// Diariamente a las 3:00 AM elimina prospectos con completado=true
// que llevan más de 1 día. Mantiene la tabla liviana para flujos perpetuos
// que procesan muchos prospectos continuamente.
// ============================================================================
Schedule::job(new \App\Jobs\LimpiezaProspectosPerpetuosJob)
    ->dailyAt('03:00')
    ->name('limpieza:prospectos-perpetuos')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Scheduler: Limpieza de prospectos perpetuos completada');
    })
    ->onFailure(function () {
        Log::error('Scheduler: Falló la limpieza de prospectos perpetuos');
    });

// ============================================================================
// HEALTH CHECK DE LA COLA Y ENVÍOS
// Cada 15 min verifica métricas críticas (cola saturada, failed_jobs spike,
// cero envíos, circuit breaker abierto) y envía email a HEALTH_CHECK_EMAIL.
// Cada tipo de alerta se deduplica por 1h para evitar spam.
// ============================================================================
Schedule::command('nurturing:health-check')
    ->everyFifteenMinutes()
    ->name('nurturing:health-check')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Scheduler: Falló el health-check');
    });
