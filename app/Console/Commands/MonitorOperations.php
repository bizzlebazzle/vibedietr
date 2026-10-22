<?php

namespace App\Console\Commands;

use App\Domain\AccountDeletion\RecoveryRetentionHealth;
use App\Models\AccountDeletion;
use App\Models\AccountExport;
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

        $overduePurges = AccountDeletion::query()->where('deadline', '<=', now()->utc()->subMinutes(5))->count();
        if ($overduePurges > 0) {
            $alerts->send('account_erasure_retention', 'critical', ['count' => $overduePurges]);
        }
        if (is_string(config('erasure.installation')) && config('erasure.installation') !== '') {
            try {
                foreach (app(RecoveryRetentionHealth::class)->counts() as $purpose => $count) {
                    if ($count > 0) {
                        $alerts->send('erasure_'.$purpose, 'critical', ['count' => $count]);
                    }
                }
            } catch (\Throwable) {
                $alerts->send('account_erasure_journal_unavailable', 'critical');
            }
        }
        $overdueExports = AccountExport::query()->where('state', '!=', 'deleted')
            ->where(fn ($query) => $query->where('expires_at', '<=', now()->utc()->subMinutes(5))->orWhereNull('user_id'))->count();
        if ($overdueExports > 0) {
            $alerts->send('account_export_retention', 'critical', ['count' => $overdueExports]);
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
