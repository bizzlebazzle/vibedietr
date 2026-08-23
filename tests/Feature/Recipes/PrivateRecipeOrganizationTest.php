<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\PublicRecipe;
use App\Domain\Recipes\PublicRecipeSummary;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\Bookmark;
use App\Models\PrivateRecipeTag;
use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PrivateRecipeOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_crud_validates_names_and_never_accepts_submitted_ownership(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post(route('recipe-collections.store'), [
            'name' => '  Weeknight meals  ',
        ])->assertRedirect();
        $collection = RecipeCollection::query()->sole();
        $this->assertSame('Weeknight meals', $collection->name);
        $this->assertSame('weeknight meals', $collection->normalized_name);
        $this->assertSame($user->id, $collection->user_id);

        $this->patch(route('recipe-collections.update', $collection), [
            'name' => 'Fast meals',
        ])->assertRedirect(route('recipe-collections.show', $collection));
        $this->assertSame('Fast meals', $collection->fresh()->name);

        $this->post(route('recipe-collections.store'), ['name' => '   '])
            ->assertSessionHasErrors('name');
        $this->post(route('recipe-collections.store'), [
            'name' => 'Stolen', 'user_id' => $other->id,
        ])->assertSessionHasErrors('user_id');
        $this->assertDatabaseCount('recipe_collections', 1);

        $this->delete(route('recipe-collections.destroy', $collection))
            ->assertRedirect(route('recipe-collections.index'));
        $this->assertDatabaseMissing('recipe_collections', ['id' => $collection->id]);
    }

    public function test_collection_management_is_authenticated_and_owner_only_without_enumeration(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $collection = RecipeCollection::factory()->for($owner, 'owner')->create(['name' => 'Owner secret']);

        $this->get(route('recipe-collections.index'))->assertRedirect(route('login'));
        $this->get(route('recipe-collections.show', $collection))->assertRedirect(route('login'));
        $this->post(route('recipe-collections.store'), ['name' => 'Guest'])->assertRedirect(route('login'));

        $this->actingAs($other)->get(route('recipe-collections.index'))
            ->assertOk()->assertDontSee('Owner secret');
        $this->get(route('recipe-collections.show', $collection))->assertNotFound();
        $this->patch(route('recipe-collections.update', $collection), ['name' => 'Forged'])->assertNotFound();
        $this->delete(route('recipe-collections.destroy', $collection))->assertNotFound();
        $this->assertFalse(Gate::forUser($other)->allows('view', $collection));
        $this->assertFalse(Gate::forUser($other)->allows('update', $collection));
        $this->assertFalse(Gate::forUser($other)->allows('delete', $collection));
        $this->assertSame('Owner secret', $collection->fresh()->name);
    }

    public function test_private_tag_crud_is_owner_only_and_names_are_unique_only_within_one_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post(route('private-recipe-tags.store'), ['name' => ' Try Soon '])
            ->assertRedirect();
        $tag = PrivateRecipeTag::query()->sole();
        $this->patch(route('private-recipe-tags.update', $tag), ['name' => 'Meal prep'])
            ->assertRedirect(route('private-recipe-tags.show', $tag));
        $this->post(route('private-recipe-tags.store'), ['name' => 'MEAL PREP'])
            ->assertSessionHasErrors('name');

        $otherTag = PrivateRecipeTag::factory()->for($other, 'owner')->create(['name' => 'Meal prep']);
        PrivateRecipeTag::factory()->for($other, 'owner')->create(['name' => 'Other user secret tag']);
        $this->get(route('private-recipe-tags.index'))->assertOk()
            ->assertDontSee('Other user secret tag')->assertDontSee($other->email);
        $this->get(route('private-recipe-tags.show', $otherTag))->assertNotFound();
        $this->patch(route('private-recipe-tags.update', $otherTag), ['name' => 'Forged'])->assertNotFound();
        $this->delete(route('private-recipe-tags.destroy', $otherTag))->assertNotFound();
        $this->assertFalse(Gate::forUser($user)->allows('view', $otherTag));

        $this->delete(route('private-recipe-tags.destroy', $tag))->assertRedirect();
        $this->assertDatabaseHas('private_recipe_tags', ['id' => $otherTag->id, 'name' => 'Meal prep']);
        $this->assertDatabaseMissing('private_recipe_tags', ['id' => $tag->id]);
    }

    public function test_private_tag_management_requires_authentication(): void
    {
        $tag = PrivateRecipeTag::factory()->create();
        $this->get(route('private-recipe-tags.index'))->assertRedirect(route('login'));
        $this->get(route('private-recipe-tags.show', $tag))->assertRedirect(route('login'));
        $this->post(route('private-recipe-tags.store'), ['name' => 'Guest'])->assertRedirect(route('login'));
        $this->patch(route('private-recipe-tags.update', $tag), ['name' => 'Guest'])->assertRedirect(route('login'));
        $this->delete(route('private-recipe-tags.destroy', $tag))->assertRedirect(route('login'));
    }

    public function test_owned_recipe_collection_membership_is_idempotent_owner_scoped_and_removable(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $collection = RecipeCollection::factory()->for($user, 'owner')->create();
        $owned = Recipe::factory()->for($user, 'owner')->create(['title' => 'My private draft']);
        $foreign = Recipe::factory()->for($other, 'owner')->finalizedPublic()->create(['title' => 'Foreign public recipe']);

        $this->actingAs($user)->post(route('recipe-collections.recipes.store', $collection), [
            'recipe_id' => $owned->id,
        ])->assertRedirect();
        $this->post(route('recipe-collections.recipes.store', $collection), [
            'recipe_id' => $owned->id,
        ])->assertRedirect();
        $this->assertDatabaseCount('recipe_collection_recipes', 1);
        $this->get(route('recipe-collections.show', $collection))
            ->assertOk()->assertSee('My private draft')->assertDontSee('Foreign public recipe');

        $this->post(route('recipe-collections.recipes.store', $collection), [
            'recipe_id' => $foreign->id,
        ])->assertNotFound();
        $this->post(route('recipe-collections.recipes.store', $collection), [
            'recipe_id' => $owned->id, 'user_id' => $other->id,
        ])->assertSessionHasErrors('user_id');
        $this->assertDatabaseCount('recipe_collection_recipes', 1);

        $this->delete(route('recipe-collections.recipes.destroy', [$collection, $owned]))
            ->assertRedirect();
        $this->assertDatabaseCount('recipe_collection_recipes', 0);
    }

    public function test_owned_recipe_private_tag_membership_filters_only_owner_items_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tag = PrivateRecipeTag::factory()->for($user, 'owner')->create(['name' => 'Christmas']);
        $owned = Recipe::factory()->for($user, 'owner')->create(['title' => 'My festive draft']);
        $foreign = Recipe::factory()->for($other, 'owner')->finalizedPublic()->create(['title' => 'Foreign festive recipe']);

        $this->actingAs($user)->post(route('private-recipe-tags.recipes.store', $tag), ['recipe_id' => $owned->id]);
        $this->post(route('private-recipe-tags.recipes.store', $tag), ['recipe_id' => $owned->id]);
        $this->assertDatabaseCount('private_recipe_tag_recipes', 1);
        $this->get(route('private-recipe-tags.show', $tag))
            ->assertOk()->assertSee('My festive draft')->assertDontSee('Foreign festive recipe');
        $this->post(route('private-recipe-tags.recipes.store', $tag), ['recipe_id' => $foreign->id])
            ->assertNotFound();
        $this->delete(route('private-recipe-tags.recipes.destroy', [$tag, $owned]))->assertRedirect();
        $this->assertDatabaseCount('private_recipe_tag_recipes', 0);
    }

    public function test_bookmark_memberships_are_owner_scoped_idempotent_and_removable(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $collection = RecipeCollection::factory()->for($user, 'owner')->create();
        $tag = PrivateRecipeTag::factory()->for($user, 'owner')->create();
        $bookmark = Bookmark::factory()->for($user, 'owner')->create();
        $foreign = Bookmark::factory()->for($other, 'owner')->create();

        $this->actingAs($user)->post(route('recipe-collections.bookmarks.store', $collection), ['bookmark_id' => $bookmark->id]);
        $this->post(route('recipe-collections.bookmarks.store', $collection), ['bookmark_id' => $bookmark->id]);
        $this->post(route('private-recipe-tags.bookmarks.store', $tag), ['bookmark_id' => $bookmark->id]);
        $this->post(route('private-recipe-tags.bookmarks.store', $tag), ['bookmark_id' => $bookmark->id]);
        $this->assertDatabaseCount('recipe_collection_bookmarks', 1);
        $this->assertDatabaseCount('private_recipe_tag_bookmarks', 1);

        $this->post(route('recipe-collections.bookmarks.store', $collection), ['bookmark_id' => $foreign->id])
            ->assertNotFound();
        $this->post(route('private-recipe-tags.bookmarks.store', $tag), ['bookmark_id' => $foreign->id])
            ->assertNotFound();
        $this->assertDatabaseCount('recipe_collection_bookmarks', 1);
        $this->assertDatabaseCount('private_recipe_tag_bookmarks', 1);

        $this->delete(route('recipe-collections.bookmarks.destroy', [$collection, $bookmark]))->assertRedirect();
        $this->delete(route('private-recipe-tags.bookmarks.destroy', [$tag, $bookmark]))->assertRedirect();
        $this->assertDatabaseCount('recipe_collection_bookmarks', 0);
        $this->assertDatabaseCount('private_recipe_tag_bookmarks', 0);
    }

    public function test_other_user_cannot_enumerate_memberships_through_crafted_organization_ids(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Membership secret']);
        $bookmark = Bookmark::factory()->for($owner, 'owner')->create();
        $collection = RecipeCollection::factory()->for($owner, 'owner')->withOwnedRecipe($recipe)->withBookmark($bookmark)->create();
        $tag = PrivateRecipeTag::factory()->for($owner, 'owner')->withOwnedRecipe($recipe)->withBookmark($bookmark)->create();

        $this->actingAs($other)->get(route('recipe-collections.show', $collection))
            ->assertNotFound()->assertDontSee('Membership secret');
        $this->get(route('private-recipe-tags.show', $tag))
            ->assertNotFound()->assertDontSee('Membership secret');
        $this->delete(route('recipe-collections.recipes.destroy', [$collection, $recipe]))->assertNotFound();
        $this->delete(route('private-recipe-tags.bookmarks.destroy', [$tag, $bookmark]))->assertNotFound();
        $this->assertDatabaseCount('recipe_collection_recipes', 1);
        $this->assertDatabaseCount('private_recipe_tag_bookmarks', 1);
    }

    public function test_deleting_organization_removes_only_its_memberships(): void
    {
        $user = User::factory()->create();
        $recipe = Recipe::factory()->for($user, 'owner')->create();
        $bookmark = Bookmark::factory()->for($user, 'owner')->create();
        $collection = RecipeCollection::factory()->for($user, 'owner')->withOwnedRecipe($recipe)->withBookmark($bookmark)->create();
        $tag = PrivateRecipeTag::factory()->for($user, 'owner')->withOwnedRecipe($recipe)->withBookmark($bookmark)->create();

        $this->actingAs($user)->delete(route('recipe-collections.destroy', $collection));
        $this->assertDatabaseMissing('recipe_collection_recipes', ['recipe_collection_id' => $collection->id]);
        $this->assertDatabaseMissing('recipe_collection_bookmarks', ['recipe_collection_id' => $collection->id]);
        $this->assertDatabaseHas('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseHas('bookmarks', ['id' => $bookmark->id]);

        $this->delete(route('private-recipe-tags.destroy', $tag));
        $this->assertDatabaseMissing('private_recipe_tag_recipes', ['private_recipe_tag_id' => $tag->id]);
        $this->assertDatabaseMissing('private_recipe_tag_bookmarks', ['private_recipe_tag_id' => $tag->id]);
        $this->assertDatabaseHas('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseHas('bookmarks', ['id' => $bookmark->id]);
    }

    public function test_deleting_recipe_or_bookmark_cleans_direct_memberships_without_deleting_organization(): void
    {
        $user = User::factory()->create();
        $recipe = Recipe::factory()->for($user, 'owner')->create();
        $bookmark = Bookmark::factory()->for($user, 'owner')->create();
        $collection = RecipeCollection::factory()->for($user, 'owner')->withOwnedRecipe($recipe)->withBookmark($bookmark)->create();
        $tag = PrivateRecipeTag::factory()->for($user, 'owner')->withOwnedRecipe($recipe)->withBookmark($bookmark)->create();

        $recipe->delete();
        $bookmark->delete();

        $this->assertDatabaseCount('recipe_collection_recipes', 0);
        $this->assertDatabaseCount('recipe_collection_bookmarks', 0);
        $this->assertDatabaseCount('private_recipe_tag_recipes', 0);
        $this->assertDatabaseCount('private_recipe_tag_bookmarks', 0);
        $this->assertDatabaseHas('recipe_collections', ['id' => $collection->id]);
        $this->assertDatabaseHas('private_recipe_tags', ['id' => $tag->id]);
    }

    public function test_tombstoned_bookmark_remains_safe_inside_collection_and_can_be_removed(): void
    {
        $creator = User::factory()->create(['email' => 'private-source@example.test']);
        $user = User::factory()->create();
        $source = Recipe::factory()->for($creator, 'owner')->finalizedPublic()->create();
        $version = $source->currentVersion()->sole();
        $bookmark = Bookmark::factory()->for($user, 'owner')->forRecipe($source)->create();
        $collection = RecipeCollection::factory()->for($user, 'owner')->withBookmark($bookmark)->create();

        $this->actingAs($user)->get(route('recipe-collections.show', $collection))
            ->assertOk()->assertSee((string) $version->snapshot['title']);

        $secretTitle = 'Private source content must not leak';
        $source->forceFill(['visibility' => RecipeVisibility::Private])->save();
        RecipeVersion::withoutEvents(function () use ($version, $secretTitle): void {
            $version->forceFill(['snapshot' => [...$version->snapshot, 'title' => $secretTitle]])->save();
        });

        $this->get(route('recipe-collections.show', $collection))
            ->assertOk()->assertSee('Recipe unavailable')
            ->assertSee('This recipe is no longer publicly available.')
            ->assertDontSee($secretTitle)
            ->assertDontSee('private-source@example.test');
        $this->assertDatabaseHas('recipe_collection_bookmarks', ['bookmark_id' => $bookmark->id]);

        $this->delete(route('recipe-collections.bookmarks.destroy', [$collection, $bookmark]))->assertRedirect();
        $this->assertDatabaseMissing('recipe_collection_bookmarks', ['bookmark_id' => $bookmark->id]);
        $this->assertDatabaseHas('bookmarks', ['id' => $bookmark->id]);
    }

    public function test_public_recipe_and_discovery_projections_exclude_private_organization_and_counts(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();
        $collection = RecipeCollection::factory()->for($owner, 'owner')->withOwnedRecipe($recipe)->create([
            'name' => 'Never public collection',
        ]);
        $tag = PrivateRecipeTag::factory()->for($owner, 'owner')->withOwnedRecipe($recipe)->create([
            'name' => 'Never public private tag',
        ]);
        $recipe = $recipe->fresh()->load('currentVersion');

        $public = PublicRecipe::fromCurrentVersion($recipe)->toArray();
        $summary = PublicRecipeSummary::fromCurrentVersion($recipe)->toArray();
        $this->assertSame(
            ['id', 'title', 'servings', 'visibility', 'version', 'ingredients', 'instructions'],
            array_keys($public),
        );
        $this->assertSame(['id', 'title', 'servings', 'finalized_at'], array_keys($summary));
        $json = json_encode([$public, $summary], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($collection->name, $json);
        $this->assertStringNotContainsString($tag->name, $json);
        $this->assertStringNotContainsString('collection', $json);
        $this->assertStringNotContainsString('private_tag', $json);

        $this->get(route('recipes.show', $recipe))->assertOk()
            ->assertDontSee($collection->name)->assertDontSee($tag->name);
        $this->get(route('recipes.index'))->assertOk()
            ->assertDontSee($collection->name)->assertDontSee($tag->name);
        $this->assertArrayNotHasKey('recipeCollections', $recipe->toArray());
        $this->assertArrayNotHasKey('privateRecipeTags', $recipe->toArray());
    }

    public function test_factories_create_owned_memberships_and_unavailable_bookmark_state(): void
    {
        $user = User::factory()->create();
        $collection = RecipeCollection::factory()->for($user, 'owner')->withOwnedRecipe()->withBookmark()->create();
        $tag = PrivateRecipeTag::factory()->for($user, 'owner')->withOwnedRecipe()->withBookmark()->create();
        $unavailable = Bookmark::factory()->for($user, 'owner')->whoseRecipeIsDeleted()->create();
        $tombstoneCollection = RecipeCollection::factory()->for($user, 'owner')->withBookmark($unavailable)->create();

        $this->assertSame(1, $collection->recipes()->count());
        $this->assertSame(1, $collection->bookmarks()->count());
        $this->assertSame(1, $tag->recipes()->count());
        $this->assertSame(1, $tag->bookmarks()->count());
        $this->actingAs($user)->get(route('recipe-collections.show', $tombstoneCollection))
            ->assertOk()->assertSee('Recipe unavailable');
    }
}
