<?php

namespace Tests\Feature\Nutrition;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\CatalogueNutrientObservation;
use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientDisplayFormatter;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientValueStatus;
use App\Domain\Nutrition\RecipeNutritionEstimator;
use App\Domain\Recipes\RecipeVersionContent;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeIngredientLineMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeNutritionEstimatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_recipe_is_versioned_as_explicit_whole_and_per_serving_estimates(): void
    {
        $version = CatalogueItemVersion::factory()
            ->for(CatalogueItem::factory()->approved())
            ->completeNutrition()
            ->create();
        $recipe = Recipe::factory()->create(['servings' => '4']);
        $line = $this->matchedLine($recipe, $version, '200', StandardUnit::Gram);

        $snapshot = app(RecipeVersionContent::class)->snapshot($recipe);
        $estimate = $snapshot['nutrition_estimate'];

        $this->assertSame('estimate', $estimate['type']);
        $this->assertTrue($estimate['is_estimate']);
        $this->assertSame(RecipeNutritionEstimator::POLICY_VERSION, $estimate['calculation_policy_version']);
        $this->assertCount(10, $estimate['whole_recipe']);
        $this->assertSame('14.500000000000000000', $estimate['whole_recipe']['protein']['value']);
        $this->assertSame('per_recipe', $estimate['whole_recipe']['protein']['basis']);
        $this->assertSame('approximate', $estimate['whole_recipe']['protein']['status']);
        $this->assertTrue($estimate['whole_recipe']['protein']['is_estimate']);
        $this->assertSame('3.625000000000000000', $estimate['per_serving']['protein']['value']);
        $this->assertSame('per_serving', $estimate['per_serving']['protein']['basis']);
        $this->assertSame((string) $version->getKey(), $estimate['inputs'][0]['catalogue_item_version_id']);
        $this->assertSame($line->position, $estimate['inputs'][0]['ingredient_position']);
        $this->assertNotEmpty($estimate['inputs'][0]['contributions'][0]['catalogue_nutrient_value_id']);
    }

    public function test_partial_nutrients_calculate_independently_across_mixed_reliable_bases(): void
    {
        $massVersion = $this->versionWithNutrition([
            $this->observation(Nutrient::Protein, '10', NutrientBasis::Per100Gram),
        ]);
        $volumeVersion = $this->versionWithNutrition([
            $this->observation(Nutrient::Fat, '8', NutrientBasis::Per100Millilitre),
        ]);
        $servingVersion = $this->versionWithNutrition(
            [$this->observation(Nutrient::Carbohydrates, '5', NutrientBasis::PerServing)],
            [
                'serving_amount' => '200',
                'serving_amount_unit' => StandardUnit::Gram,
                'serving_amount_basis' => 'source',
                'serving_source' => CatalogueItemSource::Manual,
            ],
        );
        $recipe = Recipe::factory()->create(['servings' => '2']);
        $this->matchedLine($recipe, $massVersion, '200', StandardUnit::Gram, 0);
        $this->matchedLine($recipe, $volumeVersion, '50', StandardUnit::Millilitre, 1);
        $this->matchedLine($recipe, $servingVersion, '400', StandardUnit::Gram, 2);

        $estimate = app(RecipeNutritionEstimator::class)->estimate($recipe);

        $this->assertSame('20.000000000000000000', $estimate['whole_recipe']['protein']['value']);
        $this->assertSame('4.000000000000000000', $estimate['whole_recipe']['fat']['value']);
        $this->assertSame('10.000000000000000000', $estimate['whole_recipe']['carbohydrates']['value']);
        $this->assertArrayNotHasKey('sugars', $estimate['whole_recipe']);
        $this->assertContains(
            ['nutrient' => 'fat', 'reason' => 'nutrient_value_unavailable'],
            $estimate['inputs'][0]['exclusions'],
        );
        $servingContribution = $estimate['inputs'][2]['contributions'][0];
        $this->assertSame('2.000000000000000000', $servingContribution['converted_quantity']);
        $this->assertSame((string) $servingVersion->getKey(), $servingContribution['food_conversion']['catalogue_item_version_id']);
        $this->assertTrue($servingContribution['food_conversion']['reliable']);
    }

    public function test_recalculation_uses_the_recorded_catalogue_version_instead_of_the_current_version(): void
    {
        $item = CatalogueItem::factory()->approved()->create();
        $pinned = $this->versionWithNutrition(
            [$this->observation(Nutrient::Protein, '10')],
            ['catalogue_item_id' => $item->getKey(), 'version_number' => 1],
        );
        $item->setCurrentVersion($pinned);
        $current = $this->versionWithNutrition(
            [$this->observation(Nutrient::Protein, '99')],
            ['catalogue_item_id' => $item->getKey(), 'version_number' => 2],
        );
        $item->setCurrentVersion($current);
        $recipe = Recipe::factory()->create(['servings' => '1']);
        $this->matchedLine($recipe, $pinned, '100', StandardUnit::Gram);

        $estimate = app(RecipeNutritionEstimator::class)->estimate($recipe);

        $this->assertSame('10.000000000000000000', $estimate['whole_recipe']['protein']['value']);
        $this->assertSame((string) $pinned->getKey(), $estimate['inputs'][0]['catalogue_item_version_id']);
        $this->assertNotSame((string) $current->getKey(), $estimate['inputs'][0]['catalogue_item_version_id']);
    }

    public function test_full_precision_is_persisted_and_rounding_occurs_only_when_formatted_for_display(): void
    {
        $version = $this->versionWithNutrition([
            $this->observation(Nutrient::Protein, '1.234'),
        ]);
        $recipe = Recipe::factory()->create(['servings' => '1']);
        $this->matchedLine($recipe, $version, '100', StandardUnit::Gram);

        $estimate = app(RecipeNutritionEstimator::class)->estimate($recipe);
        $stored = $estimate['whole_recipe']['protein']['value'];

        $this->assertSame('1.234000000000000000', $stored);
        $this->assertSame('1.2 g', app(NutrientDisplayFormatter::class)->format(
            Nutrient::Protein,
            $stored,
            NutrientValueStatus::Approximate,
        ));
    }

    public function test_unsupported_conversion_is_excluded_without_inventing_a_total(): void
    {
        $version = $this->versionWithNutrition([
            $this->observation(Nutrient::Protein, '7.25'),
        ]);
        $recipe = Recipe::factory()->create(['servings' => '2']);
        $line = RecipeIngredientLine::factory()->for($recipe)->customUnit('handful')->create([
            'position' => 0,
            'quantity' => '1',
        ]);
        RecipeIngredientLineMatch::factory()->for($line, 'ingredientLine')->create([
            'catalogue_item_version_id' => $version->getKey(),
        ]);

        $estimate = app(RecipeNutritionEstimator::class)->estimate($recipe);

        $this->assertSame([], $estimate['whole_recipe']);
        $this->assertSame([], $estimate['per_serving']);
        $this->assertContains(
            ['nutrient' => 'protein', 'reason' => 'custom_unit_not_convertible'],
            $estimate['inputs'][0]['exclusions'],
        );
    }

    /**
     * @param  list<CatalogueNutrientObservation>  $observations
     * @param  array<string, mixed>  $attributes
     */
    private function versionWithNutrition(array $observations, array $attributes = []): CatalogueItemVersion
    {
        $attributes += ['catalogue_item_id' => CatalogueItem::factory()->approved()->create()->getKey()];
        $version = CatalogueItemVersion::factory()->create($attributes);
        app(CatalogueNutritionNormalizer::class)->store($version, $observations);

        return $version;
    }

    private function observation(
        Nutrient $nutrient,
        string $value,
        NutrientBasis $basis = NutrientBasis::Per100Gram,
    ): CatalogueNutrientObservation {
        return new CatalogueNutrientObservation(
            $nutrient,
            $basis,
            $value,
            $nutrient === Nutrient::EnergyKcal ? NutrientUnit::Kilocalorie : NutrientUnit::Gram,
            NutrientProvenance::ManuallySubmitted,
        );
    }

    private function matchedLine(
        Recipe $recipe,
        CatalogueItemVersion $version,
        string $quantity,
        StandardUnit $unit,
        int $position = 0,
    ): RecipeIngredientLine {
        $line = RecipeIngredientLine::factory()->for($recipe)->create([
            'position' => $position,
            'quantity' => $quantity,
            'standard_unit' => $unit,
            'custom_unit' => null,
        ]);
        RecipeIngredientLineMatch::factory()->for($line, 'ingredientLine')->create([
            'catalogue_item_version_id' => $version->getKey(),
        ]);

        return $line;
    }
}
