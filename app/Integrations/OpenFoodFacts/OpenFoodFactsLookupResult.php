<?php

namespace App\Integrations\OpenFoodFacts;

final readonly class OpenFoodFactsLookupResult
{
    private function __construct(
        public OpenFoodFactsLookupStatus $status,
        public string $correlationId,
        public ?OpenFoodFactsProductData $product = null,
    ) {}

    public static function success(string $correlationId, OpenFoodFactsProductData $product): self
    {
        return new self(OpenFoodFactsLookupStatus::Success, $correlationId, $product);
    }

    public static function failure(OpenFoodFactsLookupStatus $status, string $correlationId): self
    {
        return new self($status, $correlationId);
    }
}
