<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\PublicRecipeSummary;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeRevisionManager;
use App\Domain\Recipes\RecipeVisibility;
use App\Livewire\Recipes\Form;
use App\Models\Recipe;
use App\Models\RecipeDraftRevision;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PublicRecipeDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_authenticated_browse_use_the_same_public_current_version_scope(): void
    {
        $owner = User::factory()->create([
            'email' => 'private-owner@example.test',
            'is_administrator' => true,
            'remember_token' => 'private-security-token',
        ]);
        $other = User::factory()->create();
        $public = $this->finalizedRecipe($owner, 'Current public title');
        $private = $this->finalizedRecipe($owner, 'Hidden private title', RecipeVisibility::Private);
        $draft = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Hidden draft title',
            'visibility' => RecipeVisibility::Public,
        ]);
        $history = $this->finalizedRecipeWithHistory($owner, 'Withdrawn old title', 'Current history title');

        foreach ([null, $other, $owner] as $viewer) {
            $viewer === null ? auth()->logout() : $this->actingAs($viewer);

            $response = $this->get(route('recipes.index'));

            $response->assertOk()
                ->assertSee('Current public title')
                ->assertSee('Current history title')
                ->assertDontSee('Hidden private title')
                ->assertDontSee('Hidden draft title')
                ->assertDontSee('Withdrawn old title')
                ->assertDontSee('private-security-token')
                ->assertDontSee('is_administrator')
                ->assertDontSee(route('recipes.edit', $public));

            if (! $viewer?->is($owner)) {
                $response->assertDontSee('private-owner@example.test');
            }

            $results = $response->viewData('recipes');
            $this->assertEqualsCanonicalizing(
                [$public->id, $history->id],
                collect($results->items())->pluck('id')->all(),
            );
        }

        $this->assertDatabaseHas('recipes', ['id' => $private->id]);
        $this->assertDatabaseHas('recipes', ['id' => $draft->id]);
        $this->assertSame(2, $history->versions()->count());
    }

    public function test_title_search_is_trimmed_partial_case_insensitive_and_current_version_only(): void
    {
        $owner = User::factory()->create();
        $match = $this->finalizedRecipe($owner, 'Lemon Drizzle Cake');
        $this->finalizedRecipe($owner, 'Chocolate Tart');
        $this->finalizedRecipe($owner, 'lemon private', RecipeVisibility::Private);
        Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'lemon draft',
            'visibility' => RecipeVisibility::Public,
        ]);
        $history = $this->finalizedRecipeWithHistory($owner, 'Lemon historical title', 'Savory Pie');
        $activeDraft = $this->finalizedRecipe($owner, 'Stable Soup');
        app(RecipeRevisionManager::class)->startOrResume($activeDraft->id, $owner);
        $activeDraft->forceFill(['title' => 'Lemon draft revision'])->save();

        $this->get(route('recipes.index', ['q' => 'Lemon Drizzle Cake']))
            ->assertOk()
            ->assertSee('Lemon Drizzle Cake')
            ->assertDontSee('Chocolate Tart');

        $response = $this->get(route('recipes.index', ['q' => '  dRiZzLe  ']))
            ->assertOk()
            ->assertSee('Lemon Drizzle Cake')
            ->assertDontSee('Chocolate Tart')
            ->assertDontSee('lemon private')
            ->assertDontSee('lemon draft')
            ->assertDontSee('Lemon historical title')
            ->assertDontSee('Lemon draft revision');

        $this->assertSame([$match->id], collect($response->viewData('recipes')->items())->pluck('id')->all());
        $this->assertSame([], collect(
            $this->get(route('recipes.index', ['q' => 'historical']))
                ->viewData('recipes')
                ->items(),
        )->pluck('id')->all());
        $this->assertNotContains($history->id, collect($response->viewData('recipes')->items())->pluck('id')->all());

        $this->get(route('recipes.index', ['q' => '   ']))
            ->assertOk()
            ->assertSee('Lemon Drizzle Cake')
            ->assertSee('Chocolate Tart')
            ->assertSee('Stable Soup');
    }

    public function test_non_matching_and_empty_browse_states_do_not_disclose_hidden_matches(): void
    {
        $owner = User::factory()->create();

        $this->get(route('recipes.index'))
            ->assertOk()
            ->assertSee('No public recipes yet.');

        $this->finalizedRecipe($owner, 'Secret Search', RecipeVisibility::Private);
        $draft = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Secret Search']);

        $response = $this->get(route('recipes.index', ['q' => 'Secret Search']))
            ->assertOk()
            ->assertSee('No recipes match your search.')
            ->assertDontSee('No public recipes yet.');

        $this->assertSame(0, $response->viewData('recipes')->total());
        $response->assertDontSee(route('recipes.show', $draft));
    }

    public function test_results_paginate_with_stable_order_no_duplicates_and_persist_search_state(): void
    {
        Carbon::setTestNow('2026-08-23 12:00:00');
        $owner = User::factory()->create();
        $recipes = collect();

        foreach (range(1, 14) as $number) {
            $recipes->push($this->finalizedRecipe($owner, sprintf('Stable result %02d', $number)));
        }

        $firstResponse = $this->get(route('recipes.index', ['q' => 'Stable']));
        $secondResponse = $this->get(route('recipes.index', ['q' => 'Stable', 'page' => 2]));
        $first = $firstResponse->viewData('recipes');
        $second = $secondResponse->viewData('recipes');
        $firstIds = collect($first->items())->pluck('id')->all();
        $secondIds = collect($second->items())->pluck('id')->all();

        $this->assertSame($recipes->pluck('id')->sortDesc()->take(12)->values()->all(), $firstIds);
        $this->assertSame($recipes->pluck('id')->sortDesc()->skip(12)->values()->all(), $secondIds);
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
        $this->assertSame(14, $first->total());
        $this->assertSame(12, $first->perPage());
        $this->assertSame(2, $first->lastPage());
        $this->assertStringContainsString('q=Stable', $first->url(2));

        $firstResponse->assertOk()
            ->assertSee('Showing 1–12 of 14 public recipes');
        $secondResponse->assertOk()
            ->assertSee('Showing 13–14 of 14 public recipes')
            ->assertSee('value="Stable"', false);

        Carbon::setTestNow();
    }

    public function test_current_version_appears_once_and_active_draft_changes_wait_for_publication(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, 'Published original');
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $recipe->forceFill(['title' => 'Private replacement'])->save();

        $this->get(route('recipes.index', ['q' => 'Published']))
            ->assertOk()
            ->assertSee('Published original')
            ->assertDontSee('Private replacement');

        $this->get(route('recipes.index', ['q' => 'replacement']))
            ->assertOk()
            ->assertSee('No recipes match your search.');

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe->fresh()])
            ->set('title', 'Published replacement')
            ->call('finalize')
            ->assertHasNoErrors();

        $recipe->refresh();
        $response = $this->get(route('recipes.index', ['q' => 'replacement']))
            ->assertOk()
            ->assertSee('Published replacement')
            ->assertDontSee('Published original');
        $this->assertSame([$recipe->id], collect($response->viewData('recipes')->items())->pluck('id')->all());
        $this->assertSame(2, $recipe->versions()->count());
    }

    public function test_abandoning_revision_leaves_discovery_unchanged(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, 'Unchanged public title');
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $recipe->forceFill(['title' => 'Abandoned private title'])->save();

        $this->actingAs($owner)
            ->delete(route('recipes.revision.destroy', $recipe))
            ->assertRedirect(route('recipes.show', $recipe));

        auth()->logout();
        $this->get(route('recipes.index'))
            ->assertOk()
            ->assertSee('Unchanged public title')
            ->assertDontSee('Abandoned private title');
        $this->assertSame(1, $recipe->versions()->count());
    }

    public function test_unpublishing_removes_discovery_result_but_preserves_inaccessible_history(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipeWithHistory($owner, 'Old retained title', 'Visible current title');

        $this->get(route('recipes.index'))
            ->assertOk()
            ->assertSee('Visible current title');

        $this->actingAs($owner)->patch(route('recipes.visibility.update', $recipe), [
            'visibility' => RecipeVisibility::Private->value,
        ])->assertRedirect(route('recipes.show', $recipe));

        auth()->logout();
        $this->get(route('recipes.index'))
            ->assertOk()
            ->assertSee('No public recipes yet.')
            ->assertDontSee('Visible current title')
            ->assertDontSee('Old retained title');
        $this->assertSame(2, $recipe->versions()->count());
        $this->get(route('recipes.show', $recipe))->assertNotFound();
    }

    public function test_summary_serialization_and_html_expose_only_allowlisted_public_fields(): void
    {
        $owner = User::factory()->create([
            'email' => 'owner-secret@example.test',
            'is_administrator' => true,
            'remember_token' => 'security-secret',
        ]);
        $recipe = $this->finalizedRecipe($owner, '<script>alert("title")</script>');
        $revision = new RecipeDraftRevision;
        $revision->forceFill(['base_recipe_version_id' => $recipe->current_recipe_version_id]);
        $revision->recipe()->associate($recipe);
        $revision->save();

        $summary = PublicRecipeSummary::fromCurrentVersion($recipe->fresh()->load('currentVersion'));
        $serialized = $summary->toArray();

        $this->assertSame(['id', 'title', 'servings', 'finalized_at', 'tags', 'classifications'], array_keys($serialized));
        $json = json_encode($serialized, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('owner-secret@example.test', $json);
        $this->assertStringNotContainsString('security-secret', $json);
        $this->assertStringNotContainsString('is_administrator', $json);
        $this->assertStringNotContainsString('user_id', $json);
        $this->assertStringNotContainsString('version', $json);
        $this->assertStringNotContainsString((string) $revision->id, $json);

        $this->get(route('recipes.index'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("title")</script>', false)
            ->assertDontSee('owner-secret@example.test')
            ->assertDontSee((string) $revision->id);
    }

    public function test_untrusted_and_crafted_query_parameters_cannot_expand_public_scope(): void
    {
        $owner = User::factory()->create();
        $public = $this->finalizedRecipe($owner, 'Safe public title');
        $private = $this->finalizedRecipe($owner, 'Crafted private match', RecipeVisibility::Private);
        $draft = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Crafted draft match']);

        $response = $this->get(route('recipes.index', [
            'visibility' => 'private',
            'lifecycle' => 'draft',
            'tag' => 'internal-only',
        ]))->assertOk()
            ->assertSee('Safe public title')
            ->assertDontSee('Crafted private match')
            ->assertDontSee('Crafted draft match');

        $this->assertSame([$public->id], collect($response->viewData('recipes')->items())->pluck('id')->all());
        $this->assertDatabaseHas('recipes', ['id' => $private->id]);
        $this->assertDatabaseHas('recipes', ['id' => $draft->id]);

        $this->get(route('recipes.index', ['q' => str_repeat('x', 101)]))
            ->assertSessionHasErrors('q');

        auth()->logout();
        $this->get(route('recipes.show', $private))->assertNotFound();
        $this->get(route('recipes.show', $draft))->assertNotFound();
    }

    private function finalizedRecipe(
        User $owner,
        string $title,
        RecipeVisibility $visibility = RecipeVisibility::Public,
    ): Recipe {
        $finalizedAt = now()->utc();
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Mutable working title',
            'servings' => '4.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => $visibility,
            'finalized_at' => $finalizedAt,
        ]);
        $version = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 1,
            'visibility' => $visibility,
            'snapshot' => $this->snapshot($title, $visibility),
            'finalized_at' => $finalizedAt,
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();

        return $recipe->fresh();
    }

    private function finalizedRecipeWithHistory(User $owner, string $oldTitle, string $currentTitle): Recipe
    {
        $recipe = $this->finalizedRecipe($owner, $oldTitle);
        $current = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 2,
            'visibility' => RecipeVisibility::Public,
            'snapshot' => $this->snapshot($currentTitle, RecipeVisibility::Public),
            'finalized_at' => now()->utc(),
        ]);
        $recipe->forceFill([
            'current_recipe_version_id' => $current->id,
            'finalized_at' => $current->finalized_at,
        ])->save();

        return $recipe->fresh();
    }

    /** @return array<string, mixed> */
    private function snapshot(string $title, RecipeVisibility $visibility): array
    {
        return [
            'title' => $title,
            'servings' => '4.00',
            'visibility' => $visibility->value,
            'ingredients' => [
                [
                    'position' => 0,
                    'original_text' => '1 tbsp olive oil',
                    'quantity' => '1.000000000000000000',
                    'standard_unit' => 'tablespoon_uk',
                    'custom_unit' => null,
                    'generic_wording' => 'olive oil',
                    'notes' => null,
                ],
            ],
            'sections' => [],
            'steps' => [
                ['position' => 0, 'text' => 'Mix gently.', 'section_key' => null],
            ],
        ];
    }
}
