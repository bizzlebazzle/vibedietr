<?php

namespace App\Domain\Nutrition;

use App\Domain\Catalogue\CatalogueItemSource;
use Carbon\CarbonImmutable;

final readonly class CatalogueNutrientObservation
{
    public function __construct(
        public Nutrient $nutrient,
        public NutrientBasis $basis,
        public string|int|null $value,
        public NutrientUnit $unit,
        public NutrientProvenance $provenance,
        public NutrientValueStatus $status = NutrientValueStatus::Known,
        public string|int|null $thresholdValue = null,
        public ?CatalogueItemSource $source = null,
        public ?string $sourceField = null,
        public ?CarbonImmutable $sourceObservedAt = null,
        public ?CarbonImmutable $importedAt = null,
    ) {}
}
