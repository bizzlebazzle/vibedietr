<?php

namespace App\Security\SecondFactor;

final readonly class RecoveryCodeSet
{
    /** @param list<string> $codes */
    public function __construct(public array $codes) {}
}
