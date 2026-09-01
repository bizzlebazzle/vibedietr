<?php

namespace Database\Factories;

use App\Domain\Catalogue\ServingAmountBasis;
use App\Domain\Measurements\StandardUnit;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogueItemVersion> */
class CatalogueItemVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'catalogue_item_id' => CatalogueItem::factory(),
            'version_number' => 1,
        ];
    }

    public function singleItem(): static
    {
        return $this->state(fn () => [
            'package_count' => 1,
            'item_type' => null,
            'amount_per_item' => '400',
            'amount_per_item_unit' => StandardUnit::Gram,
        ]);
    }

    public function multipack(): static
    {
        return $this->state(fn () => [
            'package_count' => 4,
            'item_type' => 'can',
            'amount_per_item' => '400',
            'amount_per_item_unit' => StandardUnit::Gram,
        ]);
    }

    public function partialPackage(): static
    {
        return $this->state(fn () => [
            'package_count' => 4,
            'item_type' => 'can',
        ]);
    }

    public function directlySourcedServing(): static
    {
        return $this->state(fn () => [
            'serving_amount' => '200',
            'serving_amount_unit' => StandardUnit::Gram,
            'serving_amount_basis' => ServingAmountBasis::Source,
        ]);
    }

    public function reliablyDerivedServing(): static
    {
        return $this->state(fn () => [
            'package_count' => 4,
            'item_type' => 'can',
            'amount_per_item' => '400',
            'amount_per_item_unit' => StandardUnit::Gram,
            'servings_per_item' => '2',
            'serving_amount' => '200',
            'serving_amount_unit' => StandardUnit::Gram,
            'serving_amount_basis' => ServingAmountBasis::AmountPerItemDividedByServingsPerItem,
        ]);
    }
}
