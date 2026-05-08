<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'athenacampaign' => [
        'base_url' => env('ATHENACAMPAIGN_BASE_URL', 'https://apimail.athenacampaign.com'),
        'api_key' => env('ATHENACAMPAIGN_API_KEY', ''),
    ],

    'sms' => [
        'api_token' => env('SMS_API_TOKEN'),
    ],

    'certificada' => [
        'api_key' => env('CERTIFICADA_API_KEY'),
        'base_url' => env('CERTIFICADA_BASE_URL', 'https://sistema.certificada.cl/api'),
        'sender_email' => env('CERTIFICADA_SENDER_EMAIL', 'info@informescomercialesb2b.cl'),
        'sender_name' => env('CERTIFICADA_SENDER_NAME', 'Informes Comerciales'),
        'enabled' => env('CERTIFICADA_ENABLED', true),
        'timeout' => env('CERTIFICADA_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which email provider to use as primary with automatic fallback.
    | - 'athena': Uses SMTP (Athena) as primary, Certificada as fallback
    | - 'certificada': Uses Certificada as primary, SMTP (Athena) as fallback
    |
    */
    'email' => [
        'primary_provider' => env('EMAIL_PRIMARY_PROVIDER', 'athena'),
    ],

];
