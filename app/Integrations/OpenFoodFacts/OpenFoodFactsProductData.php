<?php

namespace App\Integrations\OpenFoodFacts;

final readonly class OpenFoodFactsProductData
{
    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $categories
     * @param  array<string, mixed>  $nutriments
     */
    public function __construct(
        public string $code,
        public ?string $name,
        public array $keywords,
        public array $categories,
        public int|float|null $quantity,
        public ?string $quantityUnit,
        public bool $multipleQuantity,
        public int|float|null $servingQuantity,
        public ?string $servingQuantityUnit,
        public ?string $imageUrl,
        public array $nutriments,
    ) {}
}
