<?php

use App\Http\Controllers\DesuscripcionController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/sanctum/csrf-cookie', function () {
    return response()->json();
});

// Rutas públicas para tracking de emails
// Estas rutas NO deben requerir autenticación porque son llamadas por el cliente de email/navegador del usuario
Route::get('/track/open/{token}', [TrackingController::class, 'open'])
    ->name('tracking.open');

Route::get('/track/click/{token}', [TrackingController::class, 'click'])
    ->name('tracking.click');

// Rutas públicas para desuscripción
// Estas rutas NO requieren autenticación - el usuario accede desde el link en el email
Route::get('/desuscribir/{token}', [DesuscripcionController::class, 'mostrarFormulario'])
    ->name('desuscripcion.formulario');

Route::post('/desuscribir/{token}', [DesuscripcionController::class, 'procesar'])
    ->name('desuscripcion.procesar');
