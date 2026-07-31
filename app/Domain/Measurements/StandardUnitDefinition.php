<?php

namespace App\Domain\Measurements;

final readonly class StandardUnitDefinition
{
    /**
     * @param  list<string>  $aliases
     */
    public function __construct(
        public StandardUnit $id,
        public string $symbol,
        public string $label,
        public MeasurementDimension $dimension,
        public ?string $canonicalFactor,
        public array $aliases,
    ) {}
}
