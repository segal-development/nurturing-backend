<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Throttle Configuration
    |--------------------------------------------------------------------------
    |
    | Controls rate limiting for batch email processing during cron execution.
    | This prevents overwhelming the email service when many etapas are ready.
    |
    */

    'throttle' => [
        'enabled' => env('NURTURING_THROTTLE_ENABLED', true),
        'max_etapas_per_minute' => env('NURTURING_THROTTLE_MAX', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | CatchUp Rate Governor
    |--------------------------------------------------------------------------
    |
    | Tope GLOBAL de prospectos que CatchUpProspectosJob mete al pipeline por
    | corrida (compartido entre todas las ejecuciones perpetuas). Evita que al
    | marcar un flujo perpetuo con backlog grande se dispare TODO de una (flood).
    | El backlog drena a esta tasa por corrida (CatchUp corre cada 5 min) y el
    | rate-limiter de envíos pacea la entrega real. Subir para acelerar, bajar
    | para frenar; poner bajo para arrancar suave. <= 0 cae al default 50.
    |
    */

    'catchup' => [
        'max_prospectos_per_run' => (int) env('NURTURING_CATCHUP_MAX_PER_RUN', 50),
    ],
];
