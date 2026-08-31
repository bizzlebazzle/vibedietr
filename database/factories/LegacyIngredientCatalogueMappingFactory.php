<?php

namespace Database\Factories;

use App\Domain\Catalogue\LegacyIngredientClassification;
use App\Models\CatalogueItem;
use App\Models\Ingredient;
use App\Models\LegacyIngredientCatalogueMapping;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LegacyIngredientCatalogueMapping> */
class LegacyIngredientCatalogueMappingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $snapshot = ['name' => 'Legacy ingredient'];

        return [
            'ingredient_id' => Ingredient::factory(),
            'legacy_user_id' => User::factory(),
            'catalogue_item_id' => CatalogueItem::factory()->manual(),
            'classification' => LegacyIngredientClassification::LegacyManual,
            'review_reason' => null,
            'legacy_snapshot' => $snapshot,
            'legacy_checksum' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'backfill_version' => 1,
            'backfilled_at' => now()->utc(),
        ];
    }
}
