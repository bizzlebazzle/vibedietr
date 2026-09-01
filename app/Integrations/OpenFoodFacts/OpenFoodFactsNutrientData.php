<?php

namespace App\Integrations\OpenFoodFacts;

final readonly class OpenFoodFactsNutrientData
{
    public function __construct(
        public string $nutrient,
        public string $basis,
        public string $value,
        public string $unit,
        public string $sourceField,
    ) {}
}
