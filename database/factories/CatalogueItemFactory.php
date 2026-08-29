<?php

namespace Database\Factories;

use App\Domain\Catalogue\CatalogueItemOrigin;
use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Models\CatalogueItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogueItem> */
class CatalogueItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'origin' => CatalogueItemOrigin::Manual,
            'barcode' => null,
            'submitted_by_user_id' => null,
            'source' => CatalogueItemSource::Manual,
            'source_identifier' => null,
            'introduced_at' => now()->utc(),
            'status' => CatalogueItemStatus::Pending,
            'current_catalogue_item_version_id' => null,
        ];
    }

    public function manual(): static
    {
        return $this->state(fn () => [
            'origin' => CatalogueItemOrigin::Manual,
            'barcode' => null,
            'source' => CatalogueItemSource::Manual,
            'source_identifier' => null,
            'status' => CatalogueItemStatus::Pending,
        ]);
    }

    public function barcodeBacked(?string $barcode = null): static
    {
        return $this->state(fn () => [
            'origin' => CatalogueItemOrigin::Barcode,
            'barcode' => $barcode ?? fake()->unique()->numerify('0############'),
            'source' => CatalogueItemSource::OpenFoodFacts,
            'source_identifier' => fake()->unique()->numerify('off-#############'),
            'status' => CatalogueItemStatus::Approved,
        ]);
    }

    public function submittedBy(?User $user = null): static
    {
        return $this->state(fn () => [
            'submitted_by_user_id' => $user?->getKey() ?? User::factory(),
        ]);
    }
}
