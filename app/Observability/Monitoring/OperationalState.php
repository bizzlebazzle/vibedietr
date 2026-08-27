<?php

namespace App\Observability\Monitoring;

use App\Observability\Health\HealthCheckResult;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

final class OperationalState
{
    public function __construct(private readonly Repository $cache) {}

    public function recordWorker(string $queue): void
    {
        $this->cache->forever($this->workerKey($queue), now()->utc()->toIso8601String());
    }

    public function recordScheduler(): void
    {
        $this->cache->forever('observability:scheduler:last_seen', now()->utc()->toIso8601String());
    }

    public function recordPrune(): void
    {
        $this->cache->forever('observability:failed_jobs:last_pruned', now()->utc()->toIso8601String());
    }

    public function recordReplayFailure(string $jobIdentifier): int
    {
        $key = 'observability:replay_failure:'.hash('sha256', $jobIdentifier);
        $this->cache->add($key, 0, now()->addHours(24));

        return (int) $this->cache->increment($key);
    }

    public function workerHealth(string $queue): HealthCheckResult
    {
        return $this->freshness(
            'worker:'.$queue,
            $this->cache->get($this->workerKey($queue)),
            (int) config('observability.worker_stale_seconds', 180),
        );
    }

    public function schedulerHealth(): HealthCheckResult
    {
        return $this->freshness(
            'scheduler',
            $this->cache->get('observability:scheduler:last_seen'),
            (int) config('observability.scheduler_stale_seconds', 180),
        );
    }

    public function pruningHealth(): HealthCheckResult
    {
        return $this->freshness(
            'failed_job_pruning',
            $this->cache->get('observability:failed_jobs:last_pruned'),
            (int) config('observability.prune_stale_seconds', 93600),
        );
    }

    private function freshness(string $name, mixed $timestamp, int $staleSeconds): HealthCheckResult
    {
        if (! is_string($timestamp) || $timestamp === '') {
            return HealthCheckResult::unhealthy($name, 'heartbeat is missing');
        }

        try {
            return Carbon::parse($timestamp)->lt(now()->subSeconds($staleSeconds))
                ? HealthCheckResult::unhealthy($name, 'heartbeat is stale')
                : HealthCheckResult::healthy($name);
        } catch (\Throwable) {
            return HealthCheckResult::unhealthy($name, 'heartbeat is invalid');
        }
    }

    private function workerKey(string $queue): string
    {
        return 'observability:worker:'.hash('sha256', $queue).':last_seen';
    }
}
