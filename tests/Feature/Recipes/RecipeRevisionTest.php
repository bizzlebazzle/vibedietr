<?php

namespace Tests\Feature\Recipes;

use App\Audit\Enums\AuditAction;
use App\Domain\Recipes\RecipeFinalizationHook;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeRevisionManager;
use App\Domain\Recipes\RecipeRevisionPublisher;
use App\Domain\Recipes\RecipeVisibility;
use App\Domain\Recipes\StaleRecipeRevision;
use App\Livewire\Recipes\Form;
use App\Models\AuditEvent;
use App\Models\Recipe;
use App\Models\RecipeDraftRevision;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class RecipeRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_copies_current_version_exactly_and_repeat_edit_resumes_without_overwrite(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        $manager = app(RecipeRevisionManager::class);

        $first = $manager->startOrResume($recipe->id, $owner);

        $this->assertSame($recipe->current_recipe_version_id, $first->base_recipe_version_id);
        $this->assertSame('Stable title', $recipe->fresh()->title);
        $this->assertSame(
            ['  2 tbsp olive oil  ', 'salt to taste'],
            $recipe->ingredientLines()->pluck('original_text')->all(),
        );
        $this->assertSame(['Prepare', 'Cook'], $recipe->instructionSections()->pluck('name')->all());
        $this->assertSame(
            ['  Keep this spacing.  ', 'Cook gently.'],
            $recipe->instructionSteps()->pluck('text')->all(),
        );
        $this->assertSame([0, 1], $recipe->ingredientLines()->pluck('position')->all());
        $this->assertSame([0, 1], $recipe->instructionSteps()->pluck('position')->all());
        $this->assertSame('12.500000000000000000', $recipe->ingredientLines()->first()->quantity);

        $line = $recipe->ingredientLines()->first();
        $line->original_text = 'Owner changed this draft';
        $line->save();
        $second = $manager->startOrResume($recipe->id, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $recipe->activeRevision()->count());
        $this->assertSame('Owner changed this draft', $recipe->ingredientLines()->first()->original_text);
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::RecipeRevisionCreated)->count());
    }

    public function test_revision_is_private_and_public_reads_remain_on_current_finalized_version(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $recipe->forceFill(['title' => 'Secret replacement title'])->save();
        $recipe->ingredientLines()->first()->update(['original_text' => 'Secret replacement ingredient']);

        foreach ([null, $other] as $viewer) {
            $viewer === null ? auth()->logout() : $this->actingAs($viewer);
            $this->get(route('recipes.show', $recipe))
                ->assertOk()
                ->assertSee('Stable title')
                ->assertSeeText('12.5 g olive oil, exact note')
                ->assertDontSee('Secret replacement title')
                ->assertDontSee('Secret replacement ingredient')
                ->assertDontSee('draft revision')
                ->assertDontSee(route('recipes.edit', $recipe));
        }

        auth()->logout();
        $this->delete(route('recipes.revision.destroy', $recipe))->assertRedirect(route('login'));
        $this->actingAs($other)->get(route('recipes.edit', $recipe))->assertForbidden();
        $this->actingAs($other)->delete(route('recipes.revision.destroy', $recipe))->assertForbidden();
        Livewire::actingAs($other)->test(Form::class, ['recipe' => $recipe])->assertForbidden();

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('A private draft revision exists')
            ->assertSee('Return to draft revision');
    }

    public function test_valid_revision_publishes_next_immutable_version_and_preserves_history_visibility_and_audit_privacy(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, RecipeVisibility::Private);
        $versionOne = $recipe->currentVersion()->sole();
        $originalSnapshot = $versionOne->snapshot;
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe->fresh()])
            ->set('title', 'Replacement title')
            ->set('ingredients.0.original_text', 'Replacement exact line')
            ->set('steps.0.text', 'Replacement exact instruction')
            ->call('finalize')
            ->assertHasNoErrors()
            ->assertRedirect(route('recipes.show', $recipe));

        $recipe->refresh();
        $versionTwo = $recipe->currentVersion()->sole();
        $this->assertSame(2, $versionTwo->version_number);
        $this->assertSame($versionTwo->id, $recipe->current_recipe_version_id);
        $this->assertSame(RecipeVisibility::Private, $recipe->visibility);
        $this->assertSame(2, $recipe->versions()->count());
        $this->assertSame($originalSnapshot, $versionOne->fresh()->snapshot);
        $this->assertSame('Replacement title', $versionTwo->snapshot['title']);
        $this->assertSame('Replacement exact line', $versionTwo->snapshot['ingredients'][0]['original_text']);
        $this->assertSame('Replacement exact instruction', $versionTwo->snapshot['steps'][0]['text']);
        $this->assertFalse($recipe->activeRevision()->exists());
        $this->assertSame($versionOne->id, $recipe->versions()->where('version_number', 1)->sole()->id);

        $event = AuditEvent::query()->where('action', AuditAction::RecipeRevisionPublished)->sole();
        $this->assertSame('revision_published', $event->payload['event']);
        $this->assertSame('completed', $event->payload['outcome']);
        $this->assertSame($versionOne->id, $event->payload['base_version_id']);
        $this->assertSame(1, $event->payload['base_version_number']);
        $this->assertSame($versionTwo->id, $event->payload['new_version_id']);
        $this->assertSame(2, $event->payload['new_version_number']);
        $this->assertEqualsCanonicalizing([
            'event', 'outcome', 'revision_id', 'base_version_id', 'base_version_number',
            'new_version_id', 'new_version_number',
        ], array_keys($event->payload));
        $encoded = json_encode($event->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Replacement title', $encoded);
        $this->assertStringNotContainsString('Replacement exact line', $encoded);
        $this->assertStringNotContainsString('Replacement exact instruction', $encoded);
    }

    public function test_failed_publication_leaves_old_version_current_and_draft_available_without_success_audit(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        $oldVersionId = $recipe->current_recipe_version_id;
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe->fresh()])
            ->set('ingredients', [])
            ->call('finalize')
            ->assertHasErrors(['ingredients']);

        $recipe->refresh();
        $this->assertSame($oldVersionId, $recipe->current_recipe_version_id);
        $this->assertTrue($recipe->activeRevision()->exists());
        $this->assertSame(1, $recipe->versions()->count());
        $this->assertFalse(AuditEvent::query()->where('action', AuditAction::RecipeRevisionPublished)->exists());
    }

    public function test_abandon_removes_only_draft_state_and_does_not_change_reader_output_or_versions(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        $versionId = $recipe->current_recipe_version_id;
        $revision = app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        RecipeIngredientLine::factory()->for($recipe)->create([
            'original_text' => 'Draft-only child',
            'position' => 2,
        ]);
        $recipe->forceFill(['title' => 'Abandoned secret'])->save();

        $this->actingAs($owner)
            ->delete(route('recipes.revision.destroy', $recipe))
            ->assertRedirect(route('recipes.show', $recipe));

        $recipe->refresh();
        $this->assertSame($versionId, $recipe->current_recipe_version_id);
        $this->assertSame(1, $recipe->versions()->count());
        $this->assertFalse($recipe->activeRevision()->exists());
        $this->assertSame('Stable title', $recipe->title);
        $this->assertSame(['  2 tbsp olive oil  ', 'salt to taste'], $recipe->ingredientLines()->pluck('original_text')->all());
        $this->assertDatabaseMissing('recipe_ingredient_lines', ['original_text' => 'Draft-only child']);

        auth()->logout();
        $this->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Stable title')
            ->assertDontSee('Abandoned secret');

        $event = AuditEvent::query()->where('action', AuditAction::RecipeRevisionAbandoned)->sole();
        $this->assertSame($revision->id, $event->payload['revision_id']);
    }

    public function test_late_publication_failure_rolls_back_version_switch_revision_removal_and_success_audit(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        $oldVersionId = $recipe->current_recipe_version_id;
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $state = $this->editorState($owner, $recipe->fresh());
        $this->app->instance(RecipeFinalizationHook::class, new class implements RecipeFinalizationHook
        {
            public function beforeCommit(Recipe $recipe, RecipeVersion $version): void
            {
                throw new RuntimeException('Injected late publication failure.');
            }
        });
        $this->actingAs($owner);

        try {
            app(RecipeRevisionPublisher::class)->publish(
                $recipe->id,
                $state['baseline'],
                [...$state['metadata'], 'title' => 'Rolled back title'],
                $state['ingredients'],
                $state['sections'],
                $state['steps'],
                $owner,
            );
            $this->fail('The injected late failure did not abort publication.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected late publication failure.', $exception->getMessage());
        }

        $recipe->refresh();
        $this->assertSame($oldVersionId, $recipe->current_recipe_version_id);
        $this->assertSame(1, $recipe->versions()->count());
        $this->assertTrue($recipe->activeRevision()->exists());
        $this->assertFalse(AuditEvent::query()->where('action', AuditAction::RecipeRevisionPublished)->exists());
    }

    public function test_stale_base_cannot_overwrite_a_newer_current_version(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $state = $this->editorState($owner, $recipe->fresh());

        $versionTwo = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 2,
            'snapshot' => [...$this->snapshot(), 'title' => 'Independent version two'],
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $versionTwo->id])->save();
        $this->actingAs($owner);

        try {
            app(RecipeRevisionPublisher::class)->publish(
                $recipe->id,
                $state['baseline'],
                $state['metadata'],
                $state['ingredients'],
                $state['sections'],
                $state['steps'],
                $owner,
            );
            $this->fail('A stale revision was published.');
        } catch (StaleRecipeRevision) {
            // Expected: the transaction rejects a revision whose explicit base is no longer current.
        }

        $recipe->refresh();
        $this->assertSame($versionTwo->id, $recipe->current_recipe_version_id);
        $this->assertSame(2, $recipe->versions()->count());
        $this->assertTrue($recipe->activeRevision()->exists());
        $this->assertFalse(AuditEvent::query()->where('action', AuditAction::RecipeRevisionPublished)->exists());
    }

    public function test_database_and_transaction_boundaries_prevent_duplicate_active_drafts_and_publish_replay(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        $manager = app(RecipeRevisionManager::class);
        $first = $manager->startOrResume($recipe->id, $owner);
        $second = $manager->startOrResume($recipe->id, $owner);
        $this->assertSame($first->id, $second->id);

        $duplicate = new RecipeDraftRevision;
        $duplicate->forceFill(['base_recipe_version_id' => $recipe->current_recipe_version_id]);
        $duplicate->recipe()->associate($recipe);
        try {
            $duplicate->save();
            $this->fail('The one-active-revision unique constraint was not enforced.');
        } catch (QueryException) {
            $this->assertSame(1, $recipe->activeRevision()->count());
        }

        $state = $this->editorState($owner, $recipe->fresh());
        $this->actingAs($owner);
        $publisher = app(RecipeRevisionPublisher::class);
        $published = $publisher->publish($recipe->id, $state['baseline'], $state['metadata'], $state['ingredients'], $state['sections'], $state['steps'], $owner);
        $replayed = $publisher->publish($recipe->id, $state['baseline'], $state['metadata'], $state['ingredients'], $state['sections'], $state['steps'], $owner);

        $this->assertSame($published->id, $replayed->id);
        $this->assertSame([1, 2], $recipe->versions()->pluck('version_number')->all());
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::RecipeRevisionPublished)->count());
    }

    public function test_factory_states_create_valid_revision_and_history_shapes(): void
    {
        $publicWithDraft = Recipe::factory()->publicFinalizedWithActiveRevision()->create();
        $privateWithDraft = Recipe::factory()->privateFinalizedWithActiveRevision()->create();
        $history = Recipe::factory()->withMultipleHistoricalVersions()->create();
        $staleDraft = Recipe::factory()->withDraftBasedOnPreviousVersion()->create();
        $standaloneRevision = RecipeDraftRevision::factory()->create();

        $this->assertSame(RecipeVisibility::Public, $publicWithDraft->visibility);
        $this->assertTrue($publicWithDraft->activeRevision()->exists());
        $this->assertSame(RecipeVisibility::Private, $privateWithDraft->visibility);
        $this->assertTrue($privateWithDraft->activeRevision()->exists());
        $this->assertSame([1, 2], $history->versions()->pluck('version_number')->all());
        $this->assertSame(2, $history->currentVersion()->sole()->version_number);
        $this->assertSame(1, $staleDraft->activeRevision()->sole()->baseVersion()->sole()->version_number);
        $this->assertSame(2, $staleDraft->currentVersion()->sole()->version_number);
        $this->assertSame(
            $standaloneRevision->recipe_id,
            $standaloneRevision->baseVersion()->sole()->recipe_id,
        );
    }

    public function test_deleting_durable_recipe_cascades_revision_and_versions_without_crossing_on_abandon(): void
    {
        $recipe = Recipe::factory()->publicFinalizedWithActiveRevision()->create();
        $versionId = $recipe->current_recipe_version_id;
        $revisionId = $recipe->activeRevision()->sole()->id;

        $recipe->delete();

        $this->assertDatabaseMissing('recipe_draft_revisions', ['id' => $revisionId]);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $versionId]);
    }

    public function test_finalized_versions_reject_application_mutation_and_later_draft_edits_do_not_change_history(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner);
        $version = $recipe->currentVersion()->sole();
        $snapshot = $version->snapshot;

        try {
            $version->forceFill(['snapshot' => [...$snapshot, 'title' => 'Mutated history']])->save();
            $this->fail('Finalized version metadata was mutable.');
        } catch (LogicException) {
            $this->assertSame($snapshot, $version->fresh()->snapshot);
        }

        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $recipe->ingredientLines()->first()->update(['original_text' => 'Draft edit']);
        $recipe->instructionSteps()->first()->update(['text' => 'Draft instruction edit']);
        $this->assertSame($snapshot, $version->fresh()->snapshot);
    }

    /** @return array{baseline: string, metadata: array, ingredients: array, sections: array, steps: array} */
    private function editorState(User $owner, Recipe $recipe): array
    {
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);

        return [
            'baseline' => $component->get('baselineFingerprint'),
            'metadata' => [
                'title' => $component->get('title'),
                'servings' => $component->get('servings'),
                'visibility' => $component->get('visibility'),
            ],
            'ingredients' => $component->get('ingredients'),
            'sections' => $component->get('sections'),
            'steps' => $component->get('steps'),
        ];
    }

    private function finalizedRecipe(User $owner, RecipeVisibility $visibility = RecipeVisibility::Public): Recipe
    {
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Mutable placeholder',
            'servings' => '2.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => $visibility,
            'finalized_at' => now()->utc(),
        ]);
        $version = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 1,
            'visibility' => $visibility,
            'snapshot' => [...$this->snapshot(), 'visibility' => $visibility->value],
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();

        return $recipe->fresh();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'title' => 'Stable title',
            'servings' => '4.00',
            'visibility' => 'public',
            'ingredients' => [
                ['position' => 0, 'original_text' => '  2 tbsp olive oil  ', 'quantity' => '12.500000000000000000', 'standard_unit' => 'gram', 'custom_unit' => null, 'generic_wording' => 'olive oil', 'notes' => 'exact note'],
                ['position' => 1, 'original_text' => 'salt to taste', 'quantity' => null, 'standard_unit' => null, 'custom_unit' => null, 'generic_wording' => null, 'notes' => null],
            ],
            'sections' => [
                ['key' => 'prepare', 'position' => 0, 'name' => 'Prepare'],
                ['key' => 'cook', 'position' => 1, 'name' => 'Cook'],
            ],
            'steps' => [
                ['position' => 0, 'text' => '  Keep this spacing.  ', 'section_key' => 'prepare'],
                ['position' => 1, 'text' => 'Cook gently.', 'section_key' => 'cook'],
            ],
        ];
    }
}
