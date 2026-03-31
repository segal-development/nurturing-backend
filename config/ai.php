<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default AI provider that will be used for
    | AI-powered features like the Email Template Agent. Anthropic is the
    | primary provider with OpenAI as failover.
    |
    */

    'default' => env('AI_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | AI Providers Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the AI providers used by your application.
    | We use Anthropic (Claude) as the primary provider for better
    | structured output, with OpenAI (GPT-4) as automatic failover.
    |
    */

    'providers' => [

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
            'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 4096),
            'timeout' => env('ANTHROPIC_TIMEOUT', 30),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 4096),
            'timeout' => env('OPENAI_TIMEOUT', 30),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failover Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic failover behavior when the primary provider fails.
    | Failover activates on timeouts, 5xx errors, or rate limits.
    |
    */

    'failover' => [
        'enabled' => env('AI_FAILOVER_ENABLED', true),
        'timeout' => env('AI_FAILOVER_TIMEOUT', 5), // seconds before trying fallback
        'fallback_provider' => 'openai',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting for AI chat endpoints to prevent abuse.
    |
    */

    'rate_limit' => [
        'requests_per_minute' => env('AI_RATE_LIMIT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation Settings
    |--------------------------------------------------------------------------
    |
    | Settings for AI conversation management.
    |
    */

    'conversation' => [
        'max_tokens' => env('AI_CONVERSATION_MAX_TOKENS', 8000),
        'prune_strategy' => 'oldest_first', // Remove oldest messages when limit reached
    ],

];
