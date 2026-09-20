<?php

namespace Tests\Feature\MealPlans;

use App\Audit\Enums\AuditAction;
use App\Domain\MealPlans\MealPlanRecipeVersionReviewNotifier;
use App\Domain\MealPlans\MealPlanRecipeVersionReviewStatus;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Jobs\CreateMealPlanRecipeVersionReviews;
use App\Models\AuditEvent;
use App\Models\DiaryConsumptionState;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanRecipeVersionReview;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealPlanRecipeVersionReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_fan_out_is_correlated_deduplicated_retry_safe_and_excludes_consumed_entries(): void
    {
        [$recipe, $one, $two] = $this->recipeVersions();
        $owner = User::factory()->create();
        [, $slot] = $this->planSlot($owner);
        $affected = $this->entry($slot, $recipe, $one);
        $consumed = $this->entry($slot, $recipe, $one);
        $current = $this->entry($slot, $recipe, $two);
        $state = new DiaryConsumptionState;
        $state->forceFill(['meal_plan_recipe_entry_id' => $consumed->id, 'next_sequence' => 1]);
        $state->save();

        $job = new CreateMealPlanRecipeVersionReviews($two->id, '01PLAN07CORRELATION00000000');
        $job->handle(app(MealPlanRecipeVersionReviewNotifier::class));
        $job->handle(app(MealPlanRecipeVersionReviewNotifier::class));

        $review = MealPlanRecipeVersionReview::query()->sole();
        $this->assertSame($affected->id, $review->meal_plan_recipe_entry_id);
        $this->assertSame($two->id, $review->recipe_version_id);
        $this->assertSame('01PLAN07CORRELATION00000000', $review->correlation_id);
        $this->assertSame(MealPlanRecipeVersionReviewStatus::Pending, $review->status);
        $this->assertSame($one->id, $affected->fresh()->recipe_version_id);
        $this->assertDatabaseMissing('meal_plan_recipe_version_reviews', ['meal_plan_recipe_entry_id' => $consumed->id]);
        $this->assertDatabaseMissing('meal_plan_recipe_version_reviews', ['meal_plan_recipe_entry_id' => $current->id]);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60], $job->backoff());
        $this->assertSame(60, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame($job->idempotencyFingerprint(), $job->uniqueId());
        $this->assertCount(1, $job->middleware());
    }

    public function test_only_owner_can_explicitly_update_and_resolution_cleans_the_notification_with_audit(): void
    {
        [$recipe, $one, $two] = $this->recipeVersions();
        $owner = User::factory()->create();
        [$plan, $slot] = $this->planSlot($owner);
        $entry = $this->entry($slot, $recipe, $one);
        app(MealPlanRecipeVersionReviewNotifier::class)->createForVersion($two->id, '01PLAN07UPDATE000000000000');
        $review = MealPlanRecipeVersionReview::query()->sole();

        $this->actingAs(User::factory()->create())
            ->post(route('meal-plans.recipe-version-reviews.update', [$plan, $review]))
            ->assertNotFound();
        $this->assertSame($one->id, $entry->fresh()->recipe_version_id);

        $this->actingAs($owner)
            ->post(route('meal-plans.recipe-version-reviews.update', [$plan, $review]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $review->refresh();
        $this->assertSame($two->id, $entry->recipe_version_id);
        $this->assertSame(2, $entry->recipe_version_number);
        $this->assertSame('Version two', $entry->recipe_snapshot['title']);
        $this->assertSame(MealPlanRecipeVersionReviewStatus::Updated, $review->status);
        $this->assertNotNull($review->resolved_at);
        $audit = AuditEvent::query()->where('action', AuditAction::PlanRecipeVersionReviewed)->sole();
        $this->assertEquals(['current_version_id' => $one->id, 'decision' => 'updated', 'offered_version_id' => $two->id], $audit->payload);
        $this->assertSame($review->correlation_id, $audit->correlation_id);
        $this->assertSame($review->id, $audit->evidence_reference);
        $this->actingAs($owner)->get(route('meal-plans.show', $plan))->assertOk()->assertDontSee('A newer recipe version');
    }

    public function test_retain_is_permanent_for_the_offered_version_but_a_later_version_is_reviewable(): void
    {
        [$recipe, $one, $two] = $this->recipeVersions();
        $owner = User::factory()->create();
        [$plan, $slot] = $this->planSlot($owner);
        $entry = $this->entry($slot, $recipe, $one);
        $notifier = app(MealPlanRecipeVersionReviewNotifier::class);
        $notifier->createForVersion($two->id, '01PLAN07RETAIN00000000000');
        $review = MealPlanRecipeVersionReview::query()->sole();

        $this->actingAs($owner)
            ->post(route('meal-plans.recipe-version-reviews.retain', [$plan, $review]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $notifier->createForVersion($two->id, '01PLAN07DUPLICATE000000000');

        $this->assertSame(MealPlanRecipeVersionReviewStatus::Retained, $review->fresh()->status);
        $this->assertSame($one->id, $entry->fresh()->recipe_version_id);
        $this->assertDatabaseCount('meal_plan_recipe_version_reviews', 1);
        $this->assertSame('retained', AuditEvent::query()->where('action', AuditAction::PlanRecipeVersionReviewed)->sole()->payload['decision']);

        $three = $this->version($recipe, 3, 'Version three');
        $recipe->forceFill(['current_recipe_version_id' => $three->id])->save();
        $notifier->createForVersion($three->id, '01PLAN07VERSIONTHREE000000');

        $this->assertDatabaseCount('meal_plan_recipe_version_reviews', 2);
        $this->assertDatabaseHas('meal_plan_recipe_version_reviews', [
            'meal_plan_recipe_entry_id' => $entry->id,
            'recipe_version_id' => $three->id,
            'status' => MealPlanRecipeVersionReviewStatus::Pending->value,
        ]);
    }

    private function recipeVersions(): array
    {
        $recipe = Recipe::factory()->for(User::factory(), 'owner')->create([
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => RecipeVisibility::Public,
            'finalized_at' => now()->utc(),
        ]);
        $one = $this->version($recipe, 1, 'Version one');
        $two = $this->version($recipe, 2, 'Version two');
        $recipe->forceFill(['current_recipe_version_id' => $two->id])->save();

        return [$recipe->fresh(), $one, $two];
    }

    private function version(Recipe $recipe, int $number, string $title): RecipeVersion
    {
        return RecipeVersion::factory()->for($recipe)->create([
            'version_number' => $number,
            'snapshot' => [
                'title' => $title,
                'servings' => '2.00',
                'visibility' => 'public',
                'ingredients' => [],
                'sections' => [],
                'steps' => [],
                'nutrition_estimate' => ['per_serving' => [], 'inputs' => []],
            ],
        ]);
    }

    private function planSlot(User $owner): array
    {
        $plan = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $day = new MealPlanDay;
        $day->forceFill(['day_index' => 0, 'date' => null]);
        $day->mealPlan()->associate($plan);
        $day->save();
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();

        return [$plan, $slot];
    }

    private function entry(MealPlanSlot $slot, Recipe $recipe, RecipeVersion $version): MealPlanRecipeEntry
    {
        return MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_id' => $recipe->id,
            'recipe_version_id' => $version->id,
            'recipe_version_number' => $version->version_number,
            'recipe_snapshot' => $version->snapshot,
        ]);
    }
}
