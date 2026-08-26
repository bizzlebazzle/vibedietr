<?php

use Symfony\Component\HttpFoundation\Request;

$csv = static fn (?string $value): array => array_values(array_filter(
    array_map('trim', explode(',', (string) $value)),
    static fn (string $item): bool => $item !== '',
));

$trustedProxies = env('TRUSTED_PROXIES');

return [
    'trusted_hosts' => $csv(env('TRUSTED_HOSTS')),
    'trusted_proxies_setting' => $trustedProxies,
    'trusted_proxies' => $trustedProxies === 'none' ? [] : $csv($trustedProxies),
    'trusted_proxy_headers_setting' => env('TRUSTED_PROXY_HEADERS'),
    'trusted_proxy_headers' => Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
    'storage' => ['durable_disk' => env('PRODUCTION_DURABLE_DISK')],
    'imports' => [
        'enabled' => env('RECIPE_IMPORTS_ENABLED', false),
        'formats' => $csv(env('RECIPE_IMPORT_FORMATS', 'txt,md,html')),
        'max_upload_bytes' => (int) env('RECIPE_IMPORT_MAX_UPLOAD_BYTES', 2097152),
        'max_url_length' => (int) env('RECIPE_IMPORT_MAX_URL_LENGTH', 2048),
        'max_redirects' => (int) env('RECIPE_IMPORT_MAX_REDIRECTS', 5),
        'connect_timeout_seconds' => (int) env('RECIPE_IMPORT_CONNECT_TIMEOUT', 3),
        'timeout_seconds' => (int) env('RECIPE_IMPORT_TIMEOUT', 15),
        'queue' => env('RECIPE_IMPORT_QUEUE', 'default'),
        'attempts' => (int) env('RECIPE_IMPORT_ATTEMPTS', 3),
        'backoff_seconds' => $csv(env('RECIPE_IMPORT_BACKOFF_SECONDS', '10,60')),
        'concurrency' => (int) env('RECIPE_IMPORT_CONCURRENCY', 2),
        'per_user_per_hour' => (int) env('RECIPE_IMPORT_PER_USER_PER_HOUR', 10),
        'transient_disk' => env('RECIPE_IMPORT_TRANSIENT_DISK'),
        'cleanup_hours' => (int) env('RECIPE_IMPORT_CLEANUP_HOURS', 24),
        'parser_version' => env('RECIPE_IMPORT_PARSER_VERSION'),
    ],
    'ocr' => [
        'enabled' => env('OCR_ENABLED', false),
        'tesseract_version' => env('OCR_TESSERACT_VERSION'),
        'language' => env('OCR_LANGUAGE'),
        'heic_decoder_version' => env('OCR_HEIC_DECODER_VERSION'),
        'preprocessing_version' => env('OCR_PREPROCESSING_VERSION'),
        'max_upload_bytes' => (int) env('OCR_MAX_UPLOAD_BYTES', 20971520),
        'max_megapixels' => (int) env('OCR_MAX_MEGAPIXELS', 50),
        'max_images' => (int) env('OCR_MAX_IMAGES', 1),
        'queue' => env('OCR_QUEUE', 'default'),
        'attempts' => (int) env('OCR_ATTEMPTS', 3),
        'timeout_seconds' => (int) env('OCR_TIMEOUT', 60),
        'concurrency' => (int) env('OCR_CONCURRENCY', 2),
        'transient_disk' => env('OCR_TRANSIENT_DISK'),
        'cleanup_hours' => (int) env('OCR_CLEANUP_HOURS', 24),
        'google' => [
            'enabled' => env('OCR_GOOGLE_FALLBACK_ENABLED', false),
            'project_id' => env('OCR_GOOGLE_PROJECT_ID'),
            'location' => env('OCR_GOOGLE_LOCATION'),
            'endpoint' => env('OCR_GOOGLE_ENDPOINT'),
            'processor_id' => env('OCR_GOOGLE_PROCESSOR_ID'),
            'model_version' => env('OCR_GOOGLE_MODEL_VERSION'),
            'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
            'timeout_seconds' => (int) env('OCR_GOOGLE_TIMEOUT', 30),
            'monthly_page_quota' => (int) env('OCR_GOOGLE_MONTHLY_PAGE_QUOTA', 0),
            'monthly_budget_minor' => (int) env('OCR_GOOGLE_MONTHLY_BUDGET_MINOR', 0),
        ],
    ],
];
