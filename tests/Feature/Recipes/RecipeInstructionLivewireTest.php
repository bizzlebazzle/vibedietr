<?php

namespace Tests\Feature\Recipes;

use App\Livewire\Recipes\Instructions;
use App\Models\Recipe;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeInstructionLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_edit_and_delete_a_step(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();

        $component = Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe]);
        $component->set('instructionText', 'Heat the pan.')
            ->call('saveStep')
            ->assertHasNoErrors();

        $step = RecipeInstructionStep::sole();
        $component->call('editStep', $step->id)
            ->set('instructionText', 'Heat the pan gently.')
            ->call('saveStep')
            ->assertHasNoErrors();
        $this->assertSame('Heat the pan gently.', $step->refresh()->text);

        $component->call('deleteStep', $step->id)->assertHasNoErrors();
        $this->assertDatabaseCount('recipe_instruction_steps', 0);
    }

    public function test_exact_instruction_text_is_preserved_on_create_and_edit(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        $original = '  Fold  twice — then add ½ tsp crème fraîche!  ';
        $replacement = "  Imported-style: Don't stir; rest  10–12 min.\nServe ‘as is’.  ";

        $component = Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe]);
        $component->set('instructionText', $original)->call('saveStep')->assertHasNoErrors();
        $step = RecipeInstructionStep::sole();
        $this->assertSame($original, $step->text);

        $component->call('editStep', $step->id)
            ->set('instructionText', $replacement)
            ->call('saveStep')
            ->assertHasNoErrors();

        $this->assertSame($replacement, $step->refresh()->text);
    }

    public function test_empty_and_whitespace_only_steps_are_rejected_without_altering_input(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();

        foreach (['', " \t \n "] as $blank) {
            Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
                ->set('instructionText', $blank)
                ->call('saveStep')
                ->assertHasErrors(['instructionText'])
                ->assertSet('instructionText', $blank);
        }

        $meaningful = '  ... then wait.  ';
        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->set('instructionText', $meaningful)
            ->call('saveStep')
            ->assertHasNoErrors();

        $this->assertSame($meaningful, RecipeInstructionStep::sole()->text);
    }

    public function test_owner_can_create_edit_order_and_delete_sections(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        $component = Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe]);

        $component->set('sectionName', ' Preparation ')->call('saveSection')->assertHasNoErrors();
        $component->set('sectionName', 'Assembly')->call('saveSection')->assertHasNoErrors();
        [$preparation, $assembly] = $recipe->instructionSections()->get()->all();
        $this->assertSame('Preparation', $preparation->name);

        $component->call('editSection', $preparation->id)
            ->set('sectionName', 'Sauce')
            ->call('saveSection')
            ->assertHasNoErrors();
        $component->call('moveSectionUp', $assembly->id)->assertHasNoErrors();

        $this->assertSame([$assembly->id, $preparation->id], $recipe->fresh()->instructionSections()->pluck('id')->all());
        $this->assertSame('Sauce', $preparation->refresh()->name);

        $component->call('deleteSection', $preparation->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('recipe_instruction_sections', ['id' => $preparation->id]);
        $this->assertSame([0], $recipe->fresh()->instructionSections()->pluck('position')->all());
    }

    public function test_step_can_move_between_sectioned_and_unsectioned_states_without_text_change(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        $firstSection = RecipeInstructionSection::factory()->for($recipe)->create(['name' => 'Preparation', 'position' => 0]);
        $secondSection = RecipeInstructionSection::factory()->for($recipe)->create(['name' => 'Assembly', 'position' => 1]);
        $text = '  Keep  this wording — exactly.  ';
        $component = Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe]);

        $component->set('instructionText', $text)
            ->set('sectionId', $firstSection->id)
            ->call('saveStep')
            ->assertHasNoErrors();
        $step = RecipeInstructionStep::sole();

        $component->call('editStep', $step->id)->set('sectionId', $secondSection->id)->call('saveStep')->assertHasNoErrors();
        $this->assertSame($secondSection->id, $step->refresh()->section_id);
        $this->assertSame($text, $step->text);

        $component->call('editStep', $step->id)->set('sectionId', '')->call('saveStep')->assertHasNoErrors();
        $this->assertNull($step->refresh()->section_id);
        $this->assertSame($text, $step->text);
    }

    public function test_foreign_step_and_section_ids_are_rejected(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withInstructionSteps(2)->create();
        $otherRecipe = Recipe::factory()->for($owner, 'owner')->withInstructionSection()->withInstructionStep()->create();
        $ownSection = RecipeInstructionSection::factory()->for($recipe)->create();
        $foreignStep = $otherRecipe->instructionSteps()->sole();
        $foreignSection = $otherRecipe->instructionSections()->sole();
        $ids = $recipe->instructionSteps()->pluck('id')->all();

        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->call('editStep', $foreignStep->id)
            ->assertNotFound();
        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->call('deleteStep', $foreignStep->id)
            ->assertNotFound();
        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->call('editSection', $foreignSection->id)
            ->assertNotFound();
        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->call('deleteSection', $foreignSection->id)
            ->assertNotFound();
        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->set('instructionText', 'Cannot assign')
            ->set('sectionId', $foreignSection->id)
            ->call('saveStep')
            ->assertHasErrors(['sectionId']);
        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->call('reorderSteps', [$ids[0], $foreignStep->id])
            ->assertHasErrors(['stepIds']);
        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->call('reorderSteps', [$ids[0], $ids[0]])
            ->assertHasErrors(['stepIds']);
        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->call('reorderSections', [$foreignSection->id])
            ->assertHasErrors(['sectionIds']);
        Livewire::actingAs($owner)->test(Instructions::class, ['recipe' => $recipe])
            ->call('reorderSections', [$ownSection->id, $ownSection->id])
            ->assertHasErrors(['sectionIds']);

        $this->assertSame($ids, $recipe->fresh()->instructionSteps()->pluck('id')->all());
        $this->assertSame([$ownSection->id], $recipe->fresh()->instructionSections()->pluck('id')->all());
    }

    public function test_non_owner_and_guest_cannot_mutate_steps_or_sections(): void
    {
        $recipe = Recipe::factory()->withInstructionSection()->withInstructionStep()->create();
        $otherUser = User::factory()->create();

        Livewire::actingAs($otherUser)->test(Instructions::class, ['recipe' => $recipe])->assertForbidden();
        Livewire::test(Instructions::class, ['recipe' => $recipe])->assertForbidden();

        $this->assertDatabaseCount('recipe_instruction_steps', 1);
        $this->assertDatabaseCount('recipe_instruction_sections', 1);
    }

    public function test_multiple_sections_and_unsectioned_steps_render_with_global_order(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        $preparation = RecipeInstructionSection::factory()->for($recipe)->create(['name' => 'Preparation', 'position' => 0]);
        $assembly = RecipeInstructionSection::factory()->for($recipe)->create(['name' => 'Assembly', 'position' => 1]);
        RecipeInstructionStep::factory()->for($recipe)->inSection($preparation)->create(['text' => 'Prep first', 'position' => 0]);
        RecipeInstructionStep::factory()->for($recipe)->unsectioned()->create(['text' => 'Middle step', 'position' => 1]);
        RecipeInstructionStep::factory()->for($recipe)->inSection($assembly)->create(['text' => 'Finish last', 'position' => 2]);

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSeeInOrder(['Preparation', 'Prep first', 'Middle step', 'Assembly', 'Finish last']);
    }
}
