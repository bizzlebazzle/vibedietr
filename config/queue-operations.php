<?php

use App\Jobs\DeliverSecurityNotification;
use App\Jobs\ProcessReferenceTask;
use App\Queue\QueueName;

return [
    'enabled' => env('QUEUE_OPERATIONS_ENABLED', false),
    'supervision' => env('QUEUE_SUPERVISION', 'local'),
    'scheduler_enabled' => env('QUEUE_SCHEDULER_ENABLED', false),
    'timezone' => 'UTC',
    'retry_after_safety_margin_seconds' => 20,
    'failed_job_retention_hours' => 168,
    'queues' => [QueueName::SECURITY_NOTIFICATIONS, QueueName::DEFAULT],
    'workers' => [
        'security-notifications' => [
            'queues' => [QueueName::SECURITY_NOTIFICATIONS],
            'processes' => 1,
            'timeout' => 40,
            'sleep' => 3,
            'schedules' => [
                'observability:scheduler-heartbeat' => [
                    'frequency' => 'every-minute',
                    'overlap_lock_minutes' => 10,
                    'one_server' => true,
                ],
                'observability:monitor' => [
                    'frequency' => 'every-minute',
                    'overlap_lock_minutes' => 10,
                    'one_server' => true,
                ],
            ],
            'memory' => 256,
            'max_jobs' => 500,
            'max_time' => 3600,
            'tries' => 3,
        ],
        'default' => [
            'queues' => [QueueName::DEFAULT],
            'processes' => 1,
            'timeout' => 70,
            'sleep' => 3,
            'memory' => 256,
            'max_jobs' => 500,
            'max_time' => 3600,
            'tries' => 3,
        ],
    ],
    'jobs' => [
        DeliverSecurityNotification::class => [
            'queue' => QueueName::SECURITY_NOTIFICATIONS,
            'worker' => 'security-notifications',
            'timeout' => 30,
            'failed_payload' => 'metadata-only',
        ],
        ProcessReferenceTask::class => [
            'queue' => QueueName::DEFAULT,
            'worker' => 'default',
            'timeout' => 60,
            'failed_payload' => 'metadata-only',
        ],
    ],
];
