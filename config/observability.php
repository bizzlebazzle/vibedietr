<?php

return [
    'adapter' => env('OBSERVABILITY_ADAPTER', 'local'),
    'release' => env('OBSERVABILITY_RELEASE', 'development'),
    'alert_recipient_role' => env('OBSERVABILITY_ALERT_RECIPIENT_ROLE', 'primary_administrator'),
    'worker_stale_seconds' => (int) env('OBSERVABILITY_WORKER_STALE_SECONDS', 180),
    'scheduler_stale_seconds' => (int) env('OBSERVABILITY_SCHEDULER_STALE_SECONDS', 180),
    'prune_stale_seconds' => (int) env('OBSERVABILITY_PRUNE_STALE_SECONDS', 93600),
    'queue_depth_warning' => (int) env('OBSERVABILITY_QUEUE_DEPTH_WARNING', 25),
    'queue_depth_critical' => (int) env('OBSERVABILITY_QUEUE_DEPTH_CRITICAL', 100),
    'oldest_job_warning_seconds' => (int) env('OBSERVABILITY_OLDEST_JOB_WARNING_SECONDS', 300),
    'oldest_job_critical_seconds' => (int) env('OBSERVABILITY_OLDEST_JOB_CRITICAL_SECONDS', 900),
    'provider_slow_warning_ms' => (int) env('OBSERVABILITY_PROVIDER_SLOW_WARNING_MS', 3000),
    'failure_window_seconds' => (int) env('OBSERVABILITY_FAILURE_WINDOW_SECONDS', 300),
    'failure_warning_count' => (int) env('OBSERVABILITY_FAILURE_WARNING_COUNT', 5),
    'failure_critical_count' => (int) env('OBSERVABILITY_FAILURE_CRITICAL_COUNT', 20),
];
