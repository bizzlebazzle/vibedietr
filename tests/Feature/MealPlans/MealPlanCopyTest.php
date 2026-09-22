<?php

namespace Tests\Feature\MealPlans;

use App\Audit\Enums\AuditAction;
use App\Domain\MealPlans\MealPlanItemEntryKind;
use App\Domain\MealPlans\MealPlanVisibility;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\AuditEvent;
use App\Models\DiaryConsumptionState;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanShare;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MealPlanCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_plan_copy_materializes_planning_content_privately_without_source_relationships(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $source = MealPlan::factory()->for($owner, 'owner')->dated()->public()->create(['name' => 'Public week']);
        $slot = $this->slot($source, date: $source->starts_on->toDateString());
        $recipeEntry = MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'planned_servings' => '2.50',
            'recipe_snapshot' => $this->recipeSnapshot('Pinned public recipe', RecipeVisibility::Public),
        ]);
        $itemEntry = MealPlanItemEntry::factory()->for($slot, 'slot')->create([
            'kind' => MealPlanItemEntryKind::Catalogue,
            'planned_amount' => '375.5',
            'planned_unit' => StandardUnit::Gram,
            'catalogue_item_id' => 9123,
            'catalogue_item_version_id' => (string) Str::ulid(),
            'catalogue_item_version_number' => 4,
            'catalogue_snapshot' => ['name' => 'Pinned oats', 'brand' => 'Example'],
            'catalogue_nutrition_snapshot' => [['nutrient' => 'protein', 'amount' => '12']],
            'one_off_wording' => null,
            'one_off_nutrition' => null,
        ]);
        $state = new DiaryConsumptionState;
        $state->forceFill(['meal_plan_recipe_entry_id' => $recipeEntry->id, 'next_sequence' => 2]);
        $state->save();
        MealPlanShare::factory()->for($source, 'mealPlan')->create();
        MealPlanBookmark::factory()->for($viewer, 'owner')->for($source, 'mealPlan')->create();

        $this->actingAs($viewer)->post(route('meal-plans.copy', $source))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $copy = $viewer->mealPlans()->sole();
        $copy->load(['days.slots.recipeEntries.consumptionState', 'days.slots.itemEntries', 'shares', 'bookmarks']);
        $copiedSlot = $copy->days->sole()->slots->sole();
        $copiedRecipe = $copiedSlot->recipeEntries->sole();
        $copiedItem = $copiedSlot->itemEntries->sole();

        $this->assertSame($viewer->id, $copy->user_id);
        $this->assertSame('Public week', $copy->name);
        $this->assertSame($source->type, $copy->type);
        $this->assertSame($source->starts_on->toDateString(), $copy->starts_on->toDateString());
        $this->assertSame($source->ends_on->toDateString(), $copy->ends_on->toDateString());
        $this->assertSame(MealPlanVisibility::Private, $copy->visibility);
        $this->assertNull($copy->published_at);
        $this->assertNull($copy->retained_unlisted_at);
        $this->assertSame($slot->name, $copiedSlot->name);
        $this->assertSame($slot->position, $copiedSlot->position);
        $this->assertNotSame($slot->id, $copiedSlot->id);
        $this->assertNotSame($recipeEntry->id, $copiedRecipe->id);
        $this->assertEquals($recipeEntry->recipe_snapshot, $copiedRecipe->recipe_snapshot);
        $this->assertEquals($recipeEntry->nutrition_snapshot, $copiedRecipe->nutrition_snapshot);
        $this->assertSame($recipeEntry->planned_servings, $copiedRecipe->planned_servings);
        $this->assertNull($copiedRecipe->consumptionState);
        $this->assertNotSame($itemEntry->id, $copiedItem->id);
        $this->assertEquals($itemEntry->catalogue_snapshot, $copiedItem->catalogue_snapshot);
        $this->assertEquals($itemEntry->catalogue_nutrition_snapshot, $copiedItem->catalogue_nutrition_snapshot);
        $this->assertSame($itemEntry->planned_amount, $copiedItem->planned_amount);
        $this->assertCount(0, $copy->shares);
        $this->assertCount(0, $copy->bookmarks);
        $this->assertSame(1, $source->bookmarks()->count());
        $this->assertDatabaseCount('meal_plan_bookmarks', 1);
        $this->assertDatabaseCount('diary_consumption_states', 1);

        $audit = AuditEvent::query()->where('action', AuditAction::PlanCopied)->sole();
        $this->assertSame((string) $copy->id, $audit->subject_identifier);
        $this->assertEquals(['outcome' => 'completed', 'source_visibility' => 'public'], $audit->payload);

        $source->update(['name' => 'Changed source']);
        $recipeEntry->forceFill(['planned_servings' => '7.00']);
        $recipeEntry->save();
        $this->assertSame('Public week', $copy->fresh()->name);
        $this->assertSame('2.50', $copiedRecipe->fresh()->planned_servings);
    }

    public function test_selected_share_copy_preserves_private_snapshot_and_one_off_content_without_live_recipe_access(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $intruder = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->finalizedPrivate()->create(['title' => 'Live private recipe']);
        $source = MealPlan::factory()->for($owner, 'owner')->create(['name' => 'Selected plan']);
        $slot = $this->slot($source);
        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_id' => $recipe->id,
            'recipe_version_id' => $recipe->current_recipe_version_id,
            'recipe_snapshot' => $this->recipeSnapshot('Legitimate pinned private snapshot', RecipeVisibility::Private),
        ]);
        MealPlanItemEntry::factory()->for($slot, 'slot')->create([
            'one_off_wording' => 'Owner-entered snack',
            'one_off_nutrition' => ['values' => ['energy_kcal' => '120']],
        ]);
        $share = MealPlanShare::factory()
            ->for($source, 'mealPlan')
            ->for($viewer, 'recipient')
            ->create(['private_recipe_snapshots_acknowledged_at' => now()->utc()]);

        $this->actingAs($viewer)->post(route('meal-plans.copy', $source))->assertRedirect();
        $copy = $viewer->mealPlans()->sole();

        $this->get(route('meal-plans.show', $copy))
            ->assertOk()
            ->assertSee('Legitimate pinned private snapshot')
            ->assertSee('Owner-entered snack')
            ->assertDontSee('Live private recipe');
        $this->get(route('recipes.show', $recipe))->assertNotFound();
        $this->actingAs($intruder)->get(route('meal-plans.show', $copy))->assertNotFound();

        $share->delete();
        $source->delete();

        $this->actingAs($viewer)->get(route('meal-plans.show', $copy))
            ->assertOk()
            ->assertSee('Legitimate pinned private snapshot')
            ->assertSee('Owner-entered snack');
        $this->assertDatabaseHas('meal_plans', [
            'id' => $copy->id,
            'user_id' => $viewer->id,
            'visibility' => MealPlanVisibility::Private->value,
        ]);
        $this->assertDatabaseCount('meal_plan_shares', 0);
        $this->assertDatabaseCount('meal_plan_bookmarks', 0);
    }

    public function test_retained_anonymized_plan_copy_survives_final_bookmark_removal_and_source_deletion(): void
    {
        $viewer = User::factory()->create();
        $source = MealPlan::factory()->retainedUnlisted()->create(['name' => 'Retained plan']);
        $slot = $this->slot($source);
        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_snapshot' => $this->recipeSnapshot('Retained public snapshot', RecipeVisibility::Public),
        ]);
        $bookmark = MealPlanBookmark::factory()->for($viewer, 'owner')->for($source, 'mealPlan')->create();

        $this->actingAs($viewer)->post(route('meal-plans.copy', $source))->assertRedirect();
        $copy = $viewer->mealPlans()->sole();

        $this->assertSame(1, $source->bookmarks()->count());
        $this->assertDatabaseCount('meal_plan_bookmarks', 1);

        $this->delete(route('meal-plans.bookmarks.destroy', $bookmark))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('meal_plans', ['id' => $source->id]);
        $this->assertDatabaseHas('meal_plans', ['id' => $copy->id, 'user_id' => $viewer->id]);
        $this->get(route('meal-plans.show', $copy))
            ->assertOk()
            ->assertSee('Retained public snapshot');
    }

    public function test_inaccessible_private_source_is_denied_without_creating_a_copy(): void
    {
        $source = MealPlan::factory()->create();
        $viewer = User::factory()->create();
        $administrator = User::factory()->create(['is_administrator' => true]);

        $this->actingAs($viewer)->post(route('meal-plans.copy', $source))->assertNotFound();
        $this->actingAs($administrator)->post(route('meal-plans.copy', $source))->assertNotFound();
        $this->app['auth']->guard()->logout();
        $this->post(route('meal-plans.copy', $source))->assertRedirect(route('login'));

        $this->assertDatabaseCount('meal_plans', 1);
        $this->assertDatabaseMissing('audit_events', ['action' => AuditAction::PlanCopied->value]);
    }

    private function slot(MealPlan $plan, ?string $date = null): MealPlanSlot
    {
        $day = new MealPlanDay;
        $day->forceFill([
            'day_index' => $date === null ? 0 : null,
            'date' => $date,
        ]);
        $day->mealPlan()->associate($plan);
        $day->save();

        $slot = new MealPlanSlot;
        $slot->forceFill([
            'standard_key' => null,
            'name' => 'Dinner',
            'position' => 0,
        ]);
        $slot->day()->associate($day);
        $slot->save();

        return $slot;
    }

    /** @return array<string, mixed> */
    private function recipeSnapshot(string $title, RecipeVisibility $visibility): array
    {
        return [
            'title' => $title,
            'servings' => '2.00',
            'visibility' => $visibility->value,
            'ingredients' => [],
            'sections' => [],
            'steps' => [],
        ];
    }
}
