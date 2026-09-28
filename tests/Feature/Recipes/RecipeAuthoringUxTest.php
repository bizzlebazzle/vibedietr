<?php

namespace Tests\Feature\Recipes;

use App\Livewire\Recipes\Form;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeInstructionStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeAuthoringUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_reorder_controls_are_named_for_each_row_and_reordering_keeps_pending_state_until_save(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(2)->withInstructionSections(2)->withInstructionSteps(2)->create();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $firstIngredientId = $component->get('ingredients')[0]['id'];
        $firstStepId = $component->get('steps')[0]['id'];

        $component->assertSee('aria-label="Move ingredient 1 down"', false)
            ->assertSee('aria-label="Move section 1 down"', false)
            ->assertSee('aria-label="Move step 1 down"', false)
            ->assertSee('min-h-11', false)
            ->assertSee('All changes saved')
            ->call('moveIngredientDown', 0)
            ->call('moveSectionDown', 0)
            ->call('moveStepDown', 0)
            ->assertSet('unsaved', true)
            ->assertSee('Unsaved changes. Save draft to keep your work.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('unsaved', false)
            ->assertDispatched('recipe-saved');

        $this->assertSame($firstIngredientId, $recipe->fresh()->ingredientLines()->orderBy('position')->get()[1]->id);
        $this->assertSame($firstStepId, $recipe->fresh()->instructionSteps()->orderBy('position')->get()[1]->id);
    }

    public function test_validation_keeps_long_input_and_exposes_feedback_and_pending_state(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        $longLine = str_repeat('unbrokenword', 40);

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->call('addIngredient')
            ->set('ingredients.0.original_text', $longLine)
            ->call('addStep')
            ->set('steps.0.text', str_repeat('instruction', 40))
            ->set('title', '')
            ->call('save')
            ->assertHasErrors('title')
            ->assertSet('ingredients.0.original_text', $longLine)
            ->assertSet('unsaved', true)
            ->assertSee('data-validation-summary', false)
            ->assertSee('data-feedback-error', false)
            ->assertSee('role="status"', false)
            ->assertSee('recipe-content', false)
            ->assertSee('x-on:input.capture', false);
    }

    public function test_long_original_content_and_resize_error_render_in_reflow_container_with_field_feedback(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create(['servings' => '4']);
        $line = str_repeat('ingredientwithoutspaces', 30);
        $step = str_repeat('instructionwithoutspaces', 30);
        RecipeIngredientLine::factory()->for($recipe)->create(['original_text' => $line]);
        RecipeInstructionStep::factory()->for($recipe)->create(['text' => $step]);

        $this->actingAs($owner)->get(route('recipes.show', ['recipe' => $recipe, 'servings' => '0']))
            ->assertOk()
            ->assertSee($line)
            ->assertSee($step)
            ->assertSee('class="recipe-content min-w-0', false)
            ->assertSee('aria-invalid="true" aria-describedby="display-servings-error"', false)
            ->assertSee('id="display-servings-error" role="alert"', false)
            ->assertSeeText('Requested servings must be greater than zero.');

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertDontSee('aria-describedby="display-servings-error"', false)
            ->assertSeeText('Quantities are adjusted for display only. The saved recipe remains unchanged.');
    }
}
