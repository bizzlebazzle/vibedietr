<?php

namespace Tests\Feature\Recipes;

use App\Domain\Measurements\StandardUnit;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeIngredientLineModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_text_variants_round_trip_exactly(): void
    {
        $samples = [
            '2 onions',
            '  salt to taste',
            'salt to taste  ',
            '1  1/2   cups  onions',
            '  1 ½ cups  finely chopped onions  ',
            'salt, pepper & lemon (optional)!',
        ];

        foreach ($samples as $originalText) {
            $line = RecipeIngredientLine::factory()->create(['original_text' => $originalText]);

            $this->assertSame($originalText, $line->refresh()->original_text);
        }
    }

    public function test_incomplete_and_unparseable_lines_need_no_structured_values(): void
    {
        foreach (['salt to taste', 'pepper', 'a handful of parsley', 'juice of half a lemon', '1-2 onions', '2-ish onions'] as $text) {
            $line = RecipeIngredientLine::factory()->create(['original_text' => $text]);

            $this->assertNull($line->quantity);
            $this->assertNull($line->standard_unit);
            $this->assertNull($line->custom_unit);
            $this->assertNull($line->generic_wording);
            $this->assertNull($line->notes);
        }
    }

    public function test_structured_fields_are_stored_separately_from_original_text(): void
    {
        $originalText = '2 large red onions, finely chopped';
        $line = RecipeIngredientLine::factory()->create([
            'original_text' => $originalText,
            'quantity' => '2.125',
            'standard_unit' => StandardUnit::Piece,
            'generic_wording' => 'red onions',
            'notes' => 'large; finely chopped',
        ]);

        $this->assertSame($originalText, $line->original_text);
        $this->assertSame('2.125000000000000000', $line->quantity);
        $this->assertSame(StandardUnit::Piece, $line->standard_unit);
        $this->assertNull($line->custom_unit);
        $this->assertSame('red onions', $line->generic_wording);
        $this->assertSame('large; finely chopped', $line->notes);

        $line->update([
            'quantity' => '3.5',
            'standard_unit' => null,
            'custom_unit' => 'large bunch',
            'generic_wording' => 'red onion',
            'notes' => 'divided',
        ]);

        $this->assertSame($originalText, $line->refresh()->original_text);
        $this->assertSame('3.500000000000000000', $line->quantity);
        $this->assertNull($line->standard_unit);
        $this->assertSame('large bunch', $line->custom_unit);
    }

    public function test_relationships_are_ordered_and_recipe_deletion_cascades(): void
    {
        $recipe = Recipe::factory()->create();
        $second = RecipeIngredientLine::factory()->for($recipe)->create(['position' => 1, 'original_text' => 'second']);
        $first = RecipeIngredientLine::factory()->for($recipe)->create(['position' => 0, 'original_text' => 'first']);

        $this->assertTrue($first->recipe->is($recipe));
        $this->assertSame([$first->id, $second->id], $recipe->ingredientLines()->pluck('id')->all());

        $recipe->delete();

        $this->assertDatabaseMissing('recipe_ingredient_lines', ['id' => $first->id]);
        $this->assertDatabaseMissing('recipe_ingredient_lines', ['id' => $second->id]);
    }

    public function test_recipe_and_line_factories_cover_common_line_states(): void
    {
        $recipe = Recipe::factory()->withIngredientLines(3)->create();
        $structured = RecipeIngredientLine::factory()->structured()->create();
        $custom = RecipeIngredientLine::factory()->customUnit()->withNotes()->create();
        $unparseable = RecipeIngredientLine::factory()->unparseable()->create();

        $this->assertSame([0, 1, 2], $recipe->ingredientLines()->pluck('position')->all());
        $this->assertSame(StandardUnit::Tablespoon, $structured->standard_unit);
        $this->assertSame('handful', $custom->custom_unit);
        $this->assertSame('finely chopped', $custom->notes);
        $this->assertSame('salt to taste', $unparseable->original_text);
    }

    public function test_recipe_and_position_cannot_be_mass_assigned(): void
    {
        $recipe = Recipe::factory()->create();
        $otherRecipe = Recipe::factory()->create();
        $line = RecipeIngredientLine::factory()->for($recipe)->create();

        $line->fill([
            'recipe_id' => $otherRecipe->id,
            'position' => 99,
            'notes' => 'safe change',
        ])->save();

        $this->assertSame($recipe->id, $line->refresh()->recipe_id);
        $this->assertSame(0, $line->position);
        $this->assertSame('safe change', $line->notes);
    }
}
