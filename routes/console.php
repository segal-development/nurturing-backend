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
    
    $service = new ImportacionRecoveryService();
    $result = $service->recoverStuckImportations();
    
    if ($result['recovered'] > 0) {
        Log::warning('Scheduler: Recuperadas ' . $result['recovered'] . ' importaciones stuck', [
            'importacion_ids' => $result['importaciones'],
        ]);
    }
    
    return $result;
})->everyMinute()
  ->name('importaciones:auto-recovery')
  ->withoutOverlapping()
  ->runInBackground();

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
            ->where('payload', 'like', '%"importacionId";i:' . $importacion->id . ';%')
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
  ->withoutOverlapping()
  ->runInBackground();

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
  })
  ->runInBackground();
