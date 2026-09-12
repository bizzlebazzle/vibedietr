<?php

namespace Tests\Feature\Nutrition;

use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\RecipeNutritionEstimatePresenter;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeNutritionEstimatePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_estimate_labels_whole_recipe_and_per_serving_outputs(): void
    {
        $snapshot = $this->snapshot(
            ingredients: [$this->ingredient(0, '100 g oats')],
            wholeRecipe: $this->allNutrients('10'),
            perServing: $this->allNutrients('5'),
            inputs: [$this->input(0)],
        );
        $recipe = $this->finalizedRecipe(User::factory()->create(), $snapshot);

        $this->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Estimated nutrition — whole recipe')
            ->assertSee('Estimated nutrition — per serving')
            ->assertSee('Values are estimates, not verified nutrition facts.')
            ->assertSee('Complete estimate: every ingredient line contributed and none requires review.')
            ->assertDontSee('Estimate limitations');
    }

    public function test_partial_estimate_lists_every_upstream_exclusion_and_review_line_without_suppressing_values(): void
    {
        $ingredients = [
            $this->ingredient(0, '100 g oats'),
            $this->ingredient(1, 'A pinch of mystery spice'),
            $this->ingredient(2, 'One handful of seeds'),
            $this->ingredient(3, 'One serving of sauce'),
            $this->ingredient(4, '50 g suggested yoghurt', 'needs_review'),
        ];
        $snapshot = $this->snapshot(
            ingredients: $ingredients,
            wholeRecipe: ['protein' => $this->value('12.34')],
            perServing: ['protein' => $this->value('6.17')],
            inputs: [
                $this->input(0),
                $this->input(1, [['reason' => 'catalogue_match_unavailable']]),
                $this->input(2, [['nutrient' => 'protein', 'reason' => 'custom_unit_not_convertible']]),
                $this->input(3, [['nutrient' => 'fat', 'reason' => 'food_conversion_missing']]),
                $this->input(4),
            ],
        );
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, $snapshot);

        $response = $this->actingAs($owner)->get(route('recipes.show', $recipe));

        $response->assertOk()
            ->assertSee('This is a partial estimate.')
            ->assertSee('12.3 g')
            ->assertSee('6.2 g')
            ->assertSee('A pinch of mystery spice')
            ->assertSee('No catalogue match is selected, so this line is excluded.')
            ->assertSee('One handful of seeds')
            ->assertSee('The custom unit cannot be converted reliably. Affected nutrients: Protein.')
            ->assertSee('One serving of sauce')
            ->assertSee('The matched food has no reliable conversion for this quantity. Affected nutrients: Fat.')
            ->assertSee('50 g suggested yoghurt')
            ->assertSee('The selected catalogue match requires creator review.')
            ->assertSee(route('recipes.edit', $recipe).'#ingredient-line-2', false)
            ->assertSee(route('recipes.edit', $recipe).'#ingredient-line-5', false)
            ->assertSee('role="status"', false)
            ->assertSee('aria-labelledby="nutrition-limitations-heading"', false);

        $this->actingAs($owner)->get(route('recipes.edit', $recipe))
            ->assertOk()
            ->assertSee('id="ingredient-line-2"', false)
            ->assertSee('id="ingredient-line-5"', false);
    }

    public function test_unavailable_and_missing_values_are_not_zero_while_genuine_zero_remains_available(): void
    {
        $presenter = app(RecipeNutritionEstimatePresenter::class);
        $unavailable = $presenter->present($this->snapshot(
            ingredients: [$this->ingredient(0, 'Unknown ingredient')],
            inputs: [$this->input(0, [['reason' => 'catalogue_match_unavailable']])],
        ));

        $this->assertSame('unavailable', $unavailable['status']);
        $this->assertSame('Not available', $this->row($unavailable['whole_recipe'], 'Protein')['value']);
        $this->assertFalse($this->row($unavailable['whole_recipe'], 'Protein')['available']);

        $partial = $presenter->present($this->snapshot(
            ingredients: [$this->ingredient(0, 'Zero-fat ingredient')],
            wholeRecipe: ['fat' => $this->value('0')],
            perServing: ['fat' => $this->value('0')],
            inputs: [$this->input(0, [['nutrient' => 'protein', 'reason' => 'nutrient_value_unavailable']])],
        ));

        $this->assertSame('partial', $partial['status']);
        $this->assertSame('0.0 g', $this->row($partial['whole_recipe'], 'Fat')['value']);
        $this->assertTrue($this->row($partial['whole_recipe'], 'Fat')['available']);
        $this->assertSame('Not available', $this->row($partial['whole_recipe'], 'Protein')['value']);
        $this->assertFalse($this->row($partial['whole_recipe'], 'Protein')['available']);
    }

    public function test_public_reader_sees_limitations_but_only_creator_sees_correction_links(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, $this->snapshot(
            ingredients: [$this->ingredient(0, 'Unmatched public ingredient')],
            inputs: [$this->input(0, [['reason' => 'catalogue_match_unavailable']])],
        ));

        $this->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('No nutrition values are currently available for this recipe estimate.')
            ->assertSee('Unmatched public ingredient')
            ->assertDontSee('Review or correct ingredient')
            ->assertDontSee(route('recipes.edit', $recipe), false);

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Review or correct ingredient 1')
            ->assertSee(route('recipes.edit', $recipe).'#ingredient-line-1', false);
    }

    /**
     * @param  list<array<string, mixed>>  $ingredients
     * @param  array<string, array<string, mixed>>  $wholeRecipe
     * @param  array<string, array<string, mixed>>  $perServing
     * @param  list<array<string, mixed>>  $inputs
     * @return array<string, mixed>
     */
    private function snapshot(
        array $ingredients,
        array $wholeRecipe = [],
        array $perServing = [],
        array $inputs = [],
    ): array {
        return [
            'title' => 'Nutrition presentation recipe',
            'servings' => '2.00',
            'visibility' => RecipeVisibility::Public->value,
            'ingredients' => $ingredients,
            'sections' => [],
            'steps' => [],
            'nutrition_estimate' => [
                'type' => 'estimate',
                'is_estimate' => true,
                'calculation_policy_version' => 1,
                'whole_recipe' => $wholeRecipe,
                'per_serving' => $perServing,
                'inputs' => $inputs,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function ingredient(int $position, string $text, ?string $reviewState = null): array
    {
        return [
            'position' => $position,
            'original_text' => $text,
            'quantity' => null,
            'standard_unit' => null,
            'custom_unit' => null,
            'generic_wording' => null,
            'notes' => null,
            'catalogue_match' => $reviewState === null ? null : ['review_state' => $reviewState],
        ];
    }

    /**
     * @param  list<array<string, string>>  $exclusions
     * @return array<string, mixed>
     */
    private function input(int $position, array $exclusions = []): array
    {
        return [
            'ingredient_position' => $position,
            'contributions' => [],
            'exclusions' => $exclusions,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function allNutrients(string $amount): array
    {
        return collect(Nutrient::cases())
            ->mapWithKeys(fn (Nutrient $nutrient): array => [$nutrient->value => $this->value($amount)])
            ->all();
    }

    /** @return array<string, mixed> */
    private function value(string $amount): array
    {
        return ['value' => $amount, 'status' => 'approximate', 'is_estimate' => true];
    }

    /**
     * @param  list<array{label: string, value: string, available: bool}>  $rows
     * @return array{label: string, value: string, available: bool}
     */
    private function row(array $rows, string $label): array
    {
        return collect($rows)->firstOrFail(fn (array $row): bool => $row['label'] === $label);
    }

    /** @param array<string, mixed> $snapshot */
    private function finalizedRecipe(User $owner, array $snapshot): Recipe
    {
        $finalizedAt = now()->utc();
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Mutable title',
            'servings' => '2.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => RecipeVisibility::Public,
            'finalized_at' => $finalizedAt,
        ]);
        $version = RecipeVersion::factory()->for($recipe)->create([
            'visibility' => RecipeVisibility::Public,
            'snapshot' => $snapshot,
            'finalized_at' => $finalizedAt,
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $version->getKey()])->save();

        return $recipe->fresh();
    }
}
