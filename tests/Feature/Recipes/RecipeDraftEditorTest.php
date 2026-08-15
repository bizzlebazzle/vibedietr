<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeDraftSaveHook;
use App\Livewire\Recipes\Form;
use App\Models\Recipe;
use App\Models\RecipeInstructionStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class RecipeDraftEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_opens_one_editor_and_guest_and_non_owner_are_denied(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLine()->withInstructionStep()->create();

        $this->actingAs($owner)->get(route('recipes.edit', $recipe))
            ->assertOk()
            ->assertSee('Recipe details')
            ->assertSee('Ingredients')
            ->assertSee('Instructions');
        $this->actingAs($other)->get(route('recipes.edit', $recipe))->assertForbidden();
        auth()->logout();
        $this->get(route('recipes.edit', $recipe))->assertRedirect(route('login'));
    }

    public function test_owner_saves_metadata_and_complete_nested_state_atomically(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(2)->withInstructionSections(2)->withInstructionSteps(2)->create();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $ingredients = $component->get('ingredients');
        $sections = $component->get('sections');
        $steps = $component->get('steps');

        [$ingredients[0], $ingredients[1]] = [$ingredients[1], $ingredients[0]];
        $ingredients[0]['original_text'] = '  2 cups onions  ';
        $ingredients[] = ['key' => 'ingredient-new', 'id' => null, 'original_text' => 'salt to taste', 'quantity' => null, 'unit' => '', 'generic_wording' => '', 'notes' => ''];
        [$sections[0], $sections[1]] = [$sections[1], $sections[0]];
        $sections[0]['name'] = 'Finish';
        [$steps[0], $steps[1]] = [$steps[1], $steps[0]];
        $steps[0]['text'] = '  Keep exact wording.  ';
        $steps[0]['section_key'] = $sections[0]['key'];
        $steps[] = ['key' => 'step-new', 'id' => null, 'text' => 'Serve.', 'section_key' => null];

        $component->set('title', ' Updated draft ')
            ->set('servings', '3.50')
            ->set('ingredients', $ingredients)
            ->set('sections', $sections)
            ->set('steps', $steps)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('unsaved', false);

        $recipe->refresh();
        $this->assertSame('Updated draft', $recipe->title);
        $this->assertSame('3.50', $recipe->servings);
        $this->assertSame([0, 1, 2], $recipe->ingredientLines()->pluck('position')->all());
        $this->assertSame(['  2 cups onions  ', $ingredients[1]['original_text'], 'salt to taste'], $recipe->ingredientLines()->pluck('original_text')->all());
        $this->assertSame([0, 1], $recipe->instructionSections()->pluck('position')->all());
        $this->assertSame('Finish', $recipe->instructionSections()->first()->name);
        $this->assertSame([0, 1, 2], $recipe->instructionSteps()->pluck('position')->all());
        $this->assertSame('  Keep exact wording.  ', $recipe->instructionSteps()->first()->text);
        $this->assertSame($recipe->instructionSections()->first()->id, $recipe->instructionSteps()->first()->section_id);
    }

    public function test_add_remove_and_reorder_are_local_until_save_and_mark_unsaved(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(2)->withInstructionSteps(2)->create();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->assertSet('unsaved', false)
            ->set('title', 'Locally changed')
            ->assertSet('unsaved', true)
            ->call('moveIngredientDown', 0)
            ->call('removeIngredient', 1)
            ->call('addIngredient')
            ->call('moveStepDown', 0)
            ->call('removeStep', 1)
            ->call('addStep')
            ->assertSet('unsaved', true);

        $this->assertNotSame('Locally changed', $recipe->fresh()->title);
        $this->assertDatabaseCount('recipe_ingredient_lines', 2);
        $this->assertDatabaseCount('recipe_instruction_steps', 2);
        $this->assertCount(2, $component->get('ingredients'));
        $this->assertCount(2, $component->get('steps'));
    }

    public function test_validation_preserves_all_input_order_and_unsaved_state_then_correction_saves(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(2)->withInstructionSteps(2)->create();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $ingredients = array_reverse($component->get('ingredients'));
        $steps = array_reverse($component->get('steps'));
        $ingredients[0]['original_text'] = '  exact invalid companion  ';
        $ingredients[1]['quantity'] = '-1';
        $steps[0]['text'] = '  exact step text  ';

        $component->set('title', '   ')
            ->set('ingredients', $ingredients)
            ->set('steps', $steps)
            ->call('save')
            ->assertHasErrors(['title', 'ingredients.1.quantity'])
            ->assertSet('title', '   ')
            ->assertSet('ingredients', $ingredients)
            ->assertSet('steps', $steps)
            ->assertSet('unsaved', true);

        $this->assertSame($recipe->ingredientLines()->pluck('id')->all(), array_reverse(array_column($ingredients, 'id')));

        $ingredients[1]['quantity'] = '1.25';
        $component->set('title', 'Corrected')
            ->set('ingredients', $ingredients)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('unsaved', false);

        $this->assertSame(array_column($ingredients, 'id'), $recipe->fresh()->ingredientLines()->pluck('id')->all());
        $this->assertSame('  exact invalid companion  ', $recipe->fresh()->ingredientLines()->first()->original_text);
        $this->assertSame('  exact step text  ', $recipe->fresh()->instructionSteps()->first()->text);
    }

    public function test_removing_children_and_sections_compacts_order_and_unsections_steps(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(3)->withInstructionSections(2)->create();
        $section = $recipe->instructionSections()->first();
        RecipeInstructionStep::factory()->for($recipe)->inSection($section)->create(['position' => 0]);
        RecipeInstructionStep::factory()->for($recipe)->create(['position' => 1]);

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->call('removeIngredient', 1)
            ->call('removeSection', 0)
            ->call('removeStep', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([0, 1], $recipe->fresh()->ingredientLines()->pluck('position')->all());
        $this->assertSame([0], $recipe->fresh()->instructionSections()->pluck('position')->all());
        $this->assertSame([0], $recipe->fresh()->instructionSteps()->pluck('position')->all());
        $this->assertNull($recipe->fresh()->instructionSteps()->sole()->section_id);
    }

    public function test_forged_owner_recipe_and_nested_ids_cannot_cross_ownership_boundaries(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLine()->withInstructionSection()->withInstructionStep()->create();
        $foreign = Recipe::factory()->for($other, 'owner')->withIngredientLine()->withInstructionSection()->withInstructionStep()->create();

        try {
            Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])->set('user_id', $other->id);
            $this->fail('Owner state must not be exposed.');
        } catch (PublicPropertyNotFoundException) {
            $this->assertSame($owner->id, $recipe->fresh()->user_id);
        }

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->set('recipeId', $foreign->id)->set('title', 'Forged')->call('save')->assertForbidden();

        foreach (['ingredients' => $foreign->ingredientLines()->sole()->id, 'sections' => $foreign->instructionSections()->sole()->id, 'steps' => $foreign->instructionSteps()->sole()->id] as $property => $foreignId) {
            $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
            $state = $component->get($property);
            $state[0]['id'] = $foreignId;
            $component->set($property, $state)->call('save')->assertHasErrors([$property]);
        }

        $this->assertSame($owner->id, $recipe->fresh()->user_id);
        $this->assertSame($other->id, $foreign->fresh()->user_id);
    }

    public function test_authorization_is_rechecked_when_ownership_changes_after_mount(): void
    {
        $owner = User::factory()->create();
        $newOwner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Original']);
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])->set('title', 'Denied');
        Recipe::query()->whereKey($recipe->id)->update(['user_id' => $newOwner->id]);

        $component->call('save')->assertForbidden();
        $this->assertSame('Original', $recipe->fresh()->title);
    }

    public function test_recipe_or_child_change_after_mount_rejects_all_local_changes(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(2)->withInstructionSteps(2)->create(['title' => 'Original']);
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $ingredients = array_reverse($component->get('ingredients'));
        $component->set('title', 'Local')->set('ingredients', $ingredients);
        $recipe->instructionSteps()->first()->update(['text' => 'Changed elsewhere']);

        $component->call('save')
            ->assertHasErrors(['conflict'])
            ->assertSet('title', 'Local')
            ->assertSet('ingredients', $ingredients)
            ->assertSet('unsaved', true);

        $this->assertSame('Original', $recipe->fresh()->title);
        $this->assertNotSame(array_column($ingredients, 'id'), $recipe->fresh()->ingredientLines()->pluck('id')->all());
    }

    public function test_child_deleted_after_mount_is_not_recreated_and_stale_save_is_rejected(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(2)->create();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $deleted = $recipe->ingredientLines()->first();
        $deleted->delete();

        $component->set('title', 'Local')->call('save')->assertHasErrors(['conflict']);

        $this->assertDatabaseMissing('recipe_ingredient_lines', ['id' => $deleted->id]);
        $this->assertDatabaseCount('recipe_ingredient_lines', 1);
    }

    public function test_injected_failure_rolls_back_metadata_children_deletions_and_positions(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLines(3)->withInstructionSteps(2)->create(['title' => 'Original']);
        $originalIngredientIds = $recipe->ingredientLines()->pluck('id')->all();
        $originalStepIds = $recipe->instructionSteps()->pluck('id')->all();
        $this->app->instance(RecipeDraftSaveHook::class, new class implements RecipeDraftSaveHook
        {
            public function beforeCommit(Recipe $recipe): void
            {
                throw new RuntimeException('Deterministic test failure.');
            }
        });
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $ingredients = array_reverse($component->get('ingredients'));
        array_pop($ingredients);
        $steps = array_reverse($component->get('steps'));

        $component->set('title', 'Should roll back')
            ->set('ingredients', $ingredients)
            ->set('steps', $steps)
            ->call('save')
            ->assertHasErrors(['save'])
            ->assertSet('unsaved', true);

        $this->assertSame('Original', $recipe->fresh()->title);
        $this->assertSame($originalIngredientIds, $recipe->fresh()->ingredientLines()->pluck('id')->all());
        $this->assertSame([0, 1, 2], $recipe->fresh()->ingredientLines()->pluck('position')->all());
        $this->assertSame($originalStepIds, $recipe->fresh()->instructionSteps()->pluck('id')->all());
        $this->assertSame([0, 1], $recipe->fresh()->instructionSteps()->pluck('position')->all());
        $this->assertDatabaseCount('recipe_ingredient_lines', 3);
        $this->assertDatabaseCount('recipe_instruction_steps', 2);
    }
}
