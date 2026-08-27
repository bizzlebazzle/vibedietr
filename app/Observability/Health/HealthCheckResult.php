<?php

namespace App\Observability\Health;

final readonly class HealthCheckResult
{
    public function __construct(
        public string $name,
        public string $state,
        public string $reason = 'available',
    ) {}

    public static function healthy(string $name): self
    {
        return new self($name, 'healthy');
    }

    public static function degraded(string $name, string $reason): self
    {
        return new self($name, 'degraded', $reason);
    }

    public static function unhealthy(string $name, string $reason): self
    {
        return new self($name, 'unhealthy', $reason);
    }
}
