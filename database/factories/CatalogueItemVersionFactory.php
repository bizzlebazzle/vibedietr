<?php

namespace Database\Factories;

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
}
