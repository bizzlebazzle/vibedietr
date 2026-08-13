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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'openfoodfacts' => [
        'base_url' => env('OPENFOODFACTS_BASE_URL', 'https://world.openfoodfacts.org'),
        // v3.4 retains the documented flat nutrient fields consumed by the
        // existing ingredient model while moving the integration off v2.
        'api_version' => env('OPENFOODFACTS_API_VERSION', 'v3.4'),
        'user_agent' => env('OPENFOODFACTS_USER_AGENT', 'VibeDietr/development (http://localhost)'),
        'connect_timeout' => (float) env('OPENFOODFACTS_CONNECT_TIMEOUT', 2),
        'timeout' => (float) env('OPENFOODFACTS_TIMEOUT', 5),
        'attempts' => (int) env('OPENFOODFACTS_ATTEMPTS', 2),
        'backoff_ms' => array_map(
            'intval',
            explode(',', (string) env('OPENFOODFACTS_BACKOFF_MS', '100')),
        ),
        'max_retry_after' => (int) env('OPENFOODFACTS_MAX_RETRY_AFTER', 1),
    ],

];
