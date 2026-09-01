<?php

namespace App\Integrations\OpenFoodFacts;

final readonly class OpenFoodFactsPackageData
{
    public function __construct(
        public ?int $packageCount,
        public ?string $itemType,
        public ?string $amountPerItem,
        public ?string $amountPerItemUnit,
        public ?string $servingsPerItem,
        public ?string $servingAmount,
        public ?string $servingAmountUnit,
    ) {}
}
