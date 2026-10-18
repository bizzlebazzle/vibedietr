<?php

namespace App\Domain\AccountDeletion;

final readonly class ErasureCheckpoint
{
    public function __construct(
        public string $generation,
        public int $sequence,
        public string $digest,
    ) {}
}
