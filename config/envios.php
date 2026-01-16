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
    | Common provider limits:
    | - Amazon SES: 14/sec (sandbox), 50+/sec (production)
    | - SendGrid: 100/sec
    | - Mailgun: 300/min = 5/sec
    | - Generic SMTP: varies, usually 1-10/sec
    |
    */

    'rate_limits' => [

        'email' => [
            // Maximum emails per second
            'per_second' => (int) env('EMAIL_RATE_PER_SECOND', 10),

            // Maximum emails per minute (as safety net)
            'per_minute' => (int) env('EMAIL_RATE_PER_MINUTE', 500),

            // Maximum emails per hour (for daily planning)
            'per_hour' => (int) env('EMAIL_RATE_PER_HOUR', 20000),

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
    | Configure external alerting when critical events occur.
    |
    */

    'alerts' => [
        // Slack webhook URL for critical alerts
        'slack_webhook' => env('ENVIO_ALERT_SLACK_WEBHOOK', null),

        // Email address for critical alerts
        'email' => env('ENVIO_ALERT_EMAIL', null),
    ],

];
