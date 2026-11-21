<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeRevisionManager;
use App\Livewire\Recipes\Form;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_views_require_authentication_and_validate_selector(): void
    {
        foreach (['mine', 'drafts', 'bookmarks'] as $view) {
            $this->get(route('recipes.index', ['view' => $view]))->assertRedirect(route('login'));
        }
        $this->getJson(route('recipes.index', ['view' => 'invalid']))->assertUnprocessable();
        $this->getJson(route('recipes.index', ['view' => ['mine']]))->assertUnprocessable();
        $this->get(route('recipes.index'))->assertOk()->assertDontSee('My draft recipes');
    }

    public function test_owned_lists_and_counts_include_private_recipes_and_revision_overlap_without_leaking_working_titles(): void
    {
        $owner = User::factory()->create();
        $public = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();
        $private = Recipe::factory()->for($owner, 'owner')->finalizedPrivate()->create();
        $draft = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Unfinished import']);
        $revision = Recipe::factory()->for($owner, 'owner')->publicFinalizedWithActiveRevision()->create();
        $revision->forceFill(['title' => 'Working replacement title'])->save();
        $foreign = Recipe::factory()->create(['title' => 'Other owner secret']);

        $response = $this->actingAs($owner)->get(route('recipes.index', ['view' => 'mine']));
        $response->assertOk()->assertSee($public->currentVersion->snapshot['title'])
            ->assertSee($private->currentVersion->snapshot['title'])
            ->assertSee('Unfinished draft revision')->assertSee('Private')
            ->assertDontSee('Working replacement title')->assertDontSee($foreign->title)
            ->assertDontSee($draft->title);
        $this->assertEqualsCanonicalizing([$public->id, $private->id, $revision->id],
            $response->viewData('recipes')->pluck('id')->all());

        $response = $this->get(route('recipes.index', ['view' => 'drafts']));
        $response->assertOk()->assertSee('Working replacement title')->assertSee('Draft revision')
            ->assertSee('Unfinished import')->assertSee(route('recipes.edit', $draft), false)
            ->assertDontSee($foreign->title);
        $this->assertEqualsCanonicalizing([$draft->id, $revision->id], $response->viewData('recipes')->pluck('id')->all());
        $this->get(route('dashboard'))->assertSee('You have 3 recipes and 2 drafts.')
            ->assertDontSee('Get started with VibeDietr');
        $this->actingAs(User::factory()->create())->get(route('recipes.index', ['view' => 'drafts']))
            ->assertOk()->assertDontSee($draft->title)->assertDontSee('Working replacement title');
    }

    public function test_drafts_are_stably_paginated_and_empty_states_offer_creation(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->get(route('recipes.index', ['view' => 'mine']))
            ->assertSee('You have no finalized recipes yet.')->assertSee('Create a recipe');
        $drafts = Recipe::factory()->count(13)->for($owner, 'owner')->create(['updated_at' => now()]);
        $response = $this->get(route('recipes.index', ['view' => 'drafts']));
        $page = $response->viewData('recipes');
        $this->assertSame(12, $page->count());
        $this->assertSame($drafts->last()->id, $page->first()->id);
        $this->assertStringContainsString('view=drafts', $page->nextPageUrl());
        $response = $this->get($page->nextPageUrl());
        $this->assertSame($drafts->first()->id, $response->viewData('recipes')->first()->id);
    }

    public function test_finalization_publication_and_abandonment_update_draft_membership(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])->call('finalize')->assertHasNoErrors();
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('You have 1 recipe and 0 drafts.');
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $this->get(route('dashboard'))->assertSee('You have 1 recipe and 1 draft.');
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe->fresh()])->call('finalize')->assertHasNoErrors();
        $this->get(route('dashboard'))->assertSee('You have 1 recipe and 0 drafts.');
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $this->delete(route('recipes.revision.destroy', $recipe))->assertRedirect();
        $this->get(route('recipes.index', ['view' => 'drafts']))
            ->assertSee('You have no draft recipes or unfinished revisions.');
    }

    public function test_legacy_bookmark_listing_preserves_page_in_redirect(): void
    {
        $this->actingAs(User::factory()->create())->get(route('bookmarks.index', ['page' => 2]))
            ->assertRedirect(route('recipes.index', ['view' => 'bookmarks', 'page' => 2]));
    }
}
