<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeVisibility;
use App\Models\Bookmark;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookmarkFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_supports_public_private_deleted_and_newer_version_sources(): void
    {
        $public = Bookmark::factory()->toCurrentPublicRecipe()->create();
        $private = Bookmark::factory()->whoseRecipeIsPrivate()->create();
        $deleted = Bookmark::factory()->whoseRecipeIsDeleted()->create();
        $newer = Bookmark::factory()->whoseRecipeHasNewerFinalizedVersion()->create();

        $this->assertTrue(Recipe::query()->findOrFail($public->recipe_id)->isPubliclyViewable());
        $this->assertSame(
            RecipeVisibility::Private,
            Recipe::query()->findOrFail($private->recipe_id)->visibility,
        );
        $this->assertFalse(Recipe::query()->whereKey($deleted->recipe_id)->exists());
        $this->assertSame(2, Recipe::query()->findOrFail($newer->recipe_id)->currentVersion()->sole()->version_number);
        $this->assertDatabaseHas('bookmarks', ['id' => $deleted->id]);
    }

    public function test_multiple_users_can_bookmark_different_recipes_with_explicit_ownership(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstRecipe = Recipe::factory()->finalizedPublic()->create();
        $secondRecipe = Recipe::factory()->finalizedPublic()->create();

        Bookmark::factory()->for($firstUser, 'owner')->forRecipe($firstRecipe)->create();
        Bookmark::factory()->for($secondUser, 'owner')->forRecipe($secondRecipe)->create();

        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $firstUser->id,
            'recipe_id' => $firstRecipe->id,
        ]);
        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $secondUser->id,
            'recipe_id' => $secondRecipe->id,
        ]);
    }
}
