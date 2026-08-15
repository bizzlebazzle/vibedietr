<?php

namespace Tests\Feature\Recipes;

use App\Models\Recipe;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeInstructionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_instruction_text_variants_round_trip_exactly(): void
    {
        $samples = [
            'Stir until smooth.',
            '  Keep the leading space.',
            'Keep the trailing space.  ',
            'Fold  very   gently.',
            'Add ½ tsp crème fraîche — don’t boil!',
            "1. Heat oven to 180°C.\n2. Do not over-mix (seriously!).",
        ];

        foreach ($samples as $text) {
            $step = RecipeInstructionStep::factory()->create(['text' => $text]);

            $this->assertSame($text, $step->refresh()->text);
        }
    }

    public function test_recipe_may_have_no_sections_and_steps_may_be_unsectioned(): void
    {
        $recipe = Recipe::factory()->withInstructionSteps(2)->create();

        $this->assertCount(0, $recipe->instructionSections);
        $this->assertSame([null, null], $recipe->instructionSteps()->pluck('section_id')->all());
        $this->assertSame([0, 1], $recipe->instructionSteps()->pluck('position')->all());
    }

    public function test_sectioned_and_unsectioned_relationships_are_recipe_scoped(): void
    {
        $recipe = Recipe::factory()->create();
        $section = RecipeInstructionSection::factory()->for($recipe)->create(['name' => 'Sauce']);
        $sectioned = RecipeInstructionStep::factory()->for($recipe)->inSection($section)->create(['text' => 'Whisk.', 'position' => 0]);
        $unsectioned = RecipeInstructionStep::factory()->for($recipe)->unsectioned()->create(['text' => 'Serve.', 'position' => 1]);

        $this->assertTrue($section->recipe->is($recipe));
        $this->assertTrue($sectioned->section?->is($section));
        $this->assertNull($unsectioned->section);
        $this->assertSame([$sectioned->id], $section->steps()->pluck('id')->all());
    }

    public function test_recipe_and_internal_order_cannot_be_mass_assigned(): void
    {
        $recipe = Recipe::factory()->create();
        $otherRecipe = Recipe::factory()->create();
        $section = RecipeInstructionSection::factory()->for($recipe)->create();
        $foreignSection = RecipeInstructionSection::factory()->for($otherRecipe)->create();
        $step = RecipeInstructionStep::factory()->for($recipe)->inSection($section)->create();

        $step->fill([
            'recipe_id' => $otherRecipe->id,
            'section_id' => $foreignSection->id,
            'position' => 99,
            'text' => 'New exact text',
            'id' => 999,
        ])->save();
        $section->fill(['recipe_id' => $otherRecipe->id, 'position' => 99, 'id' => 998, 'name' => 'Renamed'])->save();

        $this->assertSame($recipe->id, $step->refresh()->recipe_id);
        $this->assertSame($section->id, $step->section_id);
        $this->assertSame(0, $step->position);
        $this->assertSame('New exact text', $step->text);
        $this->assertSame($recipe->id, $section->refresh()->recipe_id);
        $this->assertSame(0, $section->position);
        $this->assertSame('Renamed', $section->name);
    }

    public function test_deleting_section_keeps_steps_and_recipe_deletion_cascades(): void
    {
        $recipe = Recipe::factory()->create();
        $section = RecipeInstructionSection::factory()->for($recipe)->create();
        $step = RecipeInstructionStep::factory()->for($recipe)->inSection($section)->create();

        $section->delete();

        $this->assertNull($step->refresh()->section_id);

        $recipe->delete();

        $this->assertDatabaseMissing('recipe_instruction_steps', ['id' => $step->id]);
    }

    public function test_factories_cover_steps_sections_and_mixed_state(): void
    {
        $recipe = Recipe::factory()->withInstructionSections(2)->withInstructionSteps(3)->create();
        $section = $recipe->instructionSections()->firstOrFail();
        $sectioned = RecipeInstructionStep::factory()->for($recipe)->inSection($section)->create(['position' => 3]);

        $this->assertSame([0, 1], $recipe->instructionSections()->pluck('position')->all());
        $this->assertSame([0, 1, 2, 3], $recipe->instructionSteps()->pluck('position')->all());
        $this->assertSame($section->id, $sectioned->section_id);
        $this->assertTrue($recipe->instructionSteps()->whereNull('section_id')->exists());
    }
}
