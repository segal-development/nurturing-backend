<?php

use Illuminate\Support\Facades\Artisan;
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
|
| NOTA: El recovery automático y otras tareas "inteligentes" fueron DESACTIVADAS
| porque estaban marcando como fallidas importaciones que todavía no empezaban.
|
| El queue worker persistente (Cloud Run Service) es suficiente para procesar
| todos los jobs secuencialmente sin intervención.
|
*/

// ============================================================================
// ACTUALIZACIÓN DE ESTADO DE LOTES
// Recalcula totales de lotes con importaciones activas
// Esta es la ÚNICA tarea que se mantiene porque solo recalcula números, 
// no modifica estados de importaciones
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
