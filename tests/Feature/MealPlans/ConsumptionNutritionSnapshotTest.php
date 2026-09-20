<?php

namespace Tests\Feature\MealPlans;

use App\Audit\Enums\AuditAction;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\AuditEvent;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\ConsumptionNutritionSnapshot;
use App\Models\DiaryConsumptionTransition;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ConsumptionNutritionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_consumption_captures_nutrition_source_provenance_estimate_status_and_recipe_version(): void
    {
        Date::setTestNow('2026-09-20 12:00:00 UTC');
        [$owner, $plan, $slot] = $this->datedPlan();
        [$recipe, $version] = $this->recipeWithImportedNutrition($owner);

        $this->actingAs($owner)->post(route('meal-plans.recipe-entries.store', $plan), [
            'slot_id' => $slot->id,
            'recipe_id' => $recipe->id,
            'planned_servings' => '1.50',
        ])->assertSessionHasNoErrors();
        $entry = MealPlanRecipeEntry::query()->sole();

        $this->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]))
            ->assertSessionHasNoErrors();

        $transition = DiaryConsumptionTransition::query()->sole();
        $snapshot = ConsumptionNutritionSnapshot::query()->sole();
        $this->assertSame($snapshot->id, $transition->consumption_snapshot_id);
        $this->assertSame('meal_plan_recipe_entry', $snapshot->source_entry_type);
        $this->assertSame($entry->id, $snapshot->source_entry_id);
        $this->assertSame('recipe', $snapshot->item_kind);
        $this->assertSame($recipe->id, $snapshot->source_id);
        $this->assertSame($version->id, $snapshot->source_version_id);
        $this->assertSame(1, $snapshot->source_version_number);
        $this->assertSame('imported_source', $snapshot->nutrition_source);
        $this->assertFalse($snapshot->is_estimate);
        $this->assertSame('1.500000000000000000', $snapshot->actual_amount);
        $this->assertSame('import:PLAN06', $snapshot->nutrition['provenance']['recipe_import_id']);
        $this->assertSame('8', $snapshot->nutrition['values']['protein']['value']);

        $audit = AuditEvent::query()->whereKey($snapshot->audit_event_id)->sole();
        $this->assertSame(AuditAction::PlanSnapshotRecorded, $audit->action);
        $this->assertSame(['outcome' => 'recorded', 'snapshot_kind' => 'consumed'], $audit->payload);
        $this->assertSame('consumption-snapshot:'.$snapshot->id, $audit->subject_identifier);
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', AuditAction::PlanSnapshotRecorded->value)
                ->where('payload->snapshot_kind', 'consumed')
                ->count(),
        );
    }

    public function test_snapshot_does_not_change_after_nutrition_precedence_or_recipe_version_changes(): void
    {
        Date::setTestNow('2026-09-20 12:00:00 UTC');
        [$owner, $plan, $slot] = $this->datedPlan();
        [$recipe, $versionOne] = $this->recipeWithImportedNutrition($owner);
        $this->actingAs($owner)->post(route('meal-plans.recipe-entries.store', $plan), [
            'slot_id' => $slot->id,
            'recipe_id' => $recipe->id,
            'planned_servings' => '1.00',
        ])->assertSessionHasNoErrors();
        $entry = MealPlanRecipeEntry::query()->sole();
        $this->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]))
            ->assertSessionHasNoErrors();
        $snapshot = ConsumptionNutritionSnapshot::query()->sole();
        $original = $snapshot->getRawOriginal();

        $this->put(route('recipes.nutrition-override.update', $recipe), [
            'source_version_id' => $versionOne->id,
            'nutrients' => ['protein' => '99'],
        ])->assertSessionHasNoErrors();
        $versionTwo = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 2,
            'visibility' => RecipeVisibility::Private,
            'snapshot' => [
                ...$versionOne->snapshot,
                'title' => 'Later recipe version',
                'imported_nutrition' => [
                    'per_serving' => ['protein' => $this->nutrient('77', false)],
                    'provenance' => ['recipe_import_id' => 'import:LATER'],
                ],
            ],
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $versionTwo->id])->save();

        $snapshot->refresh();
        $this->assertSame($original, $snapshot->getRawOriginal());
        $this->assertSame($versionOne->id, $snapshot->source_version_id);
        $this->assertSame('imported_source', $snapshot->nutrition_source);
        $this->assertSame('8', $snapshot->nutrition['values']['protein']['value']);

        $snapshot->nutrition = ['source' => 'changed'];
        $this->expectException(LogicException::class);
        $snapshot->save();
    }

    public function test_catalogue_snapshot_keeps_the_consumed_version_after_current_version_changes(): void
    {
        Date::setTestNow('2026-09-20 12:00:00 UTC');
        [$owner, $plan, $slot] = $this->datedPlan();
        $item = CatalogueItem::factory()->approved()->create();
        $versionOne = CatalogueItemVersion::factory()
            ->for($item)
            ->current()
            ->completeNutrition()
            ->create(['version_number' => 1, 'name' => 'Consumed catalogue version']);

        $this->actingAs($owner)->post(route('meal-plans.item-entries.store', $plan), [
            'slot_id' => $slot->id,
            'kind' => 'catalogue',
            'catalogue_item_id' => $item->id,
            'planned_amount' => '125',
            'planned_unit' => StandardUnit::Gram->value,
        ])->assertSessionHasNoErrors();
        $entry = MealPlanItemEntry::query()->sole();
        $this->post(route('meal-plans.consumption.store', [$plan, 'item', $entry]))
            ->assertSessionHasNoErrors();
        $snapshot = ConsumptionNutritionSnapshot::query()->sole();
        $captured = $snapshot->getRawOriginal();

        $versionTwo = CatalogueItemVersion::factory()
            ->for($item)
            ->incompleteNutrition()
            ->create(['version_number' => 2, 'name' => 'Later catalogue version']);
        $item->setCurrentVersion($versionTwo);

        $snapshot->refresh();
        $this->assertSame($captured, $snapshot->getRawOriginal());
        $this->assertSame('catalogue', $snapshot->item_kind);
        $this->assertSame($item->id, $snapshot->source_id);
        $this->assertSame($versionOne->id, $snapshot->source_version_id);
        $this->assertSame(1, $snapshot->source_version_number);
        $this->assertSame('catalogue_version', $snapshot->nutrition_source);
        $this->assertSame($entry->catalogue_nutrition_snapshot, $snapshot->nutrition);
    }

    public function test_corrections_reuse_or_replace_snapshots_and_reconsumption_keeps_the_chain(): void
    {
        Date::setTestNow('2026-09-20 18:00:00 UTC');
        [$owner, $plan, $slot] = $this->datedPlan();
        $entry = MealPlanItemEntry::factory()->for($slot, 'slot')->create([
            'planned_amount' => '2',
            'planned_unit' => StandardUnit::Serving,
            'one_off_nutrition' => [
                'source' => 'one_off_user_entry',
                'basis' => 'per_serving',
                'values' => ['protein' => $this->nutrient('5', false)],
            ],
        ]);

        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'item', $entry]))
            ->assertSessionHasNoErrors();
        $first = DiaryConsumptionTransition::query()->where('sequence', 1)->sole();

        $this->patch(route('meal-plans.consumption.update', [$plan, 'item', $entry]), [
            'consumed_local_at' => '2026-09-20 17:00',
        ])->assertSessionHasNoErrors();
        $timeOnly = DiaryConsumptionTransition::query()->where('sequence', 2)->sole();
        $this->assertSame($first->consumption_snapshot_id, $timeOnly->consumption_snapshot_id);

        $this->patch(route('meal-plans.consumption.update', [$plan, 'item', $entry]), [
            'actual_amount' => '1.25',
        ])->assertSessionHasNoErrors();
        $quantityCorrection = DiaryConsumptionTransition::query()->where('sequence', 3)->sole();
        $this->assertNotSame($first->consumption_snapshot_id, $quantityCorrection->consumption_snapshot_id);

        $this->delete(route('meal-plans.consumption.destroy', [$plan, 'item', $entry]))
            ->assertSessionHasNoErrors();
        $reverse = DiaryConsumptionTransition::query()->where('sequence', 4)->sole();
        $this->assertNull($reverse->consumption_snapshot_id);

        $this->post(route('meal-plans.consumption.store', [$plan, 'item', $entry]), [
            'actual_amount' => '3',
        ])->assertSessionHasNoErrors();
        $reconsume = DiaryConsumptionTransition::query()->where('sequence', 5)->sole();

        $snapshots = ConsumptionNutritionSnapshot::query()->orderBy('created_at')->get();
        $this->assertCount(3, $snapshots);
        $this->assertSame(
            [$first->consumption_snapshot_id, $quantityCorrection->consumption_snapshot_id, $reconsume->consumption_snapshot_id],
            $snapshots->pluck('id')->all(),
        );
        $this->assertSame('2.000000000000000000', $snapshots[0]->actual_amount);
        $this->assertSame('1.250000000000000000', $snapshots[1]->actual_amount);
        $this->assertSame('3.000000000000000000', $snapshots[2]->actual_amount);
        $this->assertSame($quantityCorrection->id, $reverse->target_transition_id);
        $this->assertSame($reverse->id, $reconsume->predecessor_id);
    }

    public function test_transition_failure_rolls_back_snapshot_audits_state_and_consumption(): void
    {
        Date::setTestNow('2026-09-20 12:00:00 UTC');
        [$owner, $plan, $slot] = $this->datedPlan();
        $entry = MealPlanRecipeEntry::factory()->for($slot, 'slot')->create();

        DiaryConsumptionTransition::creating(
            fn () => throw new RuntimeException('Simulated transition failure.'),
        );

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]));
            $this->fail('The simulated transition failure should escape the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated transition failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('consumption_nutrition_snapshots', 0);
        $this->assertDatabaseCount('diary_consumption_states', 0);
        $this->assertDatabaseCount('diary_consumption_transitions', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    /** @return array{User, MealPlan, MealPlanSlot} */
    private function datedPlan(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create([
            'starts_on' => '2026-09-20',
            'ends_on' => '2026-09-20',
        ]);
        $day = MealPlanDay::factory()->for($plan)->create(['day_index' => null, 'date' => '2026-09-20']);
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();

        return [$owner, $plan, $slot];
    }

    /** @return array{Recipe, RecipeVersion} */
    private function recipeWithImportedNutrition(User $owner): array
    {
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'servings' => '2.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => RecipeVisibility::Private,
            'finalized_at' => now()->utc(),
        ]);
        $version = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 1,
            'visibility' => RecipeVisibility::Private,
            'snapshot' => [
                'title' => 'Pinned imported recipe',
                'servings' => '2.00',
                'visibility' => RecipeVisibility::Private->value,
                'ingredients' => [],
                'sections' => [],
                'steps' => [],
                'nutrition_estimate' => [
                    'type' => 'estimate',
                    'is_estimate' => true,
                    'calculation_policy_version' => 1,
                    'whole_recipe' => ['protein' => $this->nutrient('10', true)],
                    'per_serving' => ['protein' => $this->nutrient('5', true)],
                    'inputs' => [],
                ],
                'imported_nutrition' => [
                    'per_serving' => ['protein' => $this->nutrient('8', false)],
                    'provenance' => ['recipe_import_id' => 'import:PLAN06'],
                ],
            ],
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();

        return [$recipe, $version];
    }

    /** @return array{value: string, unit: string, basis: string, status: string, is_estimate: bool, source: string} */
    private function nutrient(string $value, bool $estimate): array
    {
        return [
            'value' => $value,
            'unit' => 'g',
            'basis' => 'per_serving',
            'status' => $estimate ? 'approximate' : 'known',
            'is_estimate' => $estimate,
            'source' => $estimate ? 'ingredient_estimate' : 'imported_source',
        ];
    }
}
