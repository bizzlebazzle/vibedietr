<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_persists_a_valid_draft_with_an_owner_and_safe_defaults(): void
    {
        $recipe = Recipe::factory()->create();

        $this->assertTrue($recipe->exists);
        $this->assertTrue($recipe->owner->exists);
        $this->assertSame($recipe->user_id, $recipe->owner->getKey());
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
        $this->assertSame(RecipeVisibility::Public, $recipe->visibility);
        $this->assertNull($recipe->servings);
    }

    public function test_explicit_owner_association_resolves_both_relationships(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();

        $this->assertTrue($recipe->owner->is($owner));
        $this->assertTrue($owner->recipes()->whereKey($recipe)->exists());
    }

    public function test_protected_identity_and_lifecycle_are_not_mass_assignable(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();

        $recipe->fill([
            'user_id' => $otherUser->id,
            'lifecycle' => 'published',
            'title' => 'Allowed title',
        ])->save();

        $recipe->refresh();
        $this->assertSame($owner->id, $recipe->user_id);
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
        $this->assertSame('Allowed title', $recipe->title);
    }
}
