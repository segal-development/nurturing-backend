<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\CostoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesuscripcionController;
use App\Http\Controllers\EnvioController;
use App\Http\Controllers\FlujoController;
use App\Http\Controllers\FlujoEjecucionController;
use App\Http\Controllers\ImportacionController;
use App\Http\Controllers\LoteController;
use App\Http\Controllers\MetricasController;
use App\Http\Controllers\MonitoreoController;
use App\Http\Controllers\PlantillaController;
use App\Http\Controllers\ProspectoController;
use App\Http\Controllers\TestingController;
use App\Http\Controllers\TipoProspectoController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

// Rutas públicas de autenticación
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Rutas para Cloud Scheduler - procesar jobs de la cola
// Protegidas por un header secreto en lugar de auth
Route::post('/cron/process-queue', [TestingController::class, 'processQueue'])
    ->middleware('cron.secret');
Route::get('/cron/debug-ejecuciones', [TestingController::class, 'debugEjecuciones'])
    ->middleware('cron.secret');
Route::get('/cron/debug-flujo/{flujoId}', [TestingController::class, 'debugFlujo'])
    ->middleware('cron.secret');
Route::get('/cron/debug-contenido/{stageId}', [TestingController::class, 'debugContenido'])
    ->middleware('cron.secret');
Route::get('/cron/monitor-envios/{ejecucionId}', [TestingController::class, 'monitorEnvios'])
    ->middleware('cron.secret');
Route::post('/cron/reiniciar-ejecucion/{ejecucionId}', [TestingController::class, 'reiniciarEjecucion'])
    ->middleware('cron.secret');

