<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limits for email and SMS sending to avoid overwhelming
    | external providers and ensure reliable delivery.
    |
    | Athena Campaign limits (confirmado):
    | - 10,000 emails/hora máximo
    | - Configuramos al 80% (8,000/hora) para tener margen de seguridad
    |
    | Para 350k emails = ~44 horas de envío continuo
    |
    */

    'rate_limits' => [

        'email' => [
            // Maximum emails per second (2/sec = 7,200/hora, dentro del límite)
            'per_second' => (int) env('EMAIL_RATE_PER_SECOND', 2),

            // Maximum emails per minute (150/min = 9,000/hora con margen)
            'per_minute' => (int) env('EMAIL_RATE_PER_MINUTE', 150),

            // Maximum emails per hour (80% del límite de Athena = 8,000)
            'per_hour' => (int) env('EMAIL_RATE_PER_HOUR', 8000),

            // Delay in seconds when rate limit is hit (exponential backoff base)
            'backoff_seconds' => (int) env('EMAIL_RATE_BACKOFF', 5),

            // Maximum retries when rate limited
            'max_retries' => (int) env('EMAIL_RATE_MAX_RETRIES', 3),
        ],

        'sms' => [
            // Maximum SMS per second
            'per_second' => (int) env('SMS_RATE_PER_SECOND', 5),

            // Maximum SMS per minute
            'per_minute' => (int) env('SMS_RATE_PER_MINUTE', 200),

            // Maximum SMS per hour
            'per_hour' => (int) env('SMS_RATE_PER_HOUR', 5000),

            // Delay in seconds when rate limit is hit
            'backoff_seconds' => (int) env('SMS_RATE_BACKOFF', 10),

            // Maximum retries when rate limited
            'max_retries' => (int) env('SMS_RATE_MAX_RETRIES', 3),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Circuit breaker prevents cascading failures when the email/SMS provider
    | is having issues. After X failures, it "opens" and stops trying for Y
    | seconds before attempting again.
    |
    */

    'circuit_breaker' => [
        // Number of failures before circuit opens
        'failure_threshold' => (int) env('ENVIO_CIRCUIT_FAILURE_THRESHOLD', 10),

        // Seconds to keep circuit open before trying again
        'recovery_time' => (int) env('ENVIO_CIRCUIT_RECOVERY_TIME', 60),

        // Time window to count failures (in seconds)
        'failure_window' => (int) env('ENVIO_CIRCUIT_FAILURE_WINDOW', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for how jobs are processed in the queue.
    |
    */

    'queue' => [
        // Default queue name for email jobs
        'email_queue' => env('EMAIL_QUEUE', 'envios'),

        // Default queue name for SMS jobs
        'sms_queue' => env('SMS_QUEUE', 'envios'),

        // Job timeout in seconds
        'timeout' => (int) env('ENVIO_JOB_TIMEOUT', 60),

        // Number of retry attempts
        'tries' => (int) env('ENVIO_JOB_TRIES', 3),

        // Backoff between retries (in seconds)
        'backoff' => [30, 60, 120],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging and monitoring for email/SMS sending.
    |
    */

    'monitoring' => [
        // Log rate limit hits
        'log_rate_limits' => env('ENVIO_LOG_RATE_LIMITS', true),

        // Log circuit breaker state changes
        'log_circuit_breaker' => env('ENVIO_LOG_CIRCUIT_BREAKER', true),

        // Log successful sends (can be verbose)
        'log_success' => env('ENVIO_LOG_SUCCESS', false),

        // Metrics channel (for external monitoring)
        'metrics_channel' => env('ENVIO_METRICS_CHANNEL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts Configuration
    |--------------------------------------------------------------------------
    |
    | Sistema de alertas por niveles:
    | - CRÍTICO (SMS): Sistema caído, circuit breaker abierto
    | - WARNING (Email): Tasa de error alta, cola saturada
    | - INFO (Email): Resumen diario, estadísticas
    |
    */

    'alerts' => [
        // Emails para alertas (separados por coma)
        'emails' => env('ALERT_EMAILS', 'csalinas@segal.cl,mtoro@segal.cl'),

        // Teléfonos para SMS críticos (separados por coma, con código país)
        'sms_numbers' => env('ALERT_SMS_NUMBERS', '+56958531798'),

        // Habilitar/deshabilitar por nivel
        'enabled' => [
            'critical' => env('ALERT_CRITICAL_ENABLED', true),
            'warning' => env('ALERT_WARNING_ENABLED', true),
            'info' => env('ALERT_INFO_ENABLED', true),
        ],

        // Hora del resumen diario (formato 24h)
        'daily_summary_hour' => (int) env('ALERT_DAILY_SUMMARY_HOUR', 8),

        // Umbral de tasa de error para warning (porcentaje)
        'error_rate_threshold' => (int) env('ALERT_ERROR_RATE_THRESHOLD', 5),

        // Umbral de cola para warning (cantidad de jobs pendientes)
        'queue_size_threshold' => (int) env('ALERT_QUEUE_SIZE_THRESHOLD', 1000),

        // Cooldown entre alertas del mismo tipo (minutos)
        'cooldown_minutes' => (int) env('ALERT_COOLDOWN_MINUTES', 15),
    ],

];
