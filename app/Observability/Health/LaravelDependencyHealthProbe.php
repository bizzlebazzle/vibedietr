<?php

namespace App\Observability\Health;

use App\Observability\Monitoring\OperationalState;
use App\Security\Notifications\ProductionSecurityReadiness;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Filesystem\FilesystemManager;
use Throwable;

final class LaravelDependencyHealthProbe implements DependencyHealthProbe
{
    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly Repository $cache,
        private readonly FilesystemManager $storage,
        private readonly OperationalState $state,
        private readonly ProductionSecurityReadiness $securityReadiness,
    ) {}

    public function check(): array
    {
        $checks = [$this->database(), $this->cache(), $this->queueBackend(), $this->storage()];

        if ((bool) config('queue-operations.enabled')) {
            $checks[] = $this->state->schedulerHealth();
            foreach ((array) config('queue-operations.queues', []) as $queue) {
                $checks[] = $this->state->workerHealth((string) $queue);
            }
            $checks[] = $this->state->pruningHealth();
        }

        if (app()->environment('production')) {
            try {
                $checks[] = $this->securityReadiness->failures() === []
                    ? HealthCheckResult::healthy('security_notifications')
                    : HealthCheckResult::unhealthy('security_notifications', 'readiness requirements not met');
            } catch (Throwable) {
                $checks[] = HealthCheckResult::unhealthy('security_notifications', 'readiness check failed');
            }
        }

        $checks[] = trim((string) config('services.openfoodfacts.base_url')) === ''
            ? HealthCheckResult::degraded('openfoodfacts', 'optional provider is not configured')
            : HealthCheckResult::healthy('openfoodfacts');

        return $checks;
    }

    private function database(): HealthCheckResult
    {
        try {
            $this->database->connection()->select('select 1');

            return HealthCheckResult::healthy('database');
        } catch (Throwable) {
            return HealthCheckResult::unhealthy('database', 'connection or safe read failed');
        }
    }

    private function cache(): HealthCheckResult
    {
        $key = 'observability:health:'.bin2hex(random_bytes(8));
        try {
            $this->cache->put($key, 'ok', 10);
            $healthy = $this->cache->get($key) === 'ok';
            $this->cache->forget($key);

            return $healthy ? HealthCheckResult::healthy('cache') : HealthCheckResult::unhealthy('cache', 'round trip failed');
        } catch (Throwable) {
            return HealthCheckResult::unhealthy('cache', 'round trip failed');
        }
    }

    private function queueBackend(): HealthCheckResult
    {
        try {
            $connection = $this->database->connection(config('queue.connections.database.connection'));
            $connection->table((string) config('queue.connections.database.table', 'jobs'))->limit(1)->count();

            return HealthCheckResult::healthy('queue_backend');
        } catch (Throwable) {
            return HealthCheckResult::unhealthy('queue_backend', 'backend read failed');
        }
    }

    private function storage(): HealthCheckResult
    {
        $disk = app()->environment('production')
            ? (string) config('production.storage.durable_disk')
            : (string) config('filesystems.default');
        try {
            $this->storage->disk($disk)->exists('.observability-health-probe');

            return HealthCheckResult::healthy('storage');
        } catch (Throwable) {
            return HealthCheckResult::unhealthy('storage', 'safe reachability check failed');
        }
    }
}
