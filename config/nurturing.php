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
];
