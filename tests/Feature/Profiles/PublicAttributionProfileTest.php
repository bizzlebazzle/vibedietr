<?php

namespace Tests\Feature\Profiles;

use App\Domain\Recipes\PublicRecipe;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Livewire\Recipes\Form;
use App\Models\Bookmark;
use App\Models\PrivateRecipeTag;
use App\Models\PublicProfile;
use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Models\RecipeDraftRevision;
use App\Models\RecipeRemixLineage;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PublicAttributionProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_configure_only_their_own_safe_public_settings(): void
    {
        $owner = User::factory()->create([
            'name' => 'Private account name',
            'email' => 'distinctive-private@example.test',
        ]);
        $other = User::factory()->withEnabledPublicProfile('Existing public name')->create();
        $otherProfile = $other->publicProfile()->sole();

        $this->patch(route('profile.public-attribution.update'), $this->settings('Guest'))
            ->assertRedirect(route('login'));

        $this->actingAs($owner)->patch(route('profile.public-attribution.update'), [
            ...$this->settings('Chosen public name'),
            'user_id' => $other->id,
            'owner_id' => $other->id,
            'public_profile_id' => $otherProfile->id,
            'email' => 'public@example.test',
        ])->assertSessionHasErrors(['user_id', 'owner_id', 'public_profile_id', 'email']);

        $this->assertFalse($owner->publicProfile()->exists());
        $this->assertSame('Existing public name', $otherProfile->fresh()->attribution_name);

        $this->patch(route('profile.public-attribution.update'), $this->settings('  Chosen public name  '))
            ->assertRedirect(route('profile'));

        $profile = $owner->publicProfile()->sole();
        $this->assertSame('Chosen public name', $profile->attribution_name);
        $this->assertTrue($profile->profile_enabled);
        $this->assertTrue($profile->show_public_recipes);
        $this->assertTrue($profile->show_public_remixes);
        $this->assertSame('Private account name', $owner->fresh()->name);
        $this->assertSame('distinctive-private@example.test', $owner->fresh()->email);
        $this->assertTrue(Str::isUlid($profile->id));

        $otherId = $otherProfile->id;
        $this->actingAs($other)->patch(route('profile.public-attribution.update'), $this->settings('Updated by owner'))
            ->assertRedirect(route('profile'));
        $this->assertSame($otherId, $otherProfile->fresh()->id);
        $this->assertSame('Updated by owner', $otherProfile->fresh()->attribution_name);
        $this->assertSame('Chosen public name', $profile->fresh()->attribution_name);
    }

    public function test_attribution_validation_rejects_blank_email_html_and_overlong_values(): void
    {
        $user = User::factory()->create();

        foreach ([
            '   ',
            'visible-email@example.test',
            '<strong>Injected name</strong>',
            str_repeat('a', 81),
        ] as $name) {
            $this->actingAs($user)
                ->patch(route('profile.public-attribution.update'), $this->settings($name))
                ->assertSessionHasErrors('attribution_name');
        }

        $this->assertFalse($user->publicProfile()->exists());
    }

    public function test_enabled_profile_is_public_but_serializes_only_allowlisted_fields(): void
    {
        $owner = User::factory()->administrator()->withEnabledPublicProfile('Safe & public <name>')->create([
            'email' => 'profile-private@example.test',
            'remember_token' => 'private-security-token',
        ]);
        $profile = $owner->publicProfile()->sole();
        $profile->forceFill([
            'attribution_name' => 'Safe public name',
            'show_public_recipes' => true,
            'show_public_remixes' => true,
        ])->save();

        $public = $this->finalizedRecipe($owner, 'Visible original');
        $private = $this->finalizedRecipe($owner, 'Private recipe secret', RecipeVisibility::Private);
        $draft = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Draft recipe secret']);
        $versioned = $this->finalizedRecipe($owner, 'Historical version secret');
        $current = RecipeVersion::factory()->for($versioned)->create([
            'version_number' => 2,
            'public_attribution_name' => 'Safe public name',
            'snapshot' => $this->snapshot('Current finalized recipe'),
        ]);
        $versioned->forceFill([
            'current_recipe_version_id' => $current->id,
            'title' => 'Active draft revision secret',
        ])->save();
        $revision = new RecipeDraftRevision;
        $revision->forceFill(['base_recipe_version_id' => $current->id]);
        $revision->recipe()->associate($versioned);
        $revision->save();

        $source = $this->finalizedRecipe(User::factory()->create(), 'Private source lineage secret', RecipeVisibility::Private);
        $publicRemix = $this->finalizedRecipe($owner, 'Visible public remix');
        $this->lineage($publicRemix, $source);
        $privateRemix = $this->finalizedRecipe($owner, 'Private remix secret', RecipeVisibility::Private);
        $this->lineage($privateRemix, $source);
        $draftRemix = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Draft remix secret']);
        $this->lineage($draftRemix, $source);

        $bookmarkSource = $this->finalizedRecipe(User::factory()->create(), 'Bookmark source');
        $bookmark = Bookmark::factory()->for($owner, 'owner')->forRecipe($bookmarkSource)->create();
        RecipeCollection::factory()->for($owner, 'owner')->withOwnedRecipe($public)->withBookmark($bookmark)->create([
            'name' => 'Never public collection',
        ]);
        PrivateRecipeTag::factory()->for($owner, 'owner')->withOwnedRecipe($public)->withBookmark($bookmark)->create([
            'name' => 'Never public private tag',
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach ([null, User::factory()->create()] as $viewer) {
            $viewer === null ? auth()->logout() : $this->actingAs($viewer);
            $response = $this->get(route('public-profiles.show', $profile))->assertOk()
                ->assertSee('Safe public name')
                ->assertSee('Visible original')
                ->assertSee('Current finalized recipe')
                ->assertSee('Visible public remix')
                ->assertDontSee('Private recipe secret')
                ->assertDontSee('Draft recipe secret')
                ->assertDontSee('Historical version secret')
                ->assertDontSee('Active draft revision secret')
                ->assertDontSee('Private remix secret')
                ->assertDontSee('Draft remix secret')
                ->assertDontSee('Private source lineage secret')
                ->assertDontSee('profile-private@example.test')
                ->assertDontSee('private-security-token')
                ->assertDontSee('Never public collection')
                ->assertDontSee('Never public private tag');

            $projection = $response->viewData('profile');
            $this->assertSame(['id', 'attribution_name', 'recipes', 'remixes'], array_keys($projection->toArray()));
            $serialized = json_encode($projection->toArray(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('profile-private@example.test', $serialized);
            $this->assertStringNotContainsString('is_administrator', $serialized);
            $this->assertStringNotContainsString('user_id', $serialized);
            $this->assertStringNotContainsString('bookmark', $serialized);
            $this->assertStringNotContainsString('collection', $serialized);
            $this->assertStringNotContainsString('private_tag', $serialized);
        }

        $publicSql = implode(' ', $queries);
        $this->assertStringNotContainsString('bookmarks', $publicSql);
        $this->assertStringNotContainsString('recipe_collections', $publicSql);
        $this->assertStringNotContainsString('private_recipe_tags', $publicSql);
        $this->assertTrue($public->isPubliclyViewable());
        $this->assertFalse($private->isPubliclyViewable());
        $this->assertFalse($draft->isFinalized());
    }

    public function test_disabled_profile_is_404_without_unpublishing_or_erasing_attribution(): void
    {
        $owner = User::factory()->withEnabledPublicProfile('Persistent attribution')->create([
            'email' => 'never-public@example.test',
        ]);
        $profile = $owner->publicProfile()->sole();
        $recipe = $this->finalizedRecipe($owner, 'Still public recipe');
        $versionIds = $recipe->versions()->pluck('id')->all();

        $this->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Persistent attribution')
            ->assertSee(route('public-profiles.show', $profile));

        $this->actingAs($owner)->patch(route('profile.public-attribution.update'), [
            ...$this->settings('Changed future attribution'),
            'profile_enabled' => false,
            'show_public_recipes' => false,
            'show_public_remixes' => false,
        ])->assertRedirect(route('profile'));

        auth()->logout();
        $this->get(route('public-profiles.show', $profile))->assertNotFound();
        $this->get(route('recipes.show', $recipe))->assertOk()
            ->assertSee('Still public recipe')
            ->assertSee('Persistent attribution')
            ->assertDontSee(route('public-profiles.show', $profile))
            ->assertDontSee('Changed future attribution')
            ->assertDontSee('never-public@example.test');

        $this->assertTrue($recipe->fresh()->isPubliclyViewable());
        $this->assertSame($versionIds, $recipe->versions()->pluck('id')->all());
        $this->assertSame('Persistent attribution', $recipe->currentVersion()->sole()->public_attribution_name);
    }

    public function test_real_publication_snapshots_attribution_without_following_account_changes(): void
    {
        $owner = User::factory()->withEnabledPublicProfile('First selected name')->create([
            'name' => 'Private account identity',
            'email' => 'private-auth@example.test',
        ]);
        $first = Recipe::factory()->for($owner, 'owner')->validDraft()->create();

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $first])
            ->call('finalize')->assertHasNoErrors();
        $first->refresh();
        $firstVersion = $first->currentVersion()->sole();
        $this->assertSame('First selected name', $firstVersion->public_attribution_name);

        $owner->forceFill([
            'name' => 'Changed private identity',
            'email' => 'changed-private@example.test',
        ])->save();
        $this->actingAs($owner)->patch(route('profile.public-attribution.update'), $this->settings('Second selected name'))
            ->assertRedirect(route('profile'));

        $second = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $second])
            ->call('finalize')->assertHasNoErrors();
        $second->refresh();

        $this->assertSame('First selected name', $firstVersion->fresh()->public_attribution_name);
        $this->assertSame('Second selected name', $second->currentVersion()->sole()->public_attribution_name);
        $this->assertSame('changed-private@example.test', $owner->fresh()->email);

        auth()->logout();
        $this->get(route('recipes.show', $first))->assertOk()
            ->assertSee('First selected name')
            ->assertDontSee('Second selected name')
            ->assertDontSee('Private account identity')
            ->assertDontSee('Changed private identity')
            ->assertDontSee('changed-private@example.test');

        $serialized = PublicRecipe::fromCurrentVersion($first->fresh()->load('currentVersion'))->toArray();
        $this->assertSame(
            ['name' => 'First selected name', 'profile_id' => $owner->publicProfile()->sole()->id],
            $serialized['attribution'],
        );
    }

    public function test_recipe_and_remix_listing_preferences_are_independent_of_visibility(): void
    {
        $owner = User::factory()->withEnabledPublicProfile('Listing owner')->create();
        $profile = $owner->publicProfile()->sole();
        $regular = $this->finalizedRecipe($owner, 'Regular public recipe');
        $source = $this->finalizedRecipe(User::factory()->withEnabledPublicProfile('Source creator')->create(), 'Public source');
        $remix = $this->finalizedRecipe($owner, 'Public remix recipe');
        $this->lineage($remix, $source);

        $this->actingAs($owner)->patch(route('profile.public-attribution.update'), [
            ...$this->settings('Listing owner'),
            'show_public_recipes' => false,
            'show_public_remixes' => true,
        ])->assertRedirect(route('profile'));
        auth()->logout();

        $this->get(route('public-profiles.show', $profile))->assertOk()
            ->assertSee('Public remix recipe')
            ->assertDontSee('Regular public recipe');
        $this->get(route('recipes.show', $regular))->assertOk();
        $this->get(route('recipes.show', $remix))->assertOk()
            ->assertSee('Listing owner')
            ->assertSee('Source creator');

        $source->forceFill(['visibility' => RecipeVisibility::Private])->save();
        $this->get(route('recipes.show', $remix))->assertOk()
            ->assertSee('Remixed from an unavailable recipe')
            ->assertDontSee('Source creator')
            ->assertDontSee('Public source');

        $this->actingAs($owner)->patch(route('profile.public-attribution.update'), [
            ...$this->settings('Listing owner'),
            'show_public_recipes' => true,
            'show_public_remixes' => false,
        ])->assertRedirect(route('profile'));
        auth()->logout();
        $this->get(route('public-profiles.show', $profile))->assertOk()
            ->assertSee('Regular public recipe')
            ->assertDontSee('Public remix recipe');
        $this->assertTrue($remix->fresh()->isPubliclyViewable());
    }

    public function test_public_identifier_is_stable_unique_and_removed_with_current_hard_deleted_account(): void
    {
        $owner = User::factory()->withEnabledPublicProfile('Stable profile')->create();
        $profile = $owner->publicProfile()->sole();
        $id = $profile->id;

        $this->actingAs($owner)->patch(route('profile.public-attribution.update'), $this->settings('Updated profile'))
            ->assertRedirect(route('profile'));
        $this->assertSame($id, $profile->fresh()->id);

        try {
            PublicProfile::factory()->create(['id' => $id]);
            $this->fail('Duplicate public profile identifier was accepted.');
        } catch (QueryException) {
            $this->assertSame(1, PublicProfile::query()->whereKey($id)->count());
        }

        $owner->delete();
        $this->assertDatabaseMissing('public_profiles', ['id' => $id]);
        $this->get(route('public-profiles.show', $id))->assertNotFound();
    }

    public function test_factories_represent_public_preferences_and_private_data_states(): void
    {
        $attributed = User::factory()->withPublicAttribution('Factory attribution')->create();
        $enabled = User::factory()->withEnabledPublicProfile('Enabled profile')->create();
        $recipes = User::factory()->withPublicRecipeListing('Recipe listing')->create();
        $remixes = User::factory()->withPublicRemixListing('Remix listing')->create();
        $privateRecipe = User::factory()->withPrivateRecipe()->create();
        $privateOrganization = User::factory()->withPrivateRecipeOrganization()->create();
        $privateEmail = User::factory()->withDistinctivePrivateEmail('factory-private@example.test')->create();
        $disabled = PublicProfile::factory()->disabled()->create();

        $this->assertSame('Factory attribution', $attributed->publicProfile()->sole()->attribution_name);
        $this->assertFalse($attributed->publicProfile()->sole()->profile_enabled);
        $this->assertTrue($enabled->publicProfile()->sole()->profile_enabled);
        $this->assertTrue($recipes->publicProfile()->sole()->show_public_recipes);
        $this->assertFalse($recipes->publicProfile()->sole()->show_public_remixes);
        $this->assertTrue($remixes->publicProfile()->sole()->show_public_remixes);
        $this->assertFalse($remixes->publicProfile()->sole()->show_public_recipes);
        $ownedPrivateRecipe = Recipe::query()->where('user_id', $privateRecipe->id)->sole();
        $this->assertTrue($ownedPrivateRecipe->isFinalized());
        $this->assertSame(RecipeVisibility::Private, $ownedPrivateRecipe->visibility);
        $this->assertSame(1, $privateOrganization->recipeCollections()->count());
        $this->assertSame(1, $privateOrganization->privateRecipeTags()->count());
        $this->assertSame('factory-private@example.test', $privateEmail->email);
        $this->assertFalse($disabled->profile_enabled);
    }

    /** @return array<string, mixed> */
    private function settings(string $name): array
    {
        return [
            'attribution_name' => $name,
            'profile_enabled' => true,
            'show_public_recipes' => true,
            'show_public_remixes' => true,
        ];
    }

    private function finalizedRecipe(
        User $owner,
        string $title,
        RecipeVisibility $visibility = RecipeVisibility::Public,
    ): Recipe {
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'title' => $title,
            'servings' => '2.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => $visibility,
            'finalized_at' => now()->utc(),
        ]);
        $version = RecipeVersion::factory()->for($recipe)->create([
            'visibility' => $visibility,
            'snapshot' => $this->snapshot($title, $visibility),
            'public_attribution_name' => $owner->publicProfile()->value('attribution_name'),
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();

        return $recipe->fresh();
    }

    /** @return array<string, mixed> */
    private function snapshot(
        string $title,
        RecipeVisibility $visibility = RecipeVisibility::Public,
    ): array {
        return [
            'title' => $title,
            'servings' => '2.00',
            'visibility' => $visibility->value,
            'ingredients' => [],
            'sections' => [],
            'steps' => [],
        ];
    }

    private function lineage(Recipe $remix, Recipe $source): RecipeRemixLineage
    {
        $lineage = new RecipeRemixLineage;
        $lineage->forceFill([
            'source_recipe_id' => $source->id,
            'source_recipe_version_id' => $source->current_recipe_version_id,
            'source_version_number' => $source->currentVersion()->sole()->version_number,
            'source_creator_user_id' => $source->user_id,
            'operation_id' => (string) Str::ulid(),
        ]);
        $lineage->remixRecipe()->associate($remix);
        $lineage->save();

        return $lineage;
    }
}
