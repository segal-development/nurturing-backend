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
    | Tope POR FLUJO de prospectos que CatchUpProspectosJob mete al pipeline por
    | corrida (cada ejecución perpetua drena a lo sumo N por corrida, independiente
    | de las demás — así un flujo con backlog gigante no starve-a a los chicos como
    | onboarding). Evita que al marcar un flujo perpetuo con backlog grande se dispare
    | TODO de una (flood). El backlog drena a esta tasa (CatchUp corre cada 5 min) y el
    | rate-limiter de envíos es el tope GLOBAL de entrega real. Subir para acelerar,
    | bajar para frenar; poner bajo para arrancar suave. <= 0 cae al default 50.
    |
    */

    'catchup' => [
        'max_prospectos_per_run' => (int) env('NURTURING_CATCHUP_MAX_PER_RUN', 50),
    ],
];
