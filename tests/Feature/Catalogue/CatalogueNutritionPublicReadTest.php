<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueItemStatus;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\LegacyIngredientCatalogueMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueNutritionPublicReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['catalogue.read_cutover' => true]);
    }

    public function test_public_catalogue_read_uses_normalized_facts_without_internal_provenance_leakage(): void
    {
        $item = CatalogueItem::factory()->manual()->create([
            'status' => CatalogueItemStatus::Approved,
        ]);
        LegacyIngredientCatalogueMapping::factory()->for($item, 'catalogueItem')->create([
            'legacy_snapshot' => [
                'name' => 'Normalized oats',
                'nutriments' => ['per_100g' => ['protein' => '999']],
            ],
        ]);
        $version = CatalogueItemVersion::factory()
            ->for($item)
            ->importedNutrition()
            ->create();
        $item->setCurrentVersion($version);

        $content = $this->get(route('catalogue.show', $item))
            ->assertOk()
            ->assertSee('Normalized oats')
            ->assertSee('7.3 g')
            ->assertSee('imported')
            ->assertDontSee('999')
            ->getContent();

        $this->assertStringNotContainsString('proteins_100g', $content);
        $this->assertStringNotContainsString('source_observation_id', $content);
        $this->assertStringNotContainsString('source_field', $content);
        $this->assertStringNotContainsString('imported_at', $content);
    }
}
