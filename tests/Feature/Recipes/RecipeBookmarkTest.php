<?php

namespace Tests\Feature\Recipes;

use App\Domain\Bookmarks\BookmarkCreator;
use App\Domain\Bookmarks\BookmarkListing;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeRevisionManager;
use App\Domain\Recipes\RecipeVisibility;
use App\Livewire\Recipes\Form;
use App\Models\Bookmark;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeBookmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_idempotently_bookmark_public_durable_recipe(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        $versionId = $recipe->current_recipe_version_id;

        $first = $this->actingAs($user)->post(route('bookmarks.store', $recipe), [
            'user_id' => $owner->id,
            'recipe_version_id' => $versionId,
        ]);
        $second = $this->post(route('bookmarks.store', $recipe));

        $first->assertRedirect(route('recipes.show', $recipe));
        $second->assertRedirect(route('recipes.show', $recipe));
        $this->assertDatabaseCount('bookmarks', 1);
        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $user->id,
            'recipe_id' => $recipe->id,
        ]);
        $this->assertDatabaseMissing('bookmarks', ['user_id' => $owner->id]);
        $this->assertNotSame((string) $versionId, (string) Bookmark::query()->sole()->recipe_id);
        $this->assertSame(
            ['id', 'user_id', 'recipe_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('bookmarks'),
        );
    }

    public function test_database_uniqueness_is_the_concurrent_duplicate_boundary(): void
    {
        $user = User::factory()->create();
        $recipe = $this->finalizedRecipe(User::factory()->create());
        $creator = app(BookmarkCreator::class);

        $first = $creator->create($recipe->id, $user);
        $second = $creator->create($recipe->id, $user);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('bookmarks', 1);

        try {
            Bookmark::query()->forceCreate([
                'user_id' => $user->id,
                'recipe_id' => $recipe->id,
            ]);
            $this->fail('The bookmark uniqueness constraint accepted a duplicate.');
        } catch (QueryException) {
            $this->assertDatabaseCount('bookmarks', 1);
        }
    }

    public function test_only_current_public_finalized_durable_recipes_can_be_bookmarked(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $draft = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Secret draft',
            'visibility' => RecipeVisibility::Public,
        ]);
        $private = $this->finalizedRecipe($owner, RecipeVisibility::Private, 'Private stable title');
        $public = $this->finalizedRecipe($owner);
        $historical = $public->currentVersion()->sole();
        $public->forceFill(['current_recipe_version_id' => null])->save();

        foreach ([$draft, $private, $public] as $ineligible) {
            $this->actingAs($user)
                ->post(route('bookmarks.store', $ineligible))
                ->assertNotFound();
        }

        $this->post('/recipes/'.$historical->id.'/bookmark')->assertNotFound();
        $this->assertDatabaseCount('bookmarks', 0);
    }

    public function test_public_recipe_owner_may_bookmark_their_own_recipe(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);

        $this->actingAs($owner)
            ->post(route('bookmarks.store', $recipe))
            ->assertRedirect(route('recipes.show', $recipe));

        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $owner->id,
            'recipe_id' => $recipe->id,
        ]);
    }

    public function test_remove_is_owner_only_idempotent_and_never_changes_recipe(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe(User::factory()->create());
        $bookmark = Bookmark::factory()->for($owner, 'owner')->forRecipe($recipe)->create();
        $versionId = $recipe->current_recipe_version_id;

        $nonOwnerResponse = $this->actingAs($other)->delete(route('bookmarks.destroy', $bookmark));
        $nonOwnerResponse->assertRedirect();
        $this->assertDatabaseHas('bookmarks', ['id' => $bookmark->id]);

        $this->actingAs($owner)->delete(route('bookmarks.destroy', $bookmark))->assertRedirect();
        $this->delete(route('bookmarks.destroy', $bookmark))->assertRedirect();

        $this->assertDatabaseMissing('bookmarks', ['id' => $bookmark->id]);
        $this->assertDatabaseHas('recipes', [
            'id' => $recipe->id,
            'current_recipe_version_id' => $versionId,
        ]);
        $this->assertSame(1, $recipe->versions()->count());
    }

    public function test_bookmark_routes_are_authenticated_and_direct_guessing_is_non_disclosing(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe(User::factory()->create());
        $bookmark = Bookmark::factory()->for($owner, 'owner')->forRecipe($recipe)->create();

        $this->get(route('bookmarks.index'))->assertRedirect(route('login'));
        $this->post(route('bookmarks.store', $recipe))->assertRedirect(route('login'));
        $this->delete(route('bookmarks.destroy', $bookmark))->assertRedirect(route('login'));

        $guessed = $this->actingAs($other)->delete(route('bookmarks.destroy', $bookmark));
        $missing = $this->delete(route('bookmarks.destroy', 999999));

        $this->assertSame($missing->getStatusCode(), $guessed->getStatusCode());
        $this->assertSame($missing->headers->get('Location'), $guessed->headers->get('Location'));
        $this->assertDatabaseHas('bookmarks', ['id' => $bookmark->id]);
    }

    public function test_list_is_owner_only_paginated_deterministic_and_excludes_other_users_choices(): void
    {
        $user = User::factory()->create(['email' => 'bookmark-owner@example.test']);
        $other = User::factory()->create(['email' => 'other-owner@example.test']);
        $firstRecipe = $this->finalizedRecipe(User::factory()->create(), title: 'First public saved title');
        $secondRecipe = $this->finalizedRecipe(User::factory()->create(), title: 'Second public saved title');
        $otherRecipe = $this->finalizedRecipe(User::factory()->create(), title: 'Other user private choice');
        $first = Bookmark::factory()->for($user, 'owner')->forRecipe($firstRecipe)->create([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $second = Bookmark::factory()->for($user, 'owner')->forRecipe($secondRecipe)->create();
        Bookmark::factory()->for($other, 'owner')->forRecipe($otherRecipe)->create();

        $response = $this->actingAs($user)->get(route('bookmarks.index'));

        $response->assertOk()
            ->assertSeeInOrder(['Second public saved title', 'First public saved title'])
            ->assertDontSee('Other user private choice')
            ->assertDontSee('other-owner@example.test');
        $items = collect($response->viewData('bookmarks')->items());
        $this->assertSame([$second->id, $first->id], $items->pluck('id')->all());
        $this->assertSame(12, $response->viewData('bookmarks')->perPage());
    }

    public function test_bookmark_follows_real_revision_publication_and_active_draft_never_leaks(): void
    {
        $creator = User::factory()->create();
        $user = User::factory()->create();
        $recipe = $this->finalizedRecipe($creator);
        $bookmark = Bookmark::factory()->for($user, 'owner')->forRecipe($recipe)->create();
        $bookmarkSnapshot = $bookmark->getRawOriginal();
        $oldVersionId = $recipe->current_recipe_version_id;

        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $creator);
        $recipe->forceFill(['title' => 'Secret draft replacement'])->save();

        $this->actingAs($user)->get(route('bookmarks.index'))
            ->assertOk()
            ->assertSee('Stable public title')
            ->assertDontSee('Secret draft replacement');

        Livewire::actingAs($creator)->test(Form::class, ['recipe' => $recipe->fresh()])
            ->set('title', 'Newly published public title')
            ->call('finalize')
            ->assertHasNoErrors();

        $recipe->refresh();
        $this->assertNotSame($oldVersionId, $recipe->current_recipe_version_id);
        $this->assertSame(2, $recipe->currentVersion()->sole()->version_number);
        $this->assertEquals($bookmarkSnapshot, $bookmark->fresh()->getRawOriginal());

        $this->actingAs($user)->get(route('bookmarks.index'))
            ->assertOk()
            ->assertSee('Newly published public title')
            ->assertDontSee('Stable public title')
            ->assertDontSee('Secret draft replacement');
    }

    public function test_private_source_becomes_tombstone_and_restores_without_changing_bookmark(): void
    {
        $creator = User::factory()->create();
        $user = User::factory()->create();
        $recipe = $this->finalizedRecipe($creator, title: 'Initially public title');
        $bookmark = Bookmark::factory()->for($user, 'owner')->forRecipe($recipe)->create();

        $this->actingAs($user)->get(route('bookmarks.index'))
            ->assertSee('Initially public title')
            ->assertDontSee('Recipe unavailable');

        $recipe->forceFill(['visibility' => RecipeVisibility::Private])->save();
        $version = $recipe->currentVersion()->sole();
        $secretSnapshot = [...$version->snapshot, 'title' => 'Now private secret title'];
        RecipeVersion::withoutEvents(fn () => $version->forceFill(['snapshot' => $secretSnapshot])->save());

        $this->get(route('bookmarks.index'))
            ->assertOk()
            ->assertSee('Recipe unavailable')
            ->assertSee('This recipe is no longer publicly available.')
            ->assertDontSee('Initially public title')
            ->assertDontSee('Now private secret title')
            ->assertDontSee((string) $version->id);
        $this->assertDatabaseHas('bookmarks', ['id' => $bookmark->id]);

        $recipe->forceFill(['visibility' => RecipeVisibility::Public])->save();
        $this->get(route('bookmarks.index'))
            ->assertOk()
            ->assertSee('Now private secret title')
            ->assertDontSee('Recipe unavailable');
        $this->assertDatabaseHas('bookmarks', ['id' => $bookmark->id]);
    }

    public function test_hard_deleted_source_leaves_safe_removable_tombstone(): void
    {
        $creator = User::factory()->create(['email' => 'deleted-creator@example.test']);
        $user = User::factory()->create();
        $recipe = $this->finalizedRecipe($creator, title: 'Deleted source secret');
        $bookmark = Bookmark::factory()->for($user, 'owner')->forRecipe($recipe)->create();
        $recipeId = $recipe->id;

        $recipe->delete();

        $this->assertDatabaseMissing('recipes', ['id' => $recipeId]);
        $this->assertDatabaseHas('bookmarks', [
            'id' => $bookmark->id,
            'recipe_id' => $recipeId,
        ]);
        $this->actingAs($user)->get(route('bookmarks.index'))
            ->assertOk()
            ->assertSee('Recipe unavailable')
            ->assertDontSee('Deleted source secret')
            ->assertDontSee('deleted-creator@example.test');

        $this->delete(route('bookmarks.destroy', $bookmark))->assertRedirect();
        $this->assertDatabaseMissing('bookmarks', ['id' => $bookmark->id]);
    }

    public function test_listing_projection_has_only_approved_fields_and_tombstone_has_no_content(): void
    {
        $creator = User::factory()->create([
            'email' => 'private-creator@example.test',
            'remember_token' => 'private-token',
        ]);
        $user = User::factory()->create();
        $available = $this->finalizedRecipe($creator, title: 'Approved live title');
        $private = $this->finalizedRecipe($creator, RecipeVisibility::Private, 'Protected private title');
        Bookmark::factory()->for($user, 'owner')->forRecipe($available)->create();
        Bookmark::factory()->for($user, 'owner')->forRecipe($private)->create();

        $items = app(BookmarkListing::class)->paginate($user)->getCollection();
        $live = $items->firstWhere('recipeId', $available->id)->toArray();
        $tombstone = $items->firstWhere('recipeId', $private->id)->toArray();

        $this->assertSame(
            ['id', 'recipe_id', 'bookmarked_at', 'available', 'recipe'],
            array_keys($live),
        );
        $this->assertSame(
            ['id', 'title', 'servings', 'finalized_at', 'tags', 'classifications'],
            array_keys($live['recipe']),
        );
        $this->assertNull($tombstone['recipe']);
        $encoded = json_encode([$live, $tombstone], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-creator@example.test', $encoded);
        $this->assertStringNotContainsString('private-token', $encoded);
        $this->assertStringNotContainsString('Protected private title', $encoded);
        $this->assertStringNotContainsString('ingredients', $encoded);
        $this->assertStringNotContainsString('instructions', $encoded);
        $this->assertStringNotContainsString('version_id', $encoded);
    }

    public function test_public_detail_has_authenticated_toggle_and_guest_sign_in_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe(User::factory()->create());

        $this->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('Bookmark recipe');

        $this->actingAs($user)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Bookmark recipe')
            ->assertDontSee('Remove bookmark');

        $bookmark = Bookmark::factory()->for($user, 'owner')->forRecipe($recipe)->create();
        $this->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Remove bookmark')
            ->assertSee(route('bookmarks.destroy', $bookmark))
            ->assertDontSee('Bookmark recipe');

        $this->actingAs($other)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Bookmark recipe')
            ->assertDontSee('Remove bookmark')
            ->assertDontSee(route('bookmarks.destroy', $bookmark));
    }

    private function finalizedRecipe(
        User $owner,
        RecipeVisibility $visibility = RecipeVisibility::Public,
        string $title = 'Stable public title',
    ): Recipe {
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Mutable draft placeholder',
            'servings' => '2.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => $visibility,
            'finalized_at' => now()->utc(),
        ]);
        $version = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 1,
            'visibility' => $visibility,
            'snapshot' => [
                'title' => $title,
                'servings' => '4.00',
                'visibility' => $visibility->value,
                'ingredients' => [
                    ['position' => 0, 'original_text' => '1 onion', 'quantity' => '1', 'standard_unit' => null, 'custom_unit' => 'whole', 'generic_wording' => 'onion', 'notes' => null],
                ],
                'sections' => [],
                'steps' => [
                    ['position' => 0, 'text' => 'Cook safely.', 'section_key' => null],
                ],
            ],
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();

        return $recipe->fresh();
    }
}
