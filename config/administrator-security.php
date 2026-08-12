<?php

return [
    'totp' => [
        'digits' => 6,
        'period' => 30,
        'window' => 1,
        'algorithm' => 'sha1',
        'secret_length' => 32,
        'enrollment_ttl_seconds' => 1800,
        'fresh_proof_ttl_seconds' => 120,
        'recovery_session_ttl_seconds' => 900,
        'assisted_recovery_ttl_seconds' => 900,
    ],
    'primary_authentication_ttl_seconds' => 300,
    'verification' => [
        'rolling_window_seconds' => 600,
        'maximum_failures' => 5,
        'delay_seconds' => [1, 2, 4, 8, 16],
        'lock_after_consecutive_failures' => 10,
        'lock_seconds' => 1800,
        'source_fingerprint_key' => env('ADMIN_SECURITY_FINGERPRINT_KEY'),
    ],
    'notifications' => [
        'mailer' => env('ADMIN_SECURITY_MAILER', env('MAIL_MAILER', 'log')),
        'queue' => env('ADMIN_SECURITY_QUEUE', 'security-notifications'),
        'provider' => env('ADMIN_SECURITY_PROVIDER'),
        'sender_verified' => env('ADMIN_SECURITY_SENDER_VERIFIED', false),
        'destination_challenge_ttl_seconds' => 3600,
        'provider_health_ttl_seconds' => 86400,
        'capacity_health_ttl_seconds' => 86400,
        'monitor_health_ttl_seconds' => 300,
        'application_instance' => env('APP_INSTANCE', 'default'),
        'timeout_seconds' => 30,
    ],
];
