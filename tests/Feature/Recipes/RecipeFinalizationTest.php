<?php

namespace Tests\Feature\Recipes;

use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditPurpose;
use App\Audit\Enums\AuditRetentionClass;
use App\Domain\Recipes\RecipeFinalizationHook;
use App\Domain\Recipes\RecipeFinalizer;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Livewire\Recipes\Form;
use App\Models\AuditEvent;
use App\Models\Recipe;
use App\Models\RecipeInstructionStep;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class RecipeFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_draft_saves_visible_state_and_finalizes_public_by_default(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create(['title' => 'Persisted title']);

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->set('title', 'Visible unsaved title')
            ->call('finalize')
            ->assertHasNoErrors()
            ->assertRedirect(route('recipes.show', $recipe));

        $recipe->refresh();
        $version = $recipe->currentVersion()->sole();
        $this->assertSame(RecipeLifecycle::Finalized, $recipe->lifecycle);
        $this->assertSame(RecipeVisibility::Public, $recipe->visibility);
        $this->assertSame('Visible unsaved title', $recipe->title);
        $this->assertSame('Visible unsaved title', $version->snapshot['title'] ?? null);
        $this->assertSame($version->id, $recipe->current_recipe_version_id);
        $this->assertTrue(Str::isUlid($version->id));
        $this->assertNotNull($recipe->finalized_at);
    }

    public function test_explicit_private_finalization_is_finalized_and_private(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->set('visibility', RecipeVisibility::Private->value)
            ->call('finalize')
            ->assertHasNoErrors();

        $recipe->refresh();
        $this->assertTrue($recipe->isFinalized());
        $this->assertSame(RecipeVisibility::Private, $recipe->visibility);
        $this->assertSame(RecipeVisibility::Private, $recipe->currentVersion()->sole()->visibility);
    }

    public function test_missing_title_null_zero_and_negative_servings_block_finalization(): void
    {
        $owner = User::factory()->create();

        foreach ([
            'missing title' => [['title' => ''], 'title'],
            'null servings' => [['servings' => null], 'servings'],
            'zero servings' => [['servings' => '0'], 'servings'],
            'negative servings' => [['servings' => '-1'], 'servings'],
        ] as [$attributes, $field]) {
            $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create($attributes);

            Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
                ->call('finalize')
                ->assertHasErrors([$field]);

            $this->assertDraftWithoutVersion($recipe);
        }
    }

    public function test_missing_ingredient_or_instruction_blocks_finalization(): void
    {
        $owner = User::factory()->create();
        $noIngredients = Recipe::factory()->for($owner, 'owner')->withoutIngredientLines()->create();
        $noSteps = Recipe::factory()->for($owner, 'owner')->withoutInstructionSteps()->create();

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $noIngredients])
            ->call('finalize')->assertHasErrors(['ingredients']);
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $noSteps])
            ->call('finalize')->assertHasErrors(['steps']);

        $this->assertDraftWithoutVersion($noIngredients);
        $this->assertDraftWithoutVersion($noSteps);
    }

    public function test_blank_or_deleted_instruction_never_counts(): void
    {
        $owner = User::factory()->create();
        $blank = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $blank->instructionSteps()->update(['text' => '   ']);
        $state = $this->editorState($owner, $blank);

        $this->actingAs($owner);
        try {
            $this->finalizeDirect($owner, $blank, $state);
            $this->fail('A blank authoritative instruction must not finalize.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('steps', $exception->errors());
        }
        $this->assertDraftWithoutVersion($blank);

        $deleted = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $deleted]);
        $deleted->instructionSteps()->delete();
        $component->call('finalize')->assertHasErrors(['conflict']);
        $this->assertDraftWithoutVersion($deleted);
    }

    public function test_snapshot_preserves_authoritative_order_and_section_grouping(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')
            ->validDraft()->withIngredientLine(['original_text' => 'second', 'position' => 1])
            ->withInstructionSection(['name' => 'Finish'])->create();
        $section = $recipe->instructionSections()->sole();
        $firstStep = $recipe->instructionSteps()->sole();
        $firstStep->forceFill(['section_id' => $section->id])->save();
        RecipeInstructionStep::factory()->for($recipe)->create(['text' => 'Second step', 'position' => 1]);

        $state = $this->editorState($owner, $recipe);
        $ingredients = array_reverse($state['ingredients']);
        $steps = array_reverse($state['steps']);
        $this->actingAs($owner);
        $version = app(RecipeFinalizer::class)->finalize(
            $recipe->id,
            $state['baseline'],
            $state['metadata'],
            $ingredients,
            $state['sections'],
            $steps,
            $owner,
        );

        $snapshot = $version->snapshot;
        $this->assertSame(['second', $state['ingredients'][0]['original_text']], array_column($snapshot['ingredients'], 'original_text'));
        $this->assertSame(['Second step', $state['steps'][0]['text']], array_column($snapshot['steps'], 'text'));
        $this->assertSame('Finish', $snapshot['sections'][0]['name']);
        $this->assertNull($snapshot['steps'][0]['section_key']);
        $this->assertSame($snapshot['sections'][0]['key'], $snapshot['steps'][1]['section_key']);
    }

    public function test_repeated_finalization_is_idempotent_and_version_is_immutable(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $state = $this->editorState($owner, $recipe);
        $this->actingAs($owner);

        $first = $this->finalizeDirect($owner, $recipe, $state);
        $second = $this->finalizeDirect($owner, $recipe, $state);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('recipe_versions', 1);
        $this->expectException(\LogicException::class);
        $first->forceFill(['version_number' => 2])->save();
    }

    public function test_owner_only_authorization_is_rechecked_for_guest_non_owner_and_forged_identifier(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $foreign = Recipe::factory()->for($other, 'owner')->validDraft()->create();

        Livewire::actingAs($other)->test(Form::class, ['recipe' => $recipe])->assertForbidden();
        auth()->logout();
        Livewire::test(Form::class, ['recipe' => $recipe])->assertForbidden();

        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $component->set('recipeId', $foreign->id)->call('finalize')->assertForbidden();

        try {
            Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])->set('user_id', $other->id);
            $this->fail('Owner input must not be exposed.');
        } catch (PublicPropertyNotFoundException) {
            $this->assertSame($owner->id, $recipe->fresh()->user_id);
        }

        $this->assertDraftWithoutVersion($recipe);
        $this->assertDraftWithoutVersion($foreign);
    }

    public function test_recipe_or_child_change_after_mount_rejects_finalization_without_audit(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $recipe->ingredientLines()->sole()->update(['original_text' => 'Changed elsewhere']);

        $component->call('finalize')->assertHasErrors(['conflict']);

        $this->assertDraftWithoutVersion($recipe);

        $metadataChanged = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $metadataComponent = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $metadataChanged]);
        $metadataChanged->update(['title' => 'Changed elsewhere']);

        $metadataComponent->call('finalize')->assertHasErrors(['conflict']);

        $this->assertDraftWithoutVersion($metadataChanged);
        $this->assertDatabaseMissing('audit_events', ['action' => AuditAction::RecipeFinalized->value]);
    }

    public function test_failure_after_audit_rolls_back_editor_version_lifecycle_and_event(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create(['title' => 'Before']);
        $this->app->instance(RecipeFinalizationHook::class, new class implements RecipeFinalizationHook
        {
            public function beforeCommit(Recipe $recipe, RecipeVersion $version): void
            {
                throw new RuntimeException('Deterministic finalization failure.');
            }
        });

        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->set('title', 'Must roll back')
            ->call('finalize')
            ->assertHasErrors(['finalize']);

        $this->assertSame('Before', $recipe->fresh()->title);
        $this->assertDraftWithoutVersion($recipe);
        $this->assertDatabaseMissing('audit_events', ['action' => AuditAction::RecipeFinalized->value]);
    }

    public function test_success_audit_is_allowlisted_minimized_and_private_differs_only_by_visibility(): void
    {
        $owner = User::factory()->create();

        foreach ([RecipeVisibility::Public, RecipeVisibility::Private] as $visibility) {
            $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create(['visibility' => $visibility]);
            $state = $this->editorState($owner, $recipe);
            $this->actingAs($owner);
            $version = $this->finalizeDirect($owner, $recipe, $state);
            $event = AuditEvent::query()->where('action', AuditAction::RecipeFinalized)->where('subject_identifier', 'recipe:'.$recipe->id)->sole();

            $this->assertSame(AuditPurpose::ProductHistory, $event->purpose);
            $this->assertSame(AuditRetentionClass::PrivateContentUntilFinalPurge, $event->retention_class);
            $this->assertSame([
                'event' => 'finalized',
                'outcome' => 'completed',
                'version_id' => $version->id,
                'visibility' => $visibility->value,
            ], $event->payload);
            $this->assertSame(['event', 'outcome', 'version_id', 'visibility'], array_keys($event->payload));
            $this->assertStringNotContainsString($recipe->title, json_encode($event->payload));
            $this->assertTrue($event->hasValidIntegrityHash());
        }
    }

    public function test_lifecycle_visibility_and_plan_eligibility_are_independent(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $publicDraft = Recipe::factory()->for($owner, 'owner')->create(['visibility' => RecipeVisibility::Public]);
        $public = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();
        $private = Recipe::factory()->for($owner, 'owner')->finalizedPrivate()->create();

        $this->assertFalse($publicDraft->isFinalized());
        $this->assertFalse($publicDraft->canBeUsedInPlansFor($owner));
        $this->assertTrue($public->fresh()->canBeUsedInPlansFor(null));
        $this->assertTrue($public->fresh()->canBeUsedInPlansFor($other));
        $this->assertTrue($private->fresh()->canBeUsedInPlansFor($owner));
        $this->assertFalse($private->fresh()->canBeUsedInPlansFor($other));
        $this->assertFalse($private->fresh()->canBeUsedInPlansFor(null));
        $this->assertEqualsCanonicalizing([$public->id, $private->id], Recipe::query()->finalized()->pluck('id')->all());
        $this->assertFalse(Recipe::query()->finalized()->whereKey($publicDraft)->exists());
    }

    /** @return array{baseline: string, metadata: array{title: string, servings: mixed, visibility: string}, ingredients: array, sections: array, steps: array} */
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

    /** @param array{baseline: string, metadata: array, ingredients: array, sections: array, steps: array} $state */
    private function finalizeDirect(User $owner, Recipe $recipe, array $state): RecipeVersion
    {
        return app(RecipeFinalizer::class)->finalize(
            $recipe->id,
            $state['baseline'],
            $state['metadata'],
            $state['ingredients'],
            $state['sections'],
            $state['steps'],
            $owner,
        );
    }

    private function assertDraftWithoutVersion(Recipe $recipe): void
    {
        $recipe->refresh();
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
        $this->assertNull($recipe->current_recipe_version_id);
        $this->assertNull($recipe->finalized_at);
        $this->assertFalse($recipe->versions()->exists());
    }
}
