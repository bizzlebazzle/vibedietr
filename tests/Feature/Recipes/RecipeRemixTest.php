<?php

namespace Tests\Feature\Recipes;

use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditPurpose;
use App\Audit\Enums\AuditRetentionClass;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeRemixCreationHook;
use App\Domain\Recipes\RecipeRemixCreator;
use App\Domain\Recipes\RecipeVisibility;
use App\Livewire\Recipes\Form;
use App\Models\AuditEvent;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use App\Models\RecipeRemixLineage;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class RecipeRemixTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_remixes_exact_public_version_into_independent_private_draft(): void
    {
        $sourceOwner = User::factory()->create(['name' => 'Private account name', 'email' => 'source@example.test']);
        $remixer = User::factory()->create();
        $source = $this->sourceRecipe($sourceOwner);
        $sourceVersion = $source->currentVersion()->sole();
        $sourceLineIds = $source->ingredientLines()->pluck('id')->all();
        $sourceSectionIds = $source->instructionSections()->pluck('id')->all();
        $sourceStepIds = $source->instructionSteps()->pluck('id')->all();
        $operationId = (string) Str::ulid();

        $response = $this->actingAs($remixer)->post(route('recipes.remix.store', $source), [
            'source_version_id' => $sourceVersion->id,
            'operation_id' => $operationId,
        ]);

        $lineage = RecipeRemixLineage::query()->sole();
        $remix = $lineage->remixRecipe()->sole();
        $response->assertRedirect(route('recipes.edit', $remix));
        $this->assertNotSame($source->id, $remix->id);
        $this->assertSame($remixer->id, $remix->user_id);
        $this->assertSame(RecipeLifecycle::Draft, $remix->lifecycle);
        $this->assertSame(RecipeVisibility::Private, $remix->visibility);
        $this->assertNull($remix->current_recipe_version_id);
        $this->assertNull($remix->finalized_at);
        $this->assertSame($source->id, $lineage->source_recipe_id);
        $this->assertSame($sourceVersion->id, $lineage->source_recipe_version_id);
        $this->assertSame(1, $lineage->source_version_number);
        $this->assertSame($sourceOwner->id, $lineage->source_creator_user_id);
        $this->assertSame($operationId, $lineage->operation_id);

        $this->assertSame('Exact finalized title', $remix->title);
        $this->assertSame('4.00', $remix->servings);
        $this->assertSame(
            ['  ½ bunch basil — torn  ', '2 tbsp olive oil'],
            $remix->ingredientLines()->pluck('original_text')->all(),
        );
        $first = $remix->ingredientLines()->first();
        $this->assertSame('0.500000000000000000', $first->quantity);
        $this->assertNull($first->standard_unit);
        $this->assertSame('bunch', $first->custom_unit);
        $this->assertSame('basil', $first->generic_wording);
        $this->assertSame('torn by hand', $first->notes);
        $this->assertSame(['Prepare', 'Finish'], $remix->instructionSections()->pluck('name')->all());
        $this->assertSame(['  Keep exact spacing.  ', 'Serve immediately.'], $remix->instructionSteps()->pluck('text')->all());
        $this->assertSame(
            $remix->instructionSections()->first()?->id,
            $remix->instructionSteps()->first()?->section_id,
        );

        $this->assertEmpty(array_intersect($sourceLineIds, $remix->ingredientLines()->pluck('id')->all()));
        $this->assertEmpty(array_intersect($sourceSectionIds, $remix->instructionSections()->pluck('id')->all()));
        $this->assertEmpty(array_intersect($sourceStepIds, $remix->instructionSteps()->pluck('id')->all()));
    }

    public function test_guest_draft_private_and_stale_or_forged_sources_cannot_bypass_authorization(): void
    {
        $sourceOwner = User::factory()->create();
        $remixer = User::factory()->create();
        $public = $this->sourceRecipe($sourceOwner);
        $private = $this->sourceRecipe($sourceOwner, RecipeVisibility::Private);
        $draft = Recipe::factory()->for($sourceOwner, 'owner')->validDraft()->create();

        $this->post(route('recipes.remix.store', $public), $this->requestFor($public))
            ->assertRedirect(route('login'));

        foreach ([$private, $draft] as $inaccessible) {
            $request = $inaccessible->current_recipe_version_id === null
                ? [
                    'source_version_id' => (string) Str::ulid(),
                    'operation_id' => (string) Str::ulid(),
                ]
                : $this->requestFor($inaccessible);
            $this->actingAs($remixer)
                ->post(route('recipes.remix.store', $inaccessible), $request)
                ->assertNotFound();
        }

        $historical = $public->currentVersion()->sole();
        $new = RecipeVersion::factory()->for($public)->create([
            'version_number' => 2,
            'snapshot' => [...$historical->snapshot, 'title' => 'New source title'],
        ]);
        $public->forceFill(['current_recipe_version_id' => $new->id])->save();

        $this->actingAs($remixer)
            ->post(route('recipes.remix.store', $public), [
                'source_version_id' => $historical->id,
                'operation_id' => (string) Str::ulid(),
            ])
            ->assertSessionHasErrors('source_version_id');

        $this->post(route('recipes.remix.store', $public), [
            ...$this->requestFor($public->fresh()),
            'owner_id' => $sourceOwner->id,
            'source_recipe_id' => $private->id,
            'source_version_number' => 99,
        ])->assertSessionHasErrors(['owner_id', 'source_recipe_id', 'source_version_number']);

        $this->assertDatabaseCount('recipe_remix_lineages', 0);
    }

    public function test_owner_can_remix_own_private_finalized_recipe_but_not_a_draft(): void
    {
        $owner = User::factory()->create();
        $private = $this->sourceRecipe($owner, RecipeVisibility::Private);
        $draft = Recipe::factory()->for($owner, 'owner')->validDraft()->create();

        $this->actingAs($owner)
            ->post(route('recipes.remix.store', $private), $this->requestFor($private))
            ->assertRedirect();

        $this->post(route('recipes.remix.store', $draft), [
            'source_version_id' => (string) Str::ulid(),
            'operation_id' => (string) Str::ulid(),
        ])->assertForbidden();

        $this->assertDatabaseCount('recipe_remix_lineages', 1);
    }

    public function test_remixer_alone_edits_the_copy_and_source_content_never_changes(): void
    {
        $sourceOwner = User::factory()->create();
        $remixer = User::factory()->create();
        $other = User::factory()->create();
        $source = $this->sourceRecipe($sourceOwner);
        $sourceSnapshot = $source->currentVersion()->sole()->snapshot;
        $sourceMutableText = $source->ingredientLines()->firstOrFail()->original_text;
        $remix = app(RecipeRemixCreator::class)->create(
            $source->id,
            $source->current_recipe_version_id,
            (string) Str::ulid(),
            $remixer,
        );

        $this->actingAs($remixer)->get(route('recipes.edit', $remix))->assertOk();
        $this->actingAs($sourceOwner)->get(route('recipes.edit', $remix))->assertForbidden();
        $this->actingAs($other)->get(route('recipes.edit', $remix))->assertForbidden();
        auth()->logout();
        $this->get(route('recipes.edit', $remix))->assertRedirect(route('login'));

        Livewire::actingAs($remixer)->test(Form::class, ['recipe' => $remix])
            ->set('title', 'My changed remix')
            ->set('ingredients.0.original_text', 'Changed remix ingredient')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('My changed remix', $remix->fresh()->title);
        $this->assertSame('Changed remix ingredient', $remix->ingredientLines()->firstOrFail()->original_text);
        $this->assertSame($sourceSnapshot, $source->currentVersion()->sole()->snapshot);
        $this->assertSame($sourceMutableText, $source->ingredientLines()->firstOrFail()->original_text);
    }

    public function test_new_source_version_does_not_change_remix_content_or_exact_lineage(): void
    {
        $source = $this->sourceRecipe(User::factory()->create());
        $remixer = User::factory()->create();
        $original = $source->currentVersion()->sole();
        $remix = app(RecipeRemixCreator::class)->create(
            $source->id,
            $original->id,
            (string) Str::ulid(),
            $remixer,
        );
        $lineageBefore = $remix->remixLineage()->sole()->getRawOriginal();

        $new = RecipeVersion::factory()->for($source)->create([
            'version_number' => 2,
            'snapshot' => [...$original->snapshot, 'title' => 'Replacement source', 'ingredients' => []],
        ]);
        $source->forceFill(['current_recipe_version_id' => $new->id])->save();

        $this->assertSame('Exact finalized title', $remix->fresh()->title);
        $this->assertCount(2, $remix->ingredientLines()->get());
        $this->assertEquals($lineageBefore, $remix->remixLineage()->sole()->getRawOriginal());

        $this->actingAs($remixer)->get(route('recipes.show', $remix))
            ->assertOk()
            ->assertSee('Exact finalized title')
            ->assertSee('version 1')
            ->assertDontSee('Replacement source');
    }

    public function test_lineage_is_immutable_and_submitted_lineage_or_owner_fields_are_rejected(): void
    {
        $source = $this->sourceRecipe(User::factory()->create());
        $remixer = User::factory()->create();
        $lineage = app(RecipeRemixCreator::class)->create(
            $source->id,
            $source->current_recipe_version_id,
            (string) Str::ulid(),
            $remixer,
        )->remixLineage()->sole();

        try {
            $lineage->forceFill(['source_recipe_version_id' => (string) Str::ulid()])->save();
            $this->fail('Immutable lineage accepted an update.');
        } catch (LogicException) {
            $this->assertSame($source->current_recipe_version_id, $lineage->fresh()->source_recipe_version_id);
        }

        $this->actingAs($remixer)->post(route('recipes.remix.store', $source), [
            ...$this->requestFor($source),
            'user_id' => User::factory()->create()->id,
            'source_creator_user_id' => $remixer->id,
            'remix_recipe_id' => $source->id,
        ])->assertSessionHasErrors(['user_id', 'source_creator_user_id', 'remix_recipe_id']);

        $this->assertDatabaseCount('recipe_remix_lineages', 1);
    }

    public function test_private_or_deleted_source_leaves_usable_non_disclosing_tombstone(): void
    {
        $sourceOwner = User::factory()->create(['name' => 'Never expose this owner', 'email' => 'private-source@example.test']);
        $remixer = User::factory()->create();
        $source = $this->sourceRecipe($sourceOwner);
        $remix = app(RecipeRemixCreator::class)->create(
            $source->id,
            $source->current_recipe_version_id,
            (string) Str::ulid(),
            $remixer,
        );

        $this->actingAs($remixer)->get(route('recipes.show', $remix))
            ->assertSee('Remixed from')
            ->assertSee('Exact finalized title')
            ->assertDontSee('Never expose this owner')
            ->assertDontSee('private-source@example.test');

        $source->forceFill(['visibility' => RecipeVisibility::Private])->save();

        $this->get(route('recipes.show', $remix))
            ->assertOk()
            ->assertSee('Remixed from an unavailable recipe, version 1')
            ->assertDontSee('Never expose this owner')
            ->assertDontSee('private-source@example.test');
        $this->get(route('recipes.show', $source))->assertNotFound();

        $sourceId = $source->id;
        $source->delete();

        $this->get(route('recipes.show', $remix))
            ->assertOk()
            ->assertSee('Remixed from an unavailable recipe, version 1');
        $this->assertDatabaseMissing('recipes', ['id' => $sourceId]);
        $this->assertDatabaseHas('recipes', ['id' => $remix->id, 'user_id' => $remixer->id]);
        $this->assertDatabaseCount('recipe_ingredient_lines', 2);
        $this->assertDatabaseHas('recipe_remix_lineages', [
            'remix_recipe_id' => $remix->id,
            'source_recipe_id' => $sourceId,
        ]);
    }

    public function test_source_creator_erasure_nulls_personal_reference_without_deleting_remix_or_lineage(): void
    {
        $sourceOwner = User::factory()->create(['name' => 'Erased creator', 'email' => 'erase@example.test']);
        $remixer = User::factory()->create();
        $source = $this->sourceRecipe($sourceOwner);
        $sourceId = $source->id;
        $sourceVersionId = $source->current_recipe_version_id;
        $remix = app(RecipeRemixCreator::class)->create(
            $sourceId,
            $sourceVersionId,
            (string) Str::ulid(),
            $remixer,
        );

        $sourceOwner->delete();

        $lineage = $remix->remixLineage()->sole();
        $this->assertNull($lineage->source_creator_user_id);
        $this->assertSame($sourceId, $lineage->source_recipe_id);
        $this->assertSame($sourceVersionId, $lineage->source_recipe_version_id);
        $this->assertDatabaseHas('recipes', ['id' => $remix->id]);
        $this->assertCount(2, $remix->ingredientLines()->get());
        $this->actingAs($remixer)->get(route('recipes.show', $remix))
            ->assertDontSee('Erased creator')
            ->assertDontSee('erase@example.test');
    }

    public function test_copy_failures_at_each_required_stage_roll_back_recipe_lineage_and_audit(): void
    {
        foreach (['ingredient', 'instruction', 'lineage'] as $stage) {
            $source = $this->sourceRecipe(User::factory()->create());
            $remixer = User::factory()->create();
            $recipeCount = Recipe::query()->count();
            $lineCount = RecipeIngredientLine::query()->count();
            $sectionCount = RecipeInstructionSection::query()->count();
            $stepCount = RecipeInstructionStep::query()->count();
            $this->app->instance(RecipeRemixCreationHook::class, new class($stage) implements RecipeRemixCreationHook
            {
                public function __construct(private readonly string $stage) {}

                public function afterIngredientCopy(Recipe $remix): void
                {
                    $this->failAt('ingredient');
                }

                public function afterInstructionCopy(Recipe $remix): void
                {
                    $this->failAt('instruction');
                }

                public function afterLineageCreation(Recipe $remix, RecipeRemixLineage $lineage): void
                {
                    $this->failAt('lineage');
                }

                private function failAt(string $stage): void
                {
                    if ($this->stage === $stage) {
                        throw new RuntimeException('Deterministic '.$stage.' copy failure.');
                    }
                }
            });

            try {
                app(RecipeRemixCreator::class)->create(
                    $source->id,
                    $source->current_recipe_version_id,
                    (string) Str::ulid(),
                    $remixer,
                );
                $this->fail('Expected remix creation to fail at '.$stage.'.');
            } catch (RuntimeException) {
                $this->assertSame($recipeCount, Recipe::query()->count());
                $this->assertSame($lineCount, RecipeIngredientLine::query()->count());
                $this->assertSame($sectionCount, RecipeInstructionSection::query()->count());
                $this->assertSame($stepCount, RecipeInstructionStep::query()->count());
                $this->assertDatabaseCount('recipe_remix_lineages', 0);
                $this->assertDatabaseMissing('audit_events', ['action' => AuditAction::RecipeRemixed->value]);
            }
        }
    }

    public function test_audit_is_minimized_and_retry_is_idempotent_while_new_operation_creates_new_remix(): void
    {
        $sourceOwner = User::factory()->create(['name' => 'Audit private name', 'email' => 'audit@example.test']);
        $remixer = User::factory()->create();
        $source = $this->sourceRecipe($sourceOwner);
        $creator = app(RecipeRemixCreator::class);
        $operation = (string) Str::ulid();

        $first = $creator->create($source->id, $source->current_recipe_version_id, $operation, $remixer);
        $retry = $creator->create($source->id, $source->current_recipe_version_id, $operation, $remixer);
        $second = $creator->create($source->id, $source->current_recipe_version_id, (string) Str::ulid(), $remixer);

        $this->assertTrue($first->is($retry));
        $this->assertFalse($first->is($second));
        $this->assertDatabaseCount('recipe_remix_lineages', 2);
        $events = AuditEvent::query()->where('action', AuditAction::RecipeRemixed)->get();
        $this->assertCount(2, $events);
        $event = $events->firstWhere('subject_identifier', 'recipe:'.$first->id);
        $this->assertNotNull($event);
        $this->assertSame(AuditPurpose::ProductHistory, $event->purpose);
        $this->assertSame(AuditRetentionClass::PrivateContentUntilFinalPurge, $event->retention_class);
        $this->assertSame([
            'event' => 'remixed',
            'outcome' => 'completed',
            'source_version_id' => $source->current_recipe_version_id,
        ], $event->payload);
        $this->assertSame($operation, $event->correlation_id);
        $encoded = json_encode($event->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Audit private name', $encoded);
        $this->assertStringNotContainsString('audit@example.test', $encoded);
        $this->assertStringNotContainsString('½ bunch basil', $encoded);
        $this->assertStringNotContainsString('Keep exact spacing', $encoded);
        $this->assertTrue($event->hasValidIntegrityHash());
    }

    public function test_factory_supports_public_private_deleted_erased_and_newer_version_sources(): void
    {
        $public = RecipeRemixLineage::factory()->fromPublicFinalizedSource()->create();
        $accessiblePrivate = RecipeRemixLineage::factory()->fromAccessiblePrivateFinalizedSource()->create();
        $private = RecipeRemixLineage::factory()->whoseSourceIsPrivate()->create();
        $deleted = RecipeRemixLineage::factory()->whoseSourceIsDeleted()->create();
        $erased = RecipeRemixLineage::factory()->whoseSourceCreatorIsDeleted()->create();
        $newer = RecipeRemixLineage::factory()->whoseSourceHasNewerFinalizedVersion()->create();

        $this->assertTrue(Recipe::query()->findOrFail($public->source_recipe_id)->isPubliclyViewable());
        $this->assertSame(
            Recipe::query()->findOrFail($accessiblePrivate->source_recipe_id)->user_id,
            $accessiblePrivate->remixRecipe()->sole()->user_id,
        );
        $this->assertSame(
            RecipeVisibility::Private,
            Recipe::query()->findOrFail($accessiblePrivate->source_recipe_id)->visibility,
        );
        $this->assertSame(RecipeVisibility::Private, Recipe::query()->findOrFail($private->source_recipe_id)->visibility);
        $this->assertFalse(Recipe::query()->whereKey($deleted->source_recipe_id)->exists());
        $this->assertNull($erased->fresh()->source_creator_user_id);
        $this->assertSame(
            2,
            Recipe::query()->findOrFail($newer->source_recipe_id)->currentVersion()->sole()->version_number,
        );
        $this->assertSame(1, $newer->source_version_number);
    }

    /** @return array{source_version_id: string, operation_id: string} */
    private function requestFor(Recipe $recipe): array
    {
        return [
            'source_version_id' => (string) $recipe->current_recipe_version_id,
            'operation_id' => (string) Str::ulid(),
        ];
    }

    private function sourceRecipe(
        User $owner,
        RecipeVisibility $visibility = RecipeVisibility::Public,
    ): Recipe {
        $source = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Mutable source title',
            'servings' => '9.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => $visibility,
            'finalized_at' => now()->utc(),
        ]);
        RecipeIngredientLine::factory()->for($source)->create([
            'position' => 0,
            'original_text' => 'Mutable source ingredient',
        ]);
        RecipeIngredientLine::factory()->for($source)->create([
            'position' => 1,
            'original_text' => 'Another mutable source ingredient',
        ]);
        $prepare = RecipeInstructionSection::factory()->for($source)->create(['name' => 'Mutable prepare', 'position' => 0]);
        RecipeInstructionSection::factory()->for($source)->create(['name' => 'Mutable finish', 'position' => 1]);
        RecipeInstructionStep::factory()->for($source)->create([
            'position' => 0,
            'text' => 'Mutable source step',
            'section_id' => $prepare->id,
        ]);
        RecipeInstructionStep::factory()->for($source)->create([
            'position' => 1,
            'text' => 'Another mutable source step',
        ]);

        $version = RecipeVersion::factory()->for($source)->create([
            'version_number' => 1,
            'visibility' => $visibility,
            'snapshot' => [
                'title' => 'Exact finalized title',
                'servings' => '4.00',
                'visibility' => $visibility->value,
                'ingredients' => [
                    [
                        'position' => 1,
                        'original_text' => '2 tbsp olive oil',
                        'quantity' => '2.000000000000000000',
                        'standard_unit' => 'tablespoon_uk',
                        'custom_unit' => null,
                        'generic_wording' => 'olive oil',
                        'notes' => null,
                    ],
                    [
                        'position' => 0,
                        'original_text' => '  ½ bunch basil — torn  ',
                        'quantity' => '0.500000000000000000',
                        'standard_unit' => null,
                        'custom_unit' => 'bunch',
                        'generic_wording' => 'basil',
                        'notes' => 'torn by hand',
                    ],
                ],
                'sections' => [
                    ['key' => 'finish', 'position' => 1, 'name' => 'Finish'],
                    ['key' => 'prepare', 'position' => 0, 'name' => 'Prepare'],
                ],
                'steps' => [
                    ['position' => 1, 'text' => 'Serve immediately.', 'section_key' => 'finish'],
                    ['position' => 0, 'text' => '  Keep exact spacing.  ', 'section_key' => 'prepare'],
                ],
            ],
            'finalized_at' => now()->utc(),
        ]);
        $source->forceFill(['current_recipe_version_id' => $version->id])->save();

        return $source->fresh();
    }
}
