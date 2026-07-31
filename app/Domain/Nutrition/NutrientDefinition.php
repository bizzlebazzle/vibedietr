<?php

namespace App\Domain\Nutrition;

use Brick\Math\RoundingMode;

final readonly class NutrientDefinition
{
    /**
     * @param  list<NutrientBasis>  $supportedBases
     * @param  list<string>  $aliases
     */
    public function __construct(
        public Nutrient $id,
        public string $label,
        public NutrientUnit $canonicalStorageUnit,
        public NutrientUnit $preferredDisplayUnit,
        public array $supportedBases,
        public int $storagePrecision,
        public int $storageScale,
        public int $calculationPrecision,
        public int $divisionGuardScale,
        public int $displayPrecision,
        public RoundingMode $displayRounding,
        public bool $authoritative,
        public bool $derived,
        public ?Nutrient $derivedFrom,
        public ?string $derivationFactor,
        public array $aliases,
    ) {}
}
