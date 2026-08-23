<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeRevisionManager;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeQuantityResizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_scale_all_supported_unit_kinds_and_reset_without_persistence(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        RecipeIngredientLine::factory()->for($recipe)->create([
            'position' => 0,
            'original_text' => 'mutable child must stay untouched',
            'quantity' => '9',
            'standard_unit' => 'gram',
            'generic_wording' => 'mutable child',
        ]);
        $before = $this->databaseState($recipe);

        $this->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '8']))
            ->assertOk()
            ->assertSeeText('400 g plain flour, sifted')
            ->assertSeeText('500 ml stock')
            ->assertSeeText('4 piece eggs')
            ->assertSeeText('4 pinches seasoning')
            ->assertSeeText('1 tbsp oil')
            ->assertSeeText('0.5 cup milk')
            ->assertSeeText('3 clove garlic')
            ->assertSeeText('salt to taste')
            ->assertSeeText('2 mystery items')
            ->assertDontSeeText('mutable child must stay untouched')
            ->assertSeeText('Quantities are adjusted for display only. The saved recipe remains unchanged.');

        $this->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '2']))
            ->assertOk()
            ->assertSeeText('100 g plain flour, sifted')
            ->assertSeeText('0.25 tbsp oil');

        $this->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '6']))
            ->assertOk()
            ->assertSeeText('300 g plain flour, sifted')
            ->assertSeeText('3 pinches seasoning');

        $this->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '2.5']))
            ->assertOk()
            ->assertSeeText('125 g plain flour, sifted')
            ->assertSeeText('1.25 piece eggs');

        $this->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '1']))
            ->assertOk()
            ->assertSeeText('0.5 piece eggs')
            ->assertDontSeeText('1 piece eggs');

        $this->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSeeText('200 g plain flour, sifted')
            ->assertSeeText('Reset to 4');

        $this->assertSame($before, $this->databaseState($recipe));
    }

    public function test_invalid_and_missing_requests_fall_back_safely_without_mutation(): void
    {
        $recipe = $this->finalizedRecipe(User::factory()->create());
        $before = $this->databaseState($recipe);

        foreach ([
            '0' => 'Requested servings must be greater than zero.',
            '-1' => 'Requested servings must be greater than zero.',
            'not-a-number' => 'Enter a valid serving count with no more than two decimal places.',
            '1.234' => 'Enter a valid serving count with no more than two decimal places.',
            '999999999' => 'Requested servings are too large.',
        ] as $request => $message) {
            $this->get(route('recipes.show', ['recipe' => $recipe, 'servings' => $request]))
                ->assertOk()
                ->assertSeeText($message)
                ->assertSeeText('200 g plain flour, sifted')
                ->assertDontSeeText('400 g plain flour, sifted');
        }

        $this->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '']))
            ->assertOk()
            ->assertSeeText('200 g plain flour, sifted');
        $this->get(route('recipes.show', $recipe).'?servings[crafted]=8')
            ->assertOk()
            ->assertSeeText('Enter a valid numeric serving count.')
            ->assertSeeText('200 g plain flour, sifted');

        $this->assertSame($before, $this->databaseState($recipe));
    }

    public function test_invalid_saved_servings_disable_resizing_without_division_or_fallback(): void
    {
        foreach (['0', null, 'invalid'] as $savedServings) {
            $recipe = $this->finalizedRecipe(User::factory()->create(), $savedServings);

            $this->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '8']))
                ->assertOk()
                ->assertSeeText('does not have a valid positive saved serving count')
                ->assertSeeText('200 g plain flour, sifted')
                ->assertDontSeeText('400 g plain flour, sifted');
        }
    }

    public function test_resize_uses_current_version_while_owner_preview_uses_saved_revision_values(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        $versionBefore = $recipe->currentVersion()->sole()->snapshot;
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $recipe->forceFill(['servings' => '2.00'])->save();
        $draftLine = $recipe->ingredientLines()->where('position', 0)->sole();
        $draftLine->forceFill([
            'original_text' => '50 g draft flour',
            'quantity' => '50',
            'generic_wording' => 'draft flour',
            'notes' => null,
        ])->save();
        $before = $this->databaseState($recipe);

        $this->actingAs($owner)
            ->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '4']))
            ->assertOk()
            ->assertSeeText('200 g plain flour, sifted')
            ->assertDontSeeText('100 g draft flour');

        $this->actingAs($owner)
            ->get(route('recipes.show', ['recipe' => $recipe, 'preview' => 'draft', 'servings' => '4']))
            ->assertOk()
            ->assertSeeText('Private draft revision preview')
            ->assertSeeText('100 g draft flour')
            ->assertDontSeeText('200 g plain flour, sifted');

        auth()->logout();
        $this->get(route('recipes.show', ['recipe' => $recipe, 'preview' => 'draft']))->assertNotFound();
        $this->actingAs($other)
            ->get(route('recipes.show', ['recipe' => $recipe, 'preview' => 'draft']))
            ->assertNotFound();

        $this->assertSame($versionBefore, $recipe->currentVersion()->sole()->fresh()->snapshot);
        $this->assertSame($before, $this->databaseState($recipe));
    }

    public function test_private_recipe_visibility_remains_enforced_for_resize_requests(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, '4.00', RecipeVisibility::Private);

        $this->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '8']))->assertNotFound();
        $this->actingAs($other)
            ->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '8']))
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '8']))
            ->assertOk()
            ->assertSeeText('400 g plain flour, sifted');
    }

    private function finalizedRecipe(
        User $owner,
        ?string $snapshotServings = '4.00',
        RecipeVisibility $visibility = RecipeVisibility::Public,
    ): Recipe {
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Mutable recipe title',
            'servings' => '9.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => $visibility,
            'finalized_at' => now()->utc(),
        ]);
        $version = RecipeVersion::factory()->for($recipe)->create([
            'visibility' => $visibility,
            'snapshot' => [
                'title' => 'Stable resize recipe',
                'servings' => $snapshotServings,
                'visibility' => $visibility->value,
                'ingredients' => [
                    $this->line(0, '200 g plain flour, sifted', '200', 'gram', null, 'plain flour', 'sifted'),
                    $this->line(1, '250 ml stock', '250', 'millilitre', null, 'stock'),
                    $this->line(2, '2 eggs', '2', 'piece', null, 'eggs'),
                    $this->line(3, '2 pinches seasoning', '2', null, 'pinches', 'seasoning'),
                    $this->line(4, '0.5 tbsp oil', '0.5', 'tablespoon_uk', null, 'oil'),
                    $this->line(5, '0.25 cup milk', '0.25', 'cup_us', null, 'milk'),
                    $this->line(6, '1.5 cloves garlic', '1.5', 'clove', null, 'garlic'),
                    $this->line(7, 'salt to taste', null, null, null, null),
                    $this->line(8, '2 mystery items', '2', 'piece', null, null),
                ],
                'sections' => [],
                'steps' => [['position' => 0, 'text' => 'Cook it.', 'section_key' => null]],
            ],
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();

        return $recipe->fresh();
    }

    /**
     * @return array{position: int, original_text: string, quantity: string|null, standard_unit: string|null, custom_unit: string|null, generic_wording: string|null, notes: string|null}
     */
    private function line(
        int $position,
        string $originalText,
        ?string $quantity,
        ?string $standardUnit,
        ?string $customUnit,
        ?string $wording,
        ?string $notes = null,
    ): array {
        return [
            'position' => $position,
            'original_text' => $originalText,
            'quantity' => $quantity,
            'standard_unit' => $standardUnit,
            'custom_unit' => $customUnit,
            'generic_wording' => $wording,
            'notes' => $notes,
        ];
    }

    /** @return array{recipe: array<string, mixed>, lines: array<int, array<string, mixed>>, versions: array<string, array<string, mixed>>} */
    private function databaseState(Recipe $recipe): array
    {
        return [
            'recipe' => $recipe->fresh()->getAttributes(),
            'lines' => RecipeIngredientLine::query()
                ->where('recipe_id', $recipe->id)
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (RecipeIngredientLine $line): array => [$line->id => $line->getAttributes()])
                ->all(),
            'versions' => RecipeVersion::query()
                ->where('recipe_id', $recipe->id)
                ->orderBy('version_number')
                ->get()
                ->mapWithKeys(fn (RecipeVersion $version): array => [$version->id => $version->snapshot])
                ->all(),
        ];
    }
}
