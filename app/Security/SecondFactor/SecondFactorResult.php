<?php

namespace App\Security\SecondFactor;

final readonly class SecondFactorResult
{
    public function __construct(public SecondFactorStatus $status, public ?int $matchedTimestep = null) {}

    public function succeeded(): bool
    {
        return $this->status === SecondFactorStatus::Verified;
    }
}
