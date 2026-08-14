<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Livewire\Recipes\Form;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeDraftAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_and_edit_their_draft(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();

        $this->actingAs($owner)->get(route('recipes.show', $recipe))->assertOk();
        $this->actingAs($owner)->get(route('recipes.edit', $recipe))->assertOk();

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->set('title', 'Updated by owner')
            ->set('servings', '4.00')
            ->set('visibility', RecipeVisibility::Private->value)
            ->call('save')
            ->assertHasNoErrors();

        $recipe->refresh();
        $this->assertSame('Updated by owner', $recipe->title);
        $this->assertSame($owner->id, $recipe->user_id);
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
    }

    public function test_non_owner_receives_forbidden_for_direct_view_and_edit_urls(): void
    {
        $recipe = Recipe::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)->get(route('recipes.show', $recipe))->assertForbidden();
        $this->actingAs($otherUser)->get(route('recipes.edit', $recipe))->assertForbidden();
    }

    public function test_guest_is_redirected_from_view_and_edit_urls(): void
    {
        $recipe = Recipe::factory()->create();

        $this->get(route('recipes.show', $recipe))->assertRedirect(route('login'));
        $this->get(route('recipes.edit', $recipe))->assertRedirect(route('login'));
    }

    public function test_non_owner_cannot_mount_or_mutate_another_users_draft(): void
    {
        $recipe = Recipe::factory()->create(['title' => 'Owner title']);
        $otherUser = User::factory()->create();

        Livewire::actingAs($otherUser)
            ->test(Form::class, ['recipe' => $recipe])
            ->assertForbidden();

        $this->assertSame('Owner title', $recipe->refresh()->title);
    }

    public function test_forged_livewire_identifier_cannot_mutate_another_users_draft(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownersRecipe = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Owner title']);
        $otherRecipe = Recipe::factory()->for($otherUser, 'owner')->create(['title' => 'Other title']);

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $ownersRecipe])
            ->set('recipeId', $otherRecipe->id)
            ->set('title', 'Forged title')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseHas('recipes', [
            'id' => $ownersRecipe->id,
            'user_id' => $owner->id,
            'title' => 'Owner title',
        ]);
        $this->assertDatabaseHas('recipes', [
            'id' => $otherRecipe->id,
            'user_id' => $otherUser->id,
            'title' => 'Other title',
        ]);
    }

    public function test_public_intent_does_not_expose_a_draft_to_guests_or_other_users(): void
    {
        $recipe = Recipe::factory()->create([
            'lifecycle' => RecipeLifecycle::Draft,
            'visibility' => RecipeVisibility::Public,
        ]);
        $otherUser = User::factory()->create();

        $this->get(route('recipes.show', $recipe))->assertRedirect(route('login'));
        $this->actingAs($otherUser)->get(route('recipes.show', $recipe))->assertForbidden();
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
        $this->assertSame(RecipeVisibility::Public, $recipe->visibility);
    }

    public function test_update_payload_cannot_reassign_ownership_or_change_lifecycle(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();

        $recipe->update([
            'user_id' => $otherUser->id,
            'lifecycle' => 'published',
            'title' => 'Safe update',
        ]);

        $recipe->refresh();
        $this->assertSame($owner->id, $recipe->user_id);
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
        $this->assertSame('Safe update', $recipe->title);
    }
}
