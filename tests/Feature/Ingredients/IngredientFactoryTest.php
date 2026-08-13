<?php

namespace Tests\Feature\Ingredients;

use App\Domain\Ingredients\IngredientBarcodeProvenance;
use App\Integrations\OpenFoodFacts\OpenFoodFactsClient;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_factory_persists_an_ordinary_ingredient_with_a_valid_owner(): void
    {
        $ingredient = Ingredient::factory()->create();

        $this->assertTrue($ingredient->exists);
        $this->assertTrue($ingredient->user->exists);
        $this->assertSame($ingredient->user_id, $ingredient->user->getKey());
        $this->assertSame('1.000', $ingredient->quantity);
        $this->assertSame('g', $ingredient->quantity_unit);
        $this->assertNull($ingredient->barcode);
        $this->assertNull($ingredient->nutriments);
        $this->assertSame(IngredientBarcodeProvenance::Manual, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_source);
        $this->assertNull($ingredient->barcode_imported_at);
    }

    public function test_manual_state_persists_without_external_product_attributes(): void
    {
        $ingredient = Ingredient::factory()->manual()->create();

        $this->assertNull($ingredient->barcode);
        $this->assertNull($ingredient->keywords);
        $this->assertNull($ingredient->categories);
        $this->assertNull($ingredient->image_url);
        $this->assertSame(IngredientBarcodeProvenance::Manual, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_source);
        $this->assertNull($ingredient->barcode_imported_at);
    }

    public function test_barcode_imported_state_persists_with_current_import_attributes(): void
    {
        $ingredient = Ingredient::factory()->barcodeImported()->create();

        $this->assertMatchesRegularExpression('/^0[0-9]{12}$/', $ingredient->barcode);
        $this->assertSame(IngredientBarcodeProvenance::MachineImported, $ingredient->barcode_provenance);
        $this->assertSame(OpenFoodFactsClient::PROVIDER, $ingredient->barcode_source);
        $this->assertNotNull($ingredient->barcode_imported_at);
        $this->assertSame(['synthetic', 'imported'], $ingredient->keywords);
        $this->assertSame(['en:test-foods'], $ingredient->categories);
        $this->assertSame('400.000', $ingredient->quantity);
        $this->assertSame('100.000', $ingredient->serving_quantity);
        $this->assertNull($ingredient->recommended_servings);
        $this->assertSame('https://example.test/products/synthetic-product.jpg', $ingredient->image_url);
    }

    public function test_legacy_barcode_state_is_explicitly_unknown(): void
    {
        $ingredient = Ingredient::factory()->legacyBarcode()->create();

        $this->assertMatchesRegularExpression('/^9[0-9]{12}$/', $ingredient->barcode);
        $this->assertSame(IngredientBarcodeProvenance::LegacyUnknown, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_source);
        $this->assertNull($ingredient->barcode_imported_at);
    }

    public function test_nutrition_state_persists_current_legacy_nutrition_data(): void
    {
        $ingredient = Ingredient::factory()->withNutrition()->create();

        $this->assertSame(245, data_get($ingredient->nutriments, 'per_100g.energy_kcal'));
        $this->assertSame(12.6, data_get($ingredient->nutriments, 'per_100g.proteins'));
        $this->assertSame(0.36, data_get($ingredient->nutriments, 'per_serving.salt'));
    }

    public function test_unusual_unit_state_persists_a_custom_unit(): void
    {
        $ingredient = Ingredient::factory()->unusualUnit()->create();

        $this->assertSame('2.000', $ingredient->quantity);
        $this->assertSame('sprig', $ingredient->quantity_unit);
    }

    public function test_supported_factory_state_combinations_persist(): void
    {
        $manualWithNutrition = Ingredient::factory()->manual()->withNutrition()->create();
        $barcodeWithNutrition = Ingredient::factory()->barcodeImported()->withNutrition()->create();
        $manualWithUnusualUnit = Ingredient::factory()->manual()->unusualUnit()->create();

        $this->assertNull($manualWithNutrition->barcode);
        $this->assertSame(245, data_get($manualWithNutrition->nutriments, 'per_100g.energy_kcal'));
        $this->assertMatchesRegularExpression('/^0[0-9]{12}$/', $barcodeWithNutrition->barcode);
        $this->assertSame(IngredientBarcodeProvenance::MachineImported, $barcodeWithNutrition->barcode_provenance);
        $this->assertSame(245, data_get($barcodeWithNutrition->nutriments, 'per_100g.energy_kcal'));
        $this->assertNull($manualWithUnusualUnit->barcode);
        $this->assertSame('sprig', $manualWithUnusualUnit->quantity_unit);
    }

    public function test_explicit_owner_association_uses_the_known_user(): void
    {
        $owner = User::factory()->create();

        $ingredient = Ingredient::factory()->for($owner)->create();

        $this->assertSame($owner->id, $ingredient->user_id);
        $this->assertTrue($ingredient->user->is($owner));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_ownership_is_not_mass_assignable(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ingredient = Ingredient::factory()->for($owner)->create();

        $ingredient->fill([
            'user_id' => $otherUser->id,
            'name' => 'Allowed name change',
        ])->save();

        $this->assertSame($owner->id, $ingredient->refresh()->user_id);
        $this->assertSame('Allowed name change', $ingredient->name);
    }

    public function test_several_factory_records_do_not_violate_current_constraints(): void
    {
        $ingredients = Ingredient::factory()->count(5)->create();
        $importedIngredients = Ingredient::factory()->barcodeImported()->count(5)->create();

        $this->assertCount(5, $ingredients);
        $this->assertCount(5, $importedIngredients);
        $this->assertSame(10, Ingredient::query()->count());
        $this->assertSame(5, $importedIngredients->pluck('barcode')->unique()->count());
    }
}
