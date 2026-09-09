<?php

namespace App\Domain\Catalogue;

use App\Domain\Nutrition\CatalogueNutrientObservation;

final readonly class ManualCatalogueSubmissionData
{
    /** @param list<CatalogueNutrientObservation> $nutrition */
    public function __construct(
        public string $name,
        public ManualFoodClassification $classification,
        public ?string $brand,
        public ?string $manufacturer,
        public ?string $foodForm,
        public ?string $preparation,
        public ?string $treatment,
        public ?string $composition,
        public PackageStructure $package,
        public array $nutrition,
        public ?ManualCatalogueSubmissionChoice $choice = null,
        public ?int $duplicateItemId = null,
        public ?string $distinctionExplanation = null,
    ) {}
}
