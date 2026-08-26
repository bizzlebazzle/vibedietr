<?php

namespace App\Queue;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Date;

final class FailedJobPruner
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    /** @return array{personal: int, expired: int} */
    public function prune(): array
    {
        $connection = $this->connection();
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $cutoff = Date::now()->subHours((int) config('queue-operations.failed_job_retention_hours'));
        $counts = ['personal' => 0, 'expired' => 0];

        $connection->table($table)->orderBy('id')->chunkById(200, function ($records) use ($connection, $table, $cutoff, &$counts): void {
            foreach ($records as $record) {
                $classification = $this->classification((string) $record->payload);
                $reason = $classification === 'personal'
                    ? 'personal'
                    : (Date::parse((string) $record->failed_at)->lte($cutoff) ? 'expired' : null);

                if ($reason !== null) {
                    $counts[$reason] += $connection->table($table)
                        ->where('id', $record->id)
                        ->delete();
                }
            }
        });

        return $counts;
    }

    public function classification(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $jobClass = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;
        $inventory = config('queue-operations.jobs', []);

        if (! is_string($jobClass) || ! is_array($inventory) || ! isset($inventory[$jobClass])) {
            return 'personal';
        }

        $classification = $inventory[$jobClass]['failed_payload'] ?? null;

        return $classification === 'metadata-only' ? 'metadata-only' : 'personal';
    }

    private function connection(): ConnectionInterface
    {
        $name = config('queue.failed.database');

        return $this->connections->connection(is_string($name) ? $name : null);
    }
}
