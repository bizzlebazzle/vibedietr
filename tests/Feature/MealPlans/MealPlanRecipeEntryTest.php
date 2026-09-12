<?php

namespace Tests\Feature\MealPlans;

use App\Audit\Enums\AuditAction;
use App\Domain\Nutrition\RecipeNutritionSource;
use App\Domain\Nutrition\RecipeNutritionSourceSelector;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\AuditEvent;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\RecipeNutritionOverrideEvent;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MealPlanRecipeEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_adds_finalized_recipe_with_planned_servings_and_pinned_snapshots(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();
        [$recipe, $version] = $this->finalizedRecipe($owner, RecipeVisibility::Private);

        $this->actingAs($owner)
            ->post(route('meal-plans.recipe-entries.store', $plan), [
                'slot_id' => $slot->id,
                'recipe_id' => $recipe->id,
                'planned_servings' => '1.50',
                'recipe_version_id' => (string) Str::ulid(),
                'recipe_snapshot' => ['title' => 'Forged'],
                'nutrition_snapshot' => ['source' => 'forged'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $entry = MealPlanRecipeEntry::query()->sole();
        $this->assertTrue($entry->slot->is($slot));
        $this->assertSame($recipe->id, $entry->recipe_id);
        $this->assertSame($version->id, $entry->recipe_version_id);
        $this->assertSame(1, $entry->recipe_version_number);
        $this->assertSame('1.50', $entry->planned_servings);
        $this->assertEquals($version->snapshot, $entry->recipe_snapshot);
        $this->assertSame('ingredient_estimate', $entry->nutrition_snapshot['source']);
        $this->assertEquals(
            $version->snapshot['nutrition_estimate'],
            $entry->nutrition_snapshot['ingredient_estimate'],
        );
        $this->assertEquals(
            $version->snapshot['nutrition_estimate']['per_serving'],
            $entry->nutrition_snapshot['values'],
        );

        $audit = AuditEvent::query()->where('action', AuditAction::PlanSnapshotRecorded->value)->sole();
        $this->assertSame('plan-entry:'.$entry->id, $audit->subject_identifier);
        $this->assertEquals(['outcome' => 'recorded', 'snapshot_kind' => 'planned'], $audit->payload);

        $this->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Pinned soup')
            ->assertSee('1.50 planned servings')
            ->assertSee('version 1');
    }

    public function test_entry_moves_and_is_removed_without_changing_its_pinned_data(): void
    {
        [$owner, $plan, , $source, $target] = $this->planWithSlots();
        [$recipe] = $this->finalizedRecipe($owner, RecipeVisibility::Private);
        $this->actingAs($owner)->post(route('meal-plans.recipe-entries.store', $plan), [
            'slot_id' => $source->id,
            'recipe_id' => $recipe->id,
            'planned_servings' => '2.25',
        ]);
        $entry = MealPlanRecipeEntry::query()->sole();
        $pinned = $entry->only([
            'recipe_id',
            'recipe_version_id',
            'recipe_version_number',
            'planned_servings',
            'recipe_snapshot',
            'nutrition_snapshot',
        ]);

        $this->patch(route('meal-plans.recipe-entries.update', [$plan, $entry]), [
            'target_slot_id' => $target->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertTrue($entry->slot->is($target));
        $this->assertSame($pinned, $entry->only(array_keys($pinned)));

        $this->delete(route('meal-plans.recipe-entries.destroy', [$plan, $entry]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('meal_plan_recipe_entries', ['id' => $entry->id]);
    }

    public function test_draft_recipes_and_invalid_planned_servings_are_rejected(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();
        $draft = Recipe::factory()->for($owner, 'owner')->create();
        $this->actingAs($owner);

        $this->post(route('meal-plans.recipe-entries.store', $plan), [
            'slot_id' => $slot->id,
            'recipe_id' => $draft->id,
            'planned_servings' => '1.00',
        ])->assertRedirect()->assertSessionHasErrors('recipe_id');

        [$recipe] = $this->finalizedRecipe($owner, RecipeVisibility::Private);
        foreach (['0', '-1', '1.234', '100000000'] as $invalid) {
            $this->post(route('meal-plans.recipe-entries.store', $plan), [
                'slot_id' => $slot->id,
                'recipe_id' => $recipe->id,
                'planned_servings' => $invalid,
            ])->assertRedirect()->assertSessionHasErrors('planned_servings');
        }

        $this->assertDatabaseCount('meal_plan_recipe_entries', 0);
    }

    public function test_database_rejects_zero_planned_servings(): void
    {
        [, , , $slot] = $this->planWithSlots();
        $this->expectException(QueryException::class);

        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create(['planned_servings' => '0.00']);
    }

    public function test_later_recipe_publication_and_nutrition_override_do_not_change_existing_entry(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();
        [$recipe, $versionOne] = $this->finalizedRecipe($owner, RecipeVisibility::Private);
        $this->actingAs($owner)->post(route('meal-plans.recipe-entries.store', $plan), [
            'slot_id' => $slot->id,
            'recipe_id' => $recipe->id,
            'planned_servings' => '1.00',
        ])->assertSessionHasNoErrors();
        $entry = MealPlanRecipeEntry::query()->sole();
        $pinnedRecipe = $entry->recipe_snapshot;
        $pinnedNutrition = $entry->nutrition_snapshot;

        $versionTwo = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 2,
            'visibility' => RecipeVisibility::Private,
            'snapshot' => [
                ...$versionOne->snapshot,
                'title' => 'Replacement soup',
                'nutrition_estimate' => [
                    ...$versionOne->snapshot['nutrition_estimate'],
                    'per_serving' => ['energy_kcal' => $this->nutrientFact('999')],
                ],
            ],
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $versionTwo->id])->save();

        $override = new RecipeNutritionOverrideEvent;
        $override->forceFill([
            'recipe_version_id' => $versionOne->id,
            'actor_user_id' => $owner->id,
            'event' => 'added',
            'prior_source' => RecipeNutritionSource::IngredientEstimate,
            'resulting_source' => RecipeNutritionSource::CreatorOverride,
            'prior_values' => $versionOne->snapshot['nutrition_estimate']['per_serving'],
            'resulting_values' => ['energy_kcal' => $this->nutrientFact('555', false)],
            'note' => null,
            'occurred_at' => now()->utc(),
            'audit_event_id' => (string) Str::ulid(),
        ]);
        $override->save();

        $this->assertSame(
            RecipeNutritionSource::CreatorOverride,
            app(RecipeNutritionSourceSelector::class)->effective($versionOne)['source'],
        );
        $entry->refresh();
        $this->assertSame($versionOne->id, $entry->recipe_version_id);
        $this->assertSame(1, $entry->recipe_version_number);
        $this->assertSame($pinnedRecipe, $entry->recipe_snapshot);
        $this->assertSame($pinnedNutrition, $entry->nutrition_snapshot);

        $this->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Pinned soup')
            ->assertDontSee('Replacement soup');

        $recipe->delete();
        $this->assertDatabaseHas('meal_plan_recipe_entries', ['id' => $entry->id]);
        $this->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Pinned soup');
    }

    public function test_pinned_snapshot_fields_cannot_be_changed_after_creation(): void
    {
        $entry = MealPlanRecipeEntry::factory()->create();
        $entry->recipe_version_number = 2;

        $this->expectException(LogicException::class);
        $entry->save();
    }

    public function test_private_recipe_and_nested_plan_authorization_remain_owner_scoped(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();
        $other = User::factory()->create();
        [$otherPrivate] = $this->finalizedRecipe($other, RecipeVisibility::Private);
        [$otherPublic] = $this->finalizedRecipe($other, RecipeVisibility::Public);

        $this->actingAs($owner)->post(route('meal-plans.recipe-entries.store', $plan), [
            'slot_id' => $slot->id,
            'recipe_id' => $otherPrivate->id,
            'planned_servings' => '1.00',
        ])->assertNotFound();
        $this->assertDatabaseCount('meal_plan_recipe_entries', 0);

        $this->post(route('meal-plans.recipe-entries.store', $plan), [
            'slot_id' => $slot->id,
            'recipe_id' => $otherPublic->id,
            'planned_servings' => '1.00',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $entry = MealPlanRecipeEntry::query()->sole();

        $this->actingAs($other)->patch(route('meal-plans.recipe-entries.update', [$plan, $entry]), [
            'target_slot_id' => $slot->id,
        ])->assertNotFound();
        $this->delete(route('meal-plans.recipe-entries.destroy', [$plan, $entry]))->assertNotFound();
        $this->assertDatabaseHas('meal_plan_recipe_entries', ['id' => $entry->id]);

        auth()->logout();
        $this->post(route('meal-plans.recipe-entries.store', $plan), [])->assertRedirect(route('login'));
    }

    /** @return array{User, MealPlan, MealPlanDay, MealPlanSlot, MealPlanSlot} */
    private function planWithSlots(): array
    {
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $day = new MealPlanDay;
        $day->forceFill(['day_index' => 0, 'date' => null]);
        $day->mealPlan()->associate($plan);
        $day->save();
        $source = new MealPlanSlot;
        $source->forceFill(['standard_key' => null, 'name' => 'Breakfast', 'position' => 0]);
        $source->day()->associate($day);
        $source->save();
        $target = new MealPlanSlot;
        $target->forceFill(['standard_key' => null, 'name' => 'Dinner', 'position' => 1]);
        $target->day()->associate($day);
        $target->save();

        return [$owner, $plan, $day, $source, $target];
    }

    /** @return array{Recipe, RecipeVersion} */
    private function finalizedRecipe(User $owner, RecipeVisibility $visibility): array
    {
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'title' => 'Mutable recipe title',
            'servings' => '2.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => $visibility,
            'finalized_at' => now()->utc(),
        ]);
        $estimate = [
            'type' => 'estimate',
            'is_estimate' => true,
            'calculation_policy_version' => 1,
            'whole_recipe' => ['energy_kcal' => $this->nutrientFact('200')],
            'per_serving' => ['energy_kcal' => $this->nutrientFact('100')],
            'inputs' => [[
                'ingredient_position' => 0,
                'quantity' => '100.00',
                'standard_unit' => 'g',
                'custom_unit' => null,
                'catalogue_item_version_id' => (string) Str::ulid(),
                'contributions' => [],
                'exclusions' => [],
            ]],
        ];
        $version = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 1,
            'visibility' => $visibility,
            'snapshot' => [
                'title' => 'Pinned soup',
                'servings' => '2.00',
                'visibility' => $visibility->value,
                'ingredients' => [['position' => 0, 'original_text' => '100 g beans']],
                'sections' => [],
                'steps' => [['position' => 0, 'text' => 'Cook', 'section_key' => null]],
                'nutrition_estimate' => $estimate,
                'imported_nutrition' => null,
            ],
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();

        return [$recipe, $version];
    }

    /** @return array{value: string, unit: string, basis: string, status: string, is_estimate: bool} */
    private function nutrientFact(string $value, bool $estimate = true): array
    {
        return [
            'value' => $value,
            'unit' => 'kcal',
            'basis' => 'per_serving',
            'status' => $estimate ? 'approximate' : 'known',
            'is_estimate' => $estimate,
        ];
    }
}