// Rutas protegidas con Sanctum (sesión o token)
Route::middleware('auth:sanctum')->group(function () {
    // Autenticación
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Rutas de Prospectos
    Route::get('/prospectos/count', [ProspectoController::class, 'count']);
    Route::get('/prospectos/conteo-por-tipo', [ProspectoController::class, 'conteoPorTipo']);
    Route::get('/prospectos/estadisticas', [ProspectoController::class, 'estadisticas']);
    Route::get('/prospectos/opciones-filtrado', [ProspectoController::class, 'opcionesFiltrado']);
    Route::apiResource('prospectos', ProspectoController::class);

    // Rutas de Tipos de Prospecto (categorías por monto)
    Route::get('/tipos-prospecto', [TipoProspectoController::class, 'index']);

    // Rutas de Lotes (agrupación de importaciones)
    Route::get('/lotes/abiertos', [LoteController::class, 'abiertos']);
    Route::get('/lotes/{lote}/progreso', [LoteController::class, 'progreso']);
    Route::post('/lotes/{lote}/cerrar', [LoteController::class, 'cerrar']);
    Route::apiResource('lotes', LoteController::class)->only(['index', 'store', 'show']);

    // Rutas de Importaciones
    Route::get('/importaciones/health', [ImportacionController::class, 'health']);
    Route::post('/importaciones/recovery', [ImportacionController::class, 'forceRecovery']);
    Route::post('/importaciones/force-complete', [ImportacionController::class, 'forceComplete']);
    Route::get('/importaciones/{importacion}/progreso', [ImportacionController::class, 'progreso']);
    Route::post('/importaciones/{importacion}/retry', [ImportacionController::class, 'retry']);
    Route::apiResource('importaciones', ImportacionController::class)->parameters([
        'importaciones' => 'importacion',
    ]);

    // Rutas de Flujos
    Route::get('/flujos/estadisticas-costos', [FlujoController::class, 'estadisticasCostos']);
    Route::get('/flujos/opciones-creacion', [FlujoController::class, 'opcionesCreacion']);
    Route::get('/flujos/opciones-filtrado', [FlujoController::class, 'opcionesFiltrado']);
    Route::post('/flujos/debug-payload', [FlujoController::class, 'debugPayload']); // TEMPORAL
    Route::post('/flujos/crear-con-prospectos', [FlujoController::class, 'crearFlujoConProspectos']);
    Route::get('/flujos/{flujo}/progreso', [FlujoController::class, 'progreso']);
    Route::post('/flujos/{flujo}/agregar-prospectos', [FlujoController::class, 'agregarProspectos']);
    Route::apiResource('flujos', FlujoController::class);

    // Rutas de Ejecuciones de Flujos
    Route::post('/flujos/{flujo}/ejecutar', [FlujoEjecucionController::class, 'execute']);
    Route::get('/flujos/{flujo}/ejecuciones', [FlujoEjecucionController::class, 'index']);
    Route::get('/flujos/{flujo}/ejecuciones/activa', [FlujoEjecucionController::class, 'getActiveExecution']);
    Route::get('/flujos/{flujo}/ejecuciones/{ejecucion}', [FlujoEjecucionController::class, 'show']);
    Route::post('/flujos/{flujo}/ejecuciones/{ejecucion}/pausar', [FlujoEjecucionController::class, 'pause']);
    Route::post('/flujos/{flujo}/ejecuciones/{ejecucion}/reanudar', [FlujoEjecucionController::class, 'resume']);
    Route::delete('/flujos/{flujo}/ejecuciones/{ejecucion}', [FlujoEjecucionController::class, 'destroy']);

    // Rutas de Configuración
    Route::get('/configuracion', [ConfiguracionController::class, 'show']);
    Route::put('/configuracion', [ConfiguracionController::class, 'update']);

    // Rutas de Plantillas
    Route::post('/plantillas/sms', [PlantillaController::class, 'crearSMS']);
    Route::post('/plantillas/email', [PlantillaController::class, 'crearEmail']);
    Route::post('/plantillas/preview/email', [PlantillaController::class, 'generarPreviewEmail']);
    Route::post('/plantillas/validar/sms', [PlantillaController::class, 'validarSMS']);
    Route::apiResource('plantillas', PlantillaController::class);

    // Rutas de Envíos
    Route::get('/envios/estadisticas', [EnvioController::class, 'estadisticas']);
    Route::get('/envios/estadisticas/hoy', [EnvioController::class, 'estadisticasHoy']);
    Route::get('/envios/contador-por-flujo', [EnvioController::class, 'contadorPorFlujo']);
    Route::apiResource('envios', EnvioController::class)->only(['index', 'show']);

    // Rutas de Tracking de Emails (aperturas y clicks)
    Route::get('/envios/{envioId}/aperturas', [TrackingController::class, 'estadisticasEnvio']);
    Route::get('/envios/{envioId}/clicks', [TrackingController::class, 'estadisticasClicksEnvio']);
    Route::get('/flujos/{flujoId}/estadisticas-aperturas', [TrackingController::class, 'estadisticasFlujo']);
    Route::get('/flujos/{flujoId}/estadisticas-clicks', [TrackingController::class, 'estadisticasClicksFlujo']);

    // Rutas de Costos
    Route::get('/costos/precios', [CostoController::class, 'getPrecios']);
    Route::get('/costos/dashboard', [CostoController::class, 'getDashboard']);
    Route::get('/flujos/{flujo}/costo-estimado', [CostoController::class, 'getCostoEstimado']);
    Route::get('/ejecuciones/{ejecucion}/costo', [CostoController::class, 'getCostoEjecucion']);
    Route::post('/ejecuciones/{ejecucion}/recalcular-costo', [CostoController::class, 'recalcularCosto']);

    // Rutas de Testing (solo para desarrollo/staging)
    Route::prefix('testing')->group(function () {
        Route::get('/check-ip', [TestingController::class, 'checkIp']);
        Route::post('/simular-estadisticas', [TestingController::class, 'simularEstadisticas']);
        Route::post('/forzar-verificacion-condicion', [TestingController::class, 'forzarVerificacionCondicion']);
        Route::post('/evaluar-condicion', [TestingController::class, 'evaluarCondicion']);
        Route::get('/condiciones-evaluadas/{flujoEjecucionId}', [TestingController::class, 'condicionesEvaluadas']);
        Route::get('/etapas-ejecucion/{flujoEjecucionId}', [TestingController::class, 'etapasEjecucion']);
        Route::get('/jobs/{flujoEjecucionId}', [TestingController::class, 'jobsEjecucion']);
    });

    // Rutas de Monitoreo del sistema de colas
    Route::prefix('monitoreo')->group(function () {
        Route::get('/dashboard', [MonitoreoController::class, 'dashboard']);
        Route::get('/queue', [MonitoreoController::class, 'queueStatus']);
        Route::get('/circuit-breaker', [MonitoreoController::class, 'circuitBreakerStatus']);
        Route::post('/circuit-breaker/reset', [MonitoreoController::class, 'resetCircuitBreaker']);
        Route::get('/rate-limits', [MonitoreoController::class, 'rateLimitStatus']);
        Route::get('/health', [MonitoreoController::class, 'health']);
        Route::post('/queue/retry-failed', [MonitoreoController::class, 'retryFailedJobs']);
        Route::delete('/queue/failed', [MonitoreoController::class, 'clearFailedJobs']);
        
        // Alertas - testing y configuración
        Route::get('/alertas/config', [MonitoreoController::class, 'getAlertasConfig']);
        Route::post('/alertas/test', [MonitoreoController::class, 'testAlerta']);
    });

    // Rutas de Desuscripciones (estadísticas y listado)
    Route::prefix('desuscripciones')->group(function () {
        Route::get('/', [DesuscripcionController::class, 'index']);
        Route::get('/estadisticas', [DesuscripcionController::class, 'estadisticas']);
    });

    // Rutas de Métricas y Analytics
    Route::prefix('metricas')->group(function () {
        Route::get('/dashboard', [MetricasController::class, 'dashboard']);
        Route::get('/resumen', [MetricasController::class, 'resumen']);
        Route::get('/aperturas', [MetricasController::class, 'aperturas']);
        Route::get('/clicks', [MetricasController::class, 'clicks']);
        Route::get('/envios', [MetricasController::class, 'envios']);
        Route::get('/desuscripciones', [MetricasController::class, 'desuscripciones']);
        Route::get('/conversiones', [MetricasController::class, 'conversiones']);
        Route::get('/top-flujos', [MetricasController::class, 'topFlujos']);
        Route::get('/tendencias', [MetricasController::class, 'tendencias']);
        Route::post('/refresh', [MetricasController::class, 'refresh']);
    });
});
