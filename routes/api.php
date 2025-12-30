<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\CostoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnvioController;
use App\Http\Controllers\FlujoController;
use App\Http\Controllers\FlujoEjecucionController;
use App\Http\Controllers\ImportacionController;
use App\Http\Controllers\PlantillaController;
use App\Http\Controllers\ProspectoController;
use App\Http\Controllers\TestingController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

// Rutas públicas de autenticación
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas con Sanctum (sesión o token)
Route::middleware('auth:sanctum')->group(function () {
    // Autenticación
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Rutas de Prospectos
    Route::get('/prospectos/estadisticas', [ProspectoController::class, 'estadisticas']);
    Route::get('/prospectos/opciones-filtrado', [ProspectoController::class, 'opcionesFiltrado']);
    Route::apiResource('prospectos', ProspectoController::class);

    // Rutas de Importaciones
    Route::apiResource('importaciones', ImportacionController::class)->parameters([
        'importaciones' => 'importacion',
    ]);

    // Rutas de Flujos
    Route::get('/flujos/estadisticas-costos', [FlujoController::class, 'estadisticasCostos']);
    Route::get('/flujos/opciones-creacion', [FlujoController::class, 'opcionesCreacion']);
    Route::get('/flujos/opciones-filtrado', [FlujoController::class, 'opcionesFiltrado']);
    Route::post('/flujos/debug-payload', [FlujoController::class, 'debugPayload']); // TEMPORAL
    Route::post('/flujos/crear-con-prospectos', [FlujoController::class, 'crearFlujoConProspectos']);
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
        Route::post('/simular-estadisticas', [TestingController::class, 'simularEstadisticas']);
        Route::post('/forzar-verificacion-condicion', [TestingController::class, 'forzarVerificacionCondicion']);
        Route::post('/evaluar-condicion', [TestingController::class, 'evaluarCondicion']);
        Route::get('/condiciones-evaluadas/{flujoEjecucionId}', [TestingController::class, 'condicionesEvaluadas']);
        Route::get('/etapas-ejecucion/{flujoEjecucionId}', [TestingController::class, 'etapasEjecucion']);
        Route::get('/jobs/{flujoEjecucionId}', [TestingController::class, 'jobsEjecucion']);
    });
});
