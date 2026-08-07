<?php

namespace App\Queue\Reference;

use Illuminate\Contracts\Cache\Repository;

final class CacheReferenceTaskResultRecorder implements ReferenceTaskResultRecorder
{
    public function __construct(private readonly Repository $cache) {}

    public function recordOnce(
        string $idempotencyFingerprint,
        string $correlationId,
        ReferenceTaskOutcome $outcome,
        int $lifetimeSeconds,
    ): bool {
        return $this->cache->add(
            self::cacheKey($idempotencyFingerprint),
            [
                'correlation_id' => $correlationId,
                'outcome' => $outcome->value,
            ],
            $lifetimeSeconds,
        );
    }

    public static function cacheKey(string $idempotencyFingerprint): string
    {
        return 'queue-reference-result:'.$idempotencyFingerprint;
    }
}
