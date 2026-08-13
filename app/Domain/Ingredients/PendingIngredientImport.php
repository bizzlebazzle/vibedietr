<?php

namespace App\Domain\Ingredients;

use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupResult;

final readonly class PendingIngredientImport
{
    public function __construct(
        public string $requestedBarcode,
        public OpenFoodFactsLookupResult $result,
    ) {}
}
