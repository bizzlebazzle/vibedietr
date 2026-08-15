<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeInstructionWriter;
use App\Models\Recipe;
use App\Models\RecipeInstructionStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecipeInstructionOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_steps_append_last_and_global_order_survives_reload(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        $this->actingAs($owner);
        $writer = app(RecipeInstructionWriter::class);
        $first = $writer->appendStep($recipe, ' first ', null);
        $second = $writer->appendStep($recipe, 'second', null);
        $third = $writer->appendStep($recipe, 'third', null);

        $this->assertSame([0, 1, 2], [$first->position, $second->position, $third->position]);
        $this->assertSame([' first ', 'second', 'third'], $recipe->fresh()->instructionSteps()->pluck('text')->all());
    }

    public function test_steps_reorder_without_rewriting_text_or_affecting_another_recipe(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withInstructionSteps(3)->create();
        $otherRecipe = Recipe::factory()->for($owner, 'owner')->withInstructionSteps(2)->create();
        $this->actingAs($owner);
        $writer = app(RecipeInstructionWriter::class);
        [$first, $second, $third] = $recipe->instructionSteps()->get()->all();
        $originalText = $recipe->instructionSteps()->pluck('text', 'id')->all();
        $otherIds = $otherRecipe->instructionSteps()->pluck('id')->all();

        $writer->reorderSteps($recipe, [$third->id, $first->id, $second->id]);

        $this->assertSame([$third->id, $first->id, $second->id], $recipe->fresh()->instructionSteps()->pluck('id')->all());
        $this->assertSame([0, 1, 2], $recipe->fresh()->instructionSteps()->pluck('position')->all());

        foreach ($originalText as $stepId => $text) {
            $this->assertSame($text, RecipeInstructionStep::query()->findOrFail($stepId)->text);
        }

        $this->assertSame($otherIds, $otherRecipe->fresh()->instructionSteps()->pluck('id')->all());
    }

    public function test_step_reorder_rejects_foreign_missing_and_duplicate_ids(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withInstructionSteps(2)->create();
        $foreign = RecipeInstructionStep::factory()->create();
        $this->actingAs($owner);
        $writer = app(RecipeInstructionWriter::class);
        $ids = $recipe->instructionSteps()->pluck('id')->all();

        foreach ([[$ids[0], $foreign->id], [$ids[0]], [$ids[0], $ids[0]]] as $invalidOrder) {
            try {
                $writer->reorderSteps($recipe, $invalidOrder);
                $this->fail('Invalid instruction-step order should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('stepIds', $exception->errors());
            }

            $this->assertSame($ids, $recipe->fresh()->instructionSteps()->pluck('id')->all());
        }
    }

    public function test_deleting_step_compacts_only_that_recipe(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withInstructionSteps(3)->create();
        $otherRecipe = Recipe::factory()->for($owner, 'owner')->withInstructionSteps(2)->create();
        $this->actingAs($owner);
        $writer = app(RecipeInstructionWriter::class);
        $removed = $recipe->instructionSteps()->get()[1];
        $otherIds = $otherRecipe->instructionSteps()->pluck('id')->all();

        $writer->deleteStep($recipe, $removed->id);

        $this->assertSame([0, 1], $recipe->fresh()->instructionSteps()->pluck('position')->all());
        $this->assertSame($otherIds, $otherRecipe->fresh()->instructionSteps()->pluck('id')->all());
    }

    public function test_sections_append_reorder_and_delete_with_contiguous_positions(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        $this->actingAs($owner);
        $writer = app(RecipeInstructionWriter::class);
        $first = $writer->appendSection($recipe, 'Preparation');
        $second = $writer->appendSection($recipe, 'Sauce');
        $third = $writer->appendSection($recipe, 'Assembly');
        $step = $writer->appendStep($recipe, 'Whisk.', $second->id);

        $writer->reorderSections($recipe, [$third->id, $first->id, $second->id]);
        $this->assertSame([$third->id, $first->id, $second->id], $recipe->fresh()->instructionSections()->pluck('id')->all());

        $writer->deleteSection($recipe, $second->id);

        $this->assertSame([0, 1], $recipe->fresh()->instructionSections()->pluck('position')->all());
        $this->assertNull($step->refresh()->section_id);
        $this->assertSame('Whisk.', $step->text);
    }

    public function test_writer_authorizes_recipe_owner(): void
    {
        $recipe = Recipe::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);

        $this->expectException(AuthorizationException::class);
        app(RecipeInstructionWriter::class)->appendStep($recipe, 'Forbidden', null);
    }
}
