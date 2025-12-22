<?php

/**
 * Configuración del sistema de batching para envíos masivos.
 *
 * Este sistema divide envíos grandes en lotes más pequeños para evitar
 * saturar el servidor de correo y mejorar la entregabilidad.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Umbral de Batching
    |--------------------------------------------------------------------------
    |
    | Cantidad mínima de envíos a partir de la cual se activa el batching.
    | Si un nodo tiene más de este número de envíos, se dividirá en lotes.
    | Si tiene menos o igual, se enviará todo de una vez.
    |
    */
    'threshold' => (int) env('BATCHING_THRESHOLD', 20000),

    /*
    |--------------------------------------------------------------------------
    | Número de Lotes
    |--------------------------------------------------------------------------
    |
    | Cantidad de lotes en los que se dividirán los envíos cuando se supere
    | el umbral. Por ejemplo, 24 lotes para 24,000 envíos = 1,000 por lote.
    |
    */
    'batch_count' => (int) env('BATCHING_BATCH_COUNT', 24),

    /*
    |--------------------------------------------------------------------------
    | Delay entre Lotes (minutos)
    |--------------------------------------------------------------------------
    |
    | Tiempo de espera entre el procesamiento de cada lote.
    | Esto permite que el servidor de correo procese los envíos sin saturarse.
    |
    | Ejemplo: 10 minutos × 24 lotes = 4 horas para procesar todos los envíos.
    |
    */
    'delay_between_batches_minutes' => (int) env('BATCHING_DELAY_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Cola de Procesamiento
    |--------------------------------------------------------------------------
    |
    | Nombre de la cola donde se procesarán los jobs de lotes.
    | Se recomienda usar una cola dedicada para envíos masivos.
    |
    */
    'queue' => env('BATCHING_QUEUE', 'envios-batch'),

    /*
    |--------------------------------------------------------------------------
    | Tamaño Máximo de Lote
    |--------------------------------------------------------------------------
    |
    | Tamaño máximo permitido para un lote individual.
    | Si un lote calculado excede este tamaño, se dividirá aún más.
    |
    */
    'max_batch_size' => (int) env('BATCHING_MAX_BATCH_SIZE', 5000),

    /*
    |--------------------------------------------------------------------------
    | Reintentos por Lote
    |--------------------------------------------------------------------------
    |
    | Número de reintentos si un lote falla.
    |
    */
    'batch_retries' => (int) env('BATCHING_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Backoff entre Reintentos (segundos)
    |--------------------------------------------------------------------------
    |
    | Tiempo de espera entre reintentos de un lote fallido.
    |
    */
    'batch_backoff' => [60, 300, 900], // 1min, 5min, 15min
];
