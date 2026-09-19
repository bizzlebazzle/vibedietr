<?php

namespace App\Domain\Diary;

use Carbon\CarbonImmutable;

final readonly class ResolvedConsumptionTime
{
    public function __construct(
        public CarbonImmutable $local,
        public string $timezone,
        public int $offsetMinutes,
        public CarbonImmutable $utc,
    ) {}
}
