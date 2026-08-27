<?php

namespace App\Observability\Monitoring;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Date;

final class QueueMonitor
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    /** @return list<array{queue: string, depth: int, oldest_age_seconds: int, state: string}> */
    public function queues(): array
    {
        $connection = $this->connections->connection(config('queue.connections.database.connection'));
        $table = (string) config('queue.connections.database.table', 'jobs');
        $now = Date::now()->timestamp;

        return array_map(function (string $queue) use ($connection, $table, $now): array {
            $query = $connection->table($table)->where('queue', $queue)->whereNull('reserved_at');
            $depth = (clone $query)->count();
            $oldest = (clone $query)->min('created_at');
            $age = is_numeric($oldest) ? max(0, $now - (int) $oldest) : 0;
            $criticalDepth = (int) config('observability.queue_depth_critical', 100);
            $warningDepth = (int) config('observability.queue_depth_warning', 25);
            $criticalAge = (int) config('observability.oldest_job_critical_seconds', 900);
            $warningAge = (int) config('observability.oldest_job_warning_seconds', 300);
            $state = $depth >= $criticalDepth || $age >= $criticalAge
                ? 'critical'
                : ($depth >= $warningDepth || $age >= $warningAge ? 'warning' : 'healthy');

            return [
                'queue' => $queue,
                'depth' => $depth,
                'oldest_age_seconds' => $age,
                'state' => $state,
            ];
        }, array_values((array) config('queue-operations.queues', [])));
    }

    /** @return array{count: int, oldest_age_seconds: int} */
    public function failedJobs(): array
    {
        $connection = $this->connections->connection(config('queue.failed.database'));
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $count = $connection->table($table)->count();
        $oldest = $connection->table($table)->min('failed_at');

        return [
            'count' => $count,
            'oldest_age_seconds' => is_string($oldest) ? (int) max(0, Date::parse($oldest)->diffInSeconds(Date::now())) : 0,
        ];
    }
}
