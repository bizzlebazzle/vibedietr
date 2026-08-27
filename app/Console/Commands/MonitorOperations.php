<?php

namespace App\Console\Commands;

use App\Observability\Alerts\AlertSink;
use App\Observability\Health\HealthService;
use App\Observability\Monitoring\OperationalState;
use App\Observability\Monitoring\QueueMonitor;
use App\Observability\OperationalTelemetry;
use Illuminate\Console\Command;

final class MonitorOperations extends Command
{
    protected $signature = 'observability:monitor';

    protected $description = 'Evaluate privacy-safe operational health and alert thresholds';

    public function handle(
        QueueMonitor $monitor,
        OperationalState $state,
        OperationalTelemetry $telemetry,
        HealthService $health,
        AlertSink $alerts,
    ): int {
        $readiness = $health->readiness();
        if ($readiness['status'] === 'unhealthy') {
            $alerts->send('application_readiness', 'critical');
        }

        foreach ($monitor->queues() as $queue) {
            $telemetry->event('queue_state', [
                'queue' => $queue['queue'],
                'count' => $queue['depth'],
                'duration_ms' => $queue['oldest_age_seconds'] * 1000,
                'state' => $queue['state'],
            ]);
            if ($queue['state'] !== 'healthy') {
                $alerts->send('queue_backlog', $queue['state'], [
                    'queue' => $queue['queue'],
                    'count' => $queue['depth'],
                    'value' => $queue['oldest_age_seconds'],
                ]);
            }

            $worker = $state->workerHealth($queue['queue']);
            if ($worker->state === 'unhealthy') {
                $alerts->send('queue_worker_unavailable', 'critical', ['queue' => $queue['queue']]);
            }
        }

        foreach ([$state->schedulerHealth(), $state->pruningHealth()] as $health) {
            if ($health->state === 'unhealthy') {
                $alerts->send($health->name, 'critical');
            }
        }

        $failed = $monitor->failedJobs();
        $telemetry->event('failed_job_state', [
            'count' => $failed['count'],
            'duration_ms' => $failed['oldest_age_seconds'] * 1000,
            'state' => 'observed',
        ]);

        foreach ([
            'queue.final_failure' => 'final_failure_spike',
            'application.exception' => 'exception_rate_spike',
            'provider.failure' => 'provider_outage',
            'provider.slow' => 'provider_latency',
        ] as $metric => $category) {
            $count = $telemetry->counterTotal($metric);
            $stateName = $count >= (int) config('observability.failure_critical_count', 20)
                ? 'critical'
                : ($count >= (int) config('observability.failure_warning_count', 5) ? 'warning' : 'healthy');
            if ($stateName !== 'healthy') {
                $alerts->send($category, $stateName, [
                    'count' => $count,
                    'window_seconds' => (int) config('observability.failure_window_seconds', 300),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
