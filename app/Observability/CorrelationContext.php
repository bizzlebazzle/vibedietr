<?php

namespace App\Observability;

use App\Queue\CorrelationId;

final class CorrelationContext
{
    private ?string $correlationId = null;

    public function set(?string $correlationId): string
    {
        return $this->correlationId = CorrelationId::resolve($correlationId);
    }

    public function get(): string
    {
        return $this->correlationId ??= CorrelationId::resolve(null);
    }
}
