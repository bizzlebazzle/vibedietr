<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Livewire\Recipes\Form;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeDraftCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_trimmed_private_draft_with_decimal_servings(): void
    {
        $owner = User::factory()->create();

        Livewire::actingAs($owner)->test('recipes.form')
            ->set('title', '  Weeknight curry  ')
            ->set('servings', '2.50')
            ->set('visibility', RecipeVisibility::Private->value)
            ->call('save')
            ->assertHasNoErrors();

        $recipe = Recipe::sole();
        $this->assertSame($owner->id, $recipe->user_id);
        $this->assertSame('Weeknight curry', $recipe->title);
        $this->assertSame('2.50', $recipe->servings);
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
        $this->assertSame(RecipeVisibility::Private, $recipe->visibility);
    }

    public function test_missing_servings_is_valid_for_a_draft(): void
    {
        $owner = User::factory()->create();

        Livewire::actingAs($owner)->test(Form::class)
            ->set('title', 'No yield yet')
            ->set('servings', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Recipe::sole()->servings);
    }

    public function test_title_is_required_and_bounded(): void
    {
        $owner = User::factory()->create();

        Livewire::actingAs($owner)->test(Form::class)
            ->set('title', '   ')
            ->call('save')
            ->assertHasErrors(['title' => 'required']);

        Livewire::actingAs($owner)->test(Form::class)
            ->set('title', str_repeat('a', 256))
            ->call('save')
            ->assertHasErrors(['title' => 'max']);

        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_zero_negative_and_over_precision_servings_are_rejected(): void
    {
        $owner = User::factory()->create();

        foreach (['0', '-1', '1.234'] as $invalidServings) {
            Livewire::actingAs($owner)->test(Form::class)
                ->set('title', 'Invalid yield')
                ->set('servings', $invalidServings)
                ->call('save')
                ->assertHasErrors(['servings']);
        }

        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_visibility_must_be_an_approved_recipe_preference(): void
    {
        $owner = User::factory()->create();

        Livewire::actingAs($owner)->test(Form::class)
            ->set('title', 'Invalid visibility')
            ->set('visibility', 'shared')
            ->call('save')
            ->assertHasErrors(['visibility']);

        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_guest_cannot_create_a_draft(): void
    {
        $this->get(route('recipes.create'))->assertRedirect(route('login'));

        Livewire::test(Form::class)
            ->assertForbidden();

        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_submitted_ownership_cannot_create_for_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        try {
            Livewire::actingAs($owner)->test(Form::class)
                ->set('user_id', $otherUser->id);
            $this->fail('Ownership must not be public Livewire state.');
        } catch (PublicPropertyNotFoundException) {
            $this->assertDatabaseCount('recipes', 0);
        }
    }
}
