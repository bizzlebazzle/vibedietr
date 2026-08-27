<?php

namespace App\Observability\Health;

final class HealthService
{
    public function __construct(private readonly DependencyHealthProbe $probe) {}

    /** @return array{status: string} */
    public function liveness(): array
    {
        return ['status' => 'healthy'];
    }

    /** @return array{status: string, checks: list<array{name: string, state: string, reason: string}>} */
    public function readiness(): array
    {
        $checks = $this->probe->check();
        $status = collect($checks)->contains(fn (HealthCheckResult $check): bool => $check->state === 'unhealthy')
            ? 'unhealthy'
            : (collect($checks)->contains(fn (HealthCheckResult $check): bool => $check->state === 'degraded') ? 'degraded' : 'healthy');

        return [
            'status' => $status,
            'checks' => array_map(fn (HealthCheckResult $check): array => [
                'name' => $check->name,
                'state' => $check->state,
                'reason' => $check->reason,
            ], $checks),
        ];
    }
}
