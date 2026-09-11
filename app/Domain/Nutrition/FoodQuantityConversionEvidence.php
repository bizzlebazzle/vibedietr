<?php

namespace App\Domain\Nutrition;

use App\Domain\Catalogue\CatalogueItemSource;

final readonly class FoodQuantityConversionEvidence
{
    public function __construct(
        public string $catalogueItemVersionId,
        public CatalogueItemSource $source,
        public string $basis,
        public bool $reliable = true,
    ) {}
}
