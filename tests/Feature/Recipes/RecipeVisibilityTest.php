<?php

namespace Tests\Feature\Recipes;

use App\Audit\Enums\AuditAction;
use App\Domain\Recipes\PublicRecipe;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Livewire\Recipes\Form;
use App\Models\AuditEvent;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeInstructionStep;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_finalized_recipe_is_readable_by_guest_non_owner_and_owner_from_stable_version(): void
    {
        $owner = User::factory()->create([
            'email' => 'private-owner@example.test',
            'is_administrator' => true,
            'remember_token' => 'private-security-token',
        ]);
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, RecipeVisibility::Public);

        RecipeIngredientLine::factory()->for($recipe)->create([
            'original_text' => 'Mutable ingredient must not render',
            'position' => 0,
        ]);
        RecipeInstructionStep::factory()->for($recipe)->create([
            'text' => 'Mutable instruction must not render',
            'position' => 0,
        ]);

        foreach ([null, $other, $owner] as $viewer) {
            if ($viewer instanceof User) {
                $this->actingAs($viewer);
            } else {
                auth()->logout();
            }

            $response = $this->get(route('recipes.show', $recipe));

            $response->assertOk()
                ->assertSee('Stable public title')
                ->assertSee('Stable ingredient first')
                ->assertSee('Stable ingredient second')
                ->assertSee('Prepare')
                ->assertSee('Stable instruction')
                ->assertDontSee('Mutable recipe title')
                ->assertDontSee('Mutable ingredient must not render')
                ->assertDontSee('Mutable instruction must not render')
                ->assertDontSee('private-security-token')
                ->assertDontSee('is_administrator')
                ->assertDontSee('recipe.visibility_changed');

            if (! $viewer?->is($owner)) {
                $response->assertDontSee('private-owner@example.test');
            }
        }
    }

    public function test_public_reader_has_no_edit_controls_but_owner_has_visibility_control(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, RecipeVisibility::Public);

        $this->actingAs($other)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertDontSee('Make recipe private')
            ->assertDontSee(route('recipes.edit', $recipe));

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Make recipe private');
    }

    public function test_private_finalized_recipe_is_owner_only_and_denials_hide_content(): void
    {
        $owner = User::factory()->create(['email' => 'private-owner@example.test']);
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, RecipeVisibility::Private, 'Private stable title');

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Private stable title')
            ->assertSee('Stable ingredient first');

        auth()->logout();
        $this->get(route('recipes.show', $recipe))
            ->assertNotFound()
            ->assertDontSee('Private stable title')
            ->assertDontSee('Stable ingredient first')
            ->assertDontSee('private-owner@example.test');

        $this->actingAs($other)->get(route('recipes.show', $recipe))
            ->assertNotFound()
            ->assertDontSee('Private stable title')
            ->assertDontSee('Stable ingredient first')
            ->assertDontSee('private-owner@example.test');
    }

    public function test_draft_is_owner_only_and_public_intent_never_enters_public_scope(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Secret draft title',
            'visibility' => RecipeVisibility::Public,
        ]);
        RecipeIngredientLine::factory()->for($recipe)->create([
            'original_text' => 'Secret draft ingredient',
            'position' => 0,
        ]);

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Secret draft title')
            ->assertSee('Secret draft ingredient')
            ->assertSee('Edit draft');

        auth()->logout();
        $this->get(route('recipes.show', $recipe))
            ->assertNotFound()
            ->assertDontSee('Secret draft title')
            ->assertDontSee('Secret draft ingredient');

        $this->actingAs($other)->get(route('recipes.show', $recipe))
            ->assertNotFound()
            ->assertDontSee('Secret draft title')
            ->assertDontSee('Secret draft ingredient');

        $this->assertFalse($recipe->isPubliclyViewable());
        $this->assertFalse(Recipe::query()->publiclyViewable()->whereKey($recipe)->exists());
    }

    public function test_public_scope_and_viewer_scope_apply_the_authoritative_access_rule(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $public = $this->finalizedRecipe($owner, RecipeVisibility::Public);
        $private = $this->finalizedRecipe($owner, RecipeVisibility::Private, 'Private');
        $draft = Recipe::factory()->for($owner, 'owner')->create();

        $this->assertSame(
            [$public->id],
            Recipe::query()->publiclyViewable()->orderBy('id')->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$public->id, $private->id, $draft->id],
            Recipe::query()->visibleTo($owner)->pluck('id')->all(),
        );
        $this->assertSame(
            [$public->id],
            Recipe::query()->visibleTo($other)->pluck('id')->all(),
        );
        $this->assertSame(
            [$public->id],
            Recipe::query()->visibleTo(null)->pluck('id')->all(),
        );
    }

    public function test_public_projection_has_an_explicit_privacy_safe_shape(): void
    {
        $owner = User::factory()->create([
            'email' => 'private-owner@example.test',
            'is_administrator' => true,
            'remember_token' => 'private-security-token',
        ]);
        $recipe = $this->finalizedRecipe($owner, RecipeVisibility::Public);
        $projection = PublicRecipe::fromCurrentVersion(
            $recipe->fresh()->load('currentVersion'),
        );
        $serialized = $projection->toArray();

        $this->assertSame(
            ['id', 'title', 'servings', 'visibility', 'version', 'attribution', 'ingredients', 'instructions', 'tags', 'classifications'],
            array_keys($serialized),
        );
        $this->assertSame(
            ['id', 'number', 'finalized_at'],
            array_keys($serialized['version']),
        );
        $this->assertSame(
            [
                'position', 'original_text', 'quantity', 'standard_unit',
                'custom_unit', 'generic_wording', 'notes',
            ],
            array_keys($serialized['ingredients'][0]),
        );
        $this->assertSame(
            ['position', 'text', 'section'],
            array_keys($serialized['instructions'][0]),
        );

        $json = json_encode($serialized, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-owner@example.test', $json);
        $this->assertStringNotContainsString('private-security-token', $json);
        $this->assertStringNotContainsString('is_administrator', $json);
        $this->assertStringNotContainsString('user_id', $json);
        $this->assertStringNotContainsString('owner', $json);
        $this->assertSame('private structured note', $serialized['ingredients'][1]['notes']);
    }

    public function test_edit_routes_and_livewire_mutations_remain_creator_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $draft = Recipe::factory()->for($owner, 'owner')->create();
        $public = $this->finalizedRecipe($owner, RecipeVisibility::Public);
        $private = $this->finalizedRecipe($owner, RecipeVisibility::Private, 'Private');

        $this->get(route('recipes.edit', $draft))->assertRedirect(route('login'));
        Livewire::test(Form::class, ['recipe' => $draft])->assertForbidden();

        $this->actingAs($owner)->get(route('recipes.edit', $draft))->assertOk();

        foreach ([$public, $private] as $recipe) {
            $this->actingAs($other)->get(route('recipes.edit', $recipe))->assertForbidden();
            Livewire::actingAs($other)->test(Form::class, ['recipe' => $recipe])->assertForbidden();
        }
    }

    public function test_forged_livewire_identifier_cannot_turn_public_read_into_edit_access(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownedDraft = Recipe::factory()->for($other, 'owner')->create(['title' => 'Owned draft']);
        $public = $this->finalizedRecipe($owner, RecipeVisibility::Public);

        Livewire::actingAs($other)->test(Form::class, ['recipe' => $ownedDraft])
            ->set('recipeId', $public->id)
            ->set('title', 'Forged public edit')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Stable public title', $public->currentVersion()->sole()->snapshot['title']);
        $this->assertSame('Mutable recipe title', $public->fresh()->title);
    }

    public function test_owner_can_make_public_recipe_private_without_changing_or_deleting_versions_or_content(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, RecipeVisibility::Public);
        $versionId = $recipe->current_recipe_version_id;
        RecipeIngredientLine::factory()->for($recipe)->create([
            'original_text' => 'Preserved current ingredient',
            'position' => 0,
        ]);
        RecipeInstructionStep::factory()->for($recipe)->create([
            'text' => 'Preserved current instruction',
            'position' => 0,
        ]);

        $this->get(route('recipes.show', $recipe))->assertOk();

        $this->actingAs($owner)
            ->patch(route('recipes.visibility.update', $recipe), [
                'visibility' => RecipeVisibility::Private->value,
            ])
            ->assertRedirect(route('recipes.show', $recipe));

        $recipe->refresh();
        $this->assertSame(RecipeLifecycle::Finalized, $recipe->lifecycle);
        $this->assertSame(RecipeVisibility::Private, $recipe->visibility);
        $this->assertSame($versionId, $recipe->current_recipe_version_id);
        $this->assertSame(1, $recipe->versions()->count());
        $this->assertSame('Preserved current ingredient', $recipe->ingredientLines()->sole()->original_text);
        $this->assertSame('Preserved current instruction', $recipe->instructionSteps()->sole()->text);

        auth()->logout();
        $this->get(route('recipes.show', $recipe))
            ->assertNotFound()
            ->assertDontSee('Stable public title');

        $this->actingAs($other)->get(route('recipes.show', $recipe))->assertNotFound();
        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Stable public title')
            ->assertSee('Make recipe public');

        $event = AuditEvent::query()
            ->where('action', AuditAction::RecipeVisibilityChanged)
            ->where('subject_identifier', 'recipe:'.$recipe->id)
            ->sole();
        $this->assertSame([
            'event' => 'visibility_changed',
            'outcome' => 'completed',
            'version_id' => $versionId,
            'previous_visibility' => 'public',
            'resulting_visibility' => 'private',
        ], $event->payload);
        $this->assertStringNotContainsString('Stable public title', json_encode($event->payload));
    }

    public function test_owner_can_make_private_recipe_public_again_without_replacing_version(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->finalizedRecipe($owner, RecipeVisibility::Private, 'Private stable title');
        $versionId = $recipe->current_recipe_version_id;

        $this->actingAs($owner)
            ->patch(route('recipes.visibility.update', $recipe), [
                'visibility' => RecipeVisibility::Public->value,
            ])
            ->assertRedirect(route('recipes.show', $recipe));

        $recipe->refresh();
        $this->assertSame(RecipeLifecycle::Finalized, $recipe->lifecycle);
        $this->assertSame(RecipeVisibility::Public, $recipe->visibility);
        $this->assertSame($versionId, $recipe->current_recipe_version_id);
        $this->assertSame(1, $recipe->versions()->count());

        auth()->logout();
        $this->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Private stable title');
    }

    public function test_non_owner_guest_and_draft_owner_cannot_change_visibility(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $public = $this->finalizedRecipe($owner, RecipeVisibility::Public);
        $private = $this->finalizedRecipe($owner, RecipeVisibility::Private, 'Private');
        $draft = Recipe::factory()->for($owner, 'owner')->create();

        foreach ([$public, $private] as $recipe) {
            $this->actingAs($other)
                ->patch(route('recipes.visibility.update', $recipe), [
                    'visibility' => RecipeVisibility::Private->value,
                ])
                ->assertForbidden();
        }

        $this->actingAs($owner)
            ->patch(route('recipes.visibility.update', $draft), [
                'visibility' => RecipeVisibility::Private->value,
            ])
            ->assertForbidden();

        auth()->logout();
        $this->patch(route('recipes.visibility.update', $public), [
            'visibility' => RecipeVisibility::Private->value,
        ])->assertRedirect(route('login'));

        $this->assertSame(RecipeVisibility::Public, $public->fresh()->visibility);
        $this->assertSame(RecipeVisibility::Private, $private->fresh()->visibility);
        $this->assertSame(RecipeLifecycle::Draft, $draft->fresh()->lifecycle);
        $this->assertDatabaseMissing('audit_events', [
            'action' => AuditAction::RecipeVisibilityChanged->value,
        ]);
    }

    public function test_rec05_visibility_and_plan_eligibility_regressions_remain_intact(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $public = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();
        $private = Recipe::factory()->for($owner, 'owner')->finalizedPrivate()->create();
        $draft = Recipe::factory()->for($owner, 'owner')->create([
            'visibility' => RecipeVisibility::Public,
        ]);

        $this->assertSame(RecipeVisibility::Public, $public->visibility);
        $this->assertSame(RecipeVisibility::Private, $private->visibility);
        $this->assertTrue($public->canBeUsedInPlansFor($other));
        $this->assertTrue($private->canBeUsedInPlansFor($owner));
        $this->assertFalse($private->canBeUsedInPlansFor($other));
        $this->assertFalse($draft->canBeUsedInPlansFor($owner));
    }

    private function finalizedRecipe(
        User $owner,
        RecipeVisibility $visibility,
        string $title = 'Stable public title',
    ): Recipe {
        $finalizedAt = now()->utc();
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Mutable recipe title',
            'servings' => '9.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => $visibility,
            'finalized_at' => $finalizedAt,
        ]);
        $version = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 1,
            'visibility' => $visibility,
            'finalized_at' => $finalizedAt,
            'snapshot' => [
                'title' => $title,
                'servings' => '2.00',
                'visibility' => $visibility->value,
                'ingredients' => [
                    [
                        'position' => 1,
                        'original_text' => 'Stable ingredient second',
                        'quantity' => null,
                        'standard_unit' => null,
                        'custom_unit' => null,
                        'generic_wording' => null,
                        'notes' => 'private structured note',
                    ],
                    [
                        'position' => 0,
                        'original_text' => 'Stable ingredient first',
                        'quantity' => null,
                        'standard_unit' => null,
                        'custom_unit' => null,
                        'generic_wording' => null,
                        'notes' => null,
                    ],
                ],
                'sections' => [
                    ['key' => 'section-1', 'position' => 0, 'name' => 'Prepare'],
                ],
                'steps' => [
                    ['position' => 0, 'text' => 'Stable instruction', 'section_key' => 'section-1'],
                ],
            ],
        ]);

        $recipe->forceFill(['current_recipe_version_id' => $version->getKey()])->save();

        return $recipe->fresh();
    }
}
