<?php

return [
    'headers' => [
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31_536_000),
        'csp' => ['development_server' => env('VITE_DEV_SERVER_URL', 'http://localhost:5173')],
    ],
    'throttles' => [
        'login' => ['attempts' => 5, 'decay_seconds' => 60],
        'password_reset' => ['attempts' => 5, 'decay_seconds' => 60],
        'password_reset_ip' => ['attempts' => 20, 'decay_seconds' => 3600],
        'password_confirmation' => ['attempts' => 10, 'decay_seconds' => 60],
        'security' => ['attempts' => 10, 'decay_seconds' => 60],
        'public_search' => ['attempts' => 60, 'decay_seconds' => 60],
        'barcode_user' => ['attempts' => 30, 'decay_seconds' => 60],
        'barcode_global' => [
            'attempts' => (int) env('SECURITY_BARCODE_GLOBAL_PER_MINUTE', 300),
            'decay_seconds' => 60,
        ],
        'sharing' => ['attempts' => 30, 'decay_seconds' => 60],
        'import_user' => [
            'attempts' => (int) env('RECIPE_IMPORT_PER_USER_PER_HOUR', 10),
            'decay_seconds' => 3600,
        ],
        'import_global' => [
            'attempts' => (int) env('RECIPE_IMPORT_GLOBAL_PER_HOUR', 500),
            'decay_seconds' => 3600,
        ],
    ],
    'requests' => ['max_bytes' => (int) env('SECURITY_MAX_REQUEST_BYTES', 27_262_976)],
    'uploads' => [
        'max_bytes' => (int) env('SECURITY_MAX_UPLOAD_BYTES', 26_214_400),
        'transient_disk' => env('SECURITY_TRANSIENT_DISK', 'transient'),
        'prefix' => 'inputs',
    ],
    'parsing' => [
        'max_bytes' => 2_097_152,
        'max_chars' => 2_000_000,
        'max_items' => 10_000,
        'max_depth' => 32,
        'max_milliseconds' => 5_000,
    ],
    'sensitive_keys' => [
        'password', 'password_confirmation', 'authorization', 'cookie', 'cookies',
        'session', 'session_id', 'token', 'access_token', 'refresh_token', 'api_key',
        'secret', 'otp', 'totp', 'recovery_code', 'recovery_codes', 'source_text',
        'import_source', 'import_source_bytes', 'ocr_text', 'extraction_text',
        'filename', 'original_filename', 'storage_path', 'local_path', 'file_path',
        'provider_payload', 'provider_request', 'provider_response', 'request_body',
    ],
];
