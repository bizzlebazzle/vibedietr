<?php

namespace Tests\Feature\Recipes;

use App\Domain\Measurements\StandardUnit;
use App\Livewire\Recipes\IngredientLines;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeIngredientLineLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_exact_original_text_and_separate_standard_structure(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        $original = '  1 ½ cups  finely chopped onions  ';

        Livewire::actingAs($owner)->test(IngredientLines::class, ['recipe' => $recipe])
            ->set('originalText', $original)
            ->set('quantity', '1.500000000000000001')
            ->set('unit', 'cups')
            ->set('genericWording', 'onions')
            ->set('notes', 'finely chopped')
            ->call('saveLine')
            ->assertHasNoErrors();

        $line = RecipeIngredientLine::sole();
        $this->assertSame($original, $line->original_text);
        $this->assertSame('1.500000000000000001', $line->quantity);
        $this->assertSame(StandardUnit::Cup, $line->standard_unit);
        $this->assertNull($line->custom_unit);
        $this->assertSame('onions', $line->generic_wording);
        $this->assertSame('finely chopped', $line->notes);
        $this->assertSame(0, $line->position);
    }

    public function test_owner_can_add_unparseable_and_custom_unit_lines(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();

        $component = Livewire::actingAs($owner)->test(IngredientLines::class, ['recipe' => $recipe]);
        $component->set('originalText', 'salt to taste')->call('saveLine')->assertHasNoErrors();
        $component->set('originalText', 'a smidgen of saffron')
            ->set('unit', 'smidgen')
            ->set('genericWording', 'saffron')
            ->call('saveLine')
            ->assertHasNoErrors();

        [$unparseable, $custom] = $recipe->ingredientLines()->get()->all();
        $this->assertNull($unparseable->quantity);
        $this->assertNull($unparseable->standard_unit);
        $this->assertNull($unparseable->custom_unit);
        $this->assertSame('smidgen', $custom->custom_unit);
        $this->assertSame([0, 1], [$unparseable->position, $custom->position]);
    }

    public function test_editing_structure_does_not_rewrite_original_text(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        $line = RecipeIngredientLine::factory()->for($recipe)->create([
            'original_text' => '  pepper, roughly crushed  ',
        ]);

        Livewire::actingAs($owner)->test(IngredientLines::class, ['recipe' => $recipe])
            ->call('editLine', $line->id)
            ->set('quantity', '2')
            ->set('unit', 'pinch')
            ->set('notes', 'roughly crushed')
            ->call('saveLine')
            ->assertHasNoErrors();

        $this->assertSame('  pepper, roughly crushed  ', $line->refresh()->original_text);
        $this->assertSame('2.000000000000000000', $line->quantity);
        $this->assertSame('pinch', $line->custom_unit);
    }

    public function test_owner_can_move_and_remove_lines_without_affecting_another_recipe(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(3)->create();
        $otherRecipe = Recipe::factory()->for($owner, 'owner')->withIngredientLine(['original_text' => 'other'])->create();
        [$first, $second, $third] = $recipe->ingredientLines()->get()->all();

        $component = Livewire::actingAs($owner)->test(IngredientLines::class, ['recipe' => $recipe]);
        $component->call('moveUp', $third->id)->assertHasNoErrors();
        $this->assertSame([$first->id, $third->id, $second->id], $recipe->fresh()->ingredientLines()->pluck('id')->all());

        $component->call('deleteLine', $first->id)->assertHasNoErrors();
        $this->assertSame([$third->id, $second->id], $recipe->fresh()->ingredientLines()->pluck('id')->all());
        $this->assertSame([0, 1], $recipe->fresh()->ingredientLines()->pluck('position')->all());
        $this->assertSame(['other'], $otherRecipe->fresh()->ingredientLines()->pluck('original_text')->all());
    }

    public function test_non_owner_and_guest_cannot_mutate_recipe_lines(): void
    {
        $recipe = Recipe::factory()->withIngredientLine()->create();
        $otherUser = User::factory()->create();

        Livewire::actingAs($otherUser)
            ->test(IngredientLines::class, ['recipe' => $recipe])
            ->assertForbidden();

        Livewire::test(IngredientLines::class, ['recipe' => $recipe])
            ->assertForbidden();

        $this->assertDatabaseCount('recipe_ingredient_lines', 1);
    }

    public function test_foreign_line_identifiers_are_rejected_for_edit_delete_and_reorder(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(2)->create();
        $otherRecipe = Recipe::factory()->for($owner, 'owner')->withIngredientLine()->create();
        $foreignLine = $otherRecipe->ingredientLines()->sole();
        $ids = $recipe->ingredientLines()->pluck('id')->all();

        Livewire::actingAs($owner)->test(IngredientLines::class, ['recipe' => $recipe])
            ->call('editLine', $foreignLine->id)
            ->assertNotFound();

        Livewire::actingAs($owner)->test(IngredientLines::class, ['recipe' => $recipe])
            ->call('deleteLine', $foreignLine->id)
            ->assertNotFound();

        Livewire::actingAs($owner)->test(IngredientLines::class, ['recipe' => $recipe])
            ->call('reorder', [$ids[0], $foreignLine->id])
            ->assertHasErrors(['lineIds']);

        Livewire::actingAs($owner)->test(IngredientLines::class, ['recipe' => $recipe])
            ->call('reorder', [$ids[0], $ids[0]])
            ->assertHasErrors(['lineIds']);

        $this->assertSame($ids, $recipe->fresh()->ingredientLines()->pluck('id')->all());
        $this->assertSame($foreignLine->id, $otherRecipe->fresh()->ingredientLines()->sole()->id);
    }

    public function test_blank_original_text_and_invalid_quantity_are_rejected_without_losing_input(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();

        Livewire::actingAs($owner)->test(IngredientLines::class, ['recipe' => $recipe])
            ->set('originalText', '   ')
            ->set('quantity', '-1')
            ->call('saveLine')
            ->assertHasErrors(['originalText', 'quantity'])
            ->assertSet('originalText', '   ')
            ->assertSet('quantity', '-1');

        $this->assertDatabaseCount('recipe_ingredient_lines', 0);
    }
}
