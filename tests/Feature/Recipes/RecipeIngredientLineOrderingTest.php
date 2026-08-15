<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeIngredientLineWriter;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecipeIngredientLineOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_append_is_predictable_and_order_survives_reload(): void
    {
        $recipe = Recipe::factory()->create();
        $writer = app(RecipeIngredientLineWriter::class);
        $first = $writer->append($recipe, ['original_text' => 'first']);
        $second = $writer->append($recipe, ['original_text' => 'second']);
        $third = $writer->append($recipe, ['original_text' => 'third']);

        $this->assertSame([0, 1, 2], [$first->position, $second->position, $third->position]);
        $this->assertSame(
            ['first', 'second', 'third'],
            $recipe->fresh()->ingredientLines()->pluck('original_text')->all()
        );
    }

    public function test_lines_can_be_reordered_atomically_with_contiguous_positions(): void
    {
        $recipe = Recipe::factory()->withIngredientLines(3)->create();
        $writer = app(RecipeIngredientLineWriter::class);
        [$first, $second, $third] = $recipe->ingredientLines()->get()->all();

        $writer->reorder($recipe, [$third->id, $first->id, $second->id]);

        $this->assertSame(
            [$third->id, $first->id, $second->id],
            $recipe->fresh()->ingredientLines()->pluck('id')->all()
        );
        $this->assertSame([0, 1, 2], $recipe->fresh()->ingredientLines()->pluck('position')->all());
    }

    public function test_removing_a_line_compacts_only_that_recipes_positions(): void
    {
        $recipeA = Recipe::factory()->withIngredientLines(3)->create();
        $recipeB = Recipe::factory()->withIngredientLines(2)->create();
        $writer = app(RecipeIngredientLineWriter::class);
        $removed = $recipeA->ingredientLines()->get()[1];
        $recipeBIds = $recipeB->ingredientLines()->pluck('id')->all();

        $writer->delete($recipeA, $removed->id);

        $this->assertSame([0, 1], $recipeA->fresh()->ingredientLines()->pluck('position')->all());
        $this->assertSame($recipeBIds, $recipeB->fresh()->ingredientLines()->pluck('id')->all());
        $this->assertSame([0, 1], $recipeB->fresh()->ingredientLines()->pluck('position')->all());
    }

    public function test_reorder_rejects_foreign_missing_and_duplicate_identifiers_without_mutation(): void
    {
        $recipe = Recipe::factory()->withIngredientLines(2)->create();
        $otherLine = RecipeIngredientLine::factory()->create();
        $writer = app(RecipeIngredientLineWriter::class);
        $ids = $recipe->ingredientLines()->pluck('id')->all();

        foreach ([[$ids[0], $otherLine->id], [$ids[0]], [$ids[0], $ids[0]]] as $invalidOrder) {
            try {
                $writer->reorder($recipe, $invalidOrder);
                $this->fail('Invalid recipe-line order should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('lineIds', $exception->errors());
            }

            $this->assertSame($ids, $recipe->fresh()->ingredientLines()->pluck('id')->all());
        }
    }
}
