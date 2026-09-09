<?php

namespace Database\Factories;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueProviderRefreshState;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueProviderRefresh;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CatalogueProviderRefresh> */
class CatalogueProviderRefreshFactory extends Factory
{
    protected $model = CatalogueProviderRefresh::class;

    public function definition(): array
    {
        $item = CatalogueItem::factory()->barcodeBacked()->create();
        $version = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->current()->create();

        return [
            'catalogue_item_id' => $item->id,
            'base_catalogue_item_version_id' => $version->id,
            'provider' => CatalogueItemSource::OpenFoodFacts,
            'source_identifier' => $item->source_identifier,
            'correlation_id' => strtolower((string) Str::ulid()),
            'state' => CatalogueProviderRefreshState::Queued,
            'active_key' => CatalogueItemSource::OpenFoodFacts->value.':'.$item->id,
        ];
    }
}
