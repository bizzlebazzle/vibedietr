<?php

namespace App\Queue\Reference;

interface ReferenceTaskResultRecorder
{
    public function recordOnce(
        string $idempotencyFingerprint,
        string $correlationId,
        ReferenceTaskOutcome $outcome,
        int $lifetimeSeconds,
    ): bool;
}
