<?php

namespace App\Domain\Catalogue;

use App\Domain\Nutrition\CatalogueNutrientObservation;

final readonly class CatalogueImportData
{
    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $categories
     * @param  list<CatalogueNutrientObservation>  $nutrition
     */
    public function __construct(
        public ?string $name,
        public array $keywords,
        public array $categories,
        public ?string $imageUrl,
        public PackageStructure $package,
        public array $nutrition,
    ) {}
}
