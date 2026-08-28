<?php

namespace App\Security\Uploads;

final readonly class TransientInputHandle
{
    public function __construct(
        public string $disk,
        public string $key,
        public string $detectedMime,
        public int $bytes,
    ) {}
}
