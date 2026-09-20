<?php

namespace App\Domain\MealPlans;

use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanRecipeVersionReview;
use App\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MealPlanRecipeVersionReviewNotifier
{
    public function createForVersion(string $recipeVersionId, string $correlationId): int
    {
        $version = RecipeVersion::query()->with('recipe')->find($recipeVersionId);
        if (! $version instanceof RecipeVersion || $version->version_number < 2) {
            return 0;
        }

        $created = 0;
        MealPlanRecipeEntry::query()
            ->where('recipe_id', $version->recipe_id)
            ->where('recipe_version_number', '<', $version->version_number)
            ->whereDoesntHave('consumptionState')
            ->when(
                ! $version->recipe->isPubliclyViewable(),
                fn ($query) => $query->whereHas(
                    'slot.day.mealPlan',
                    fn ($plan) => $plan->where('user_id', $version->recipe->user_id),
                ),
            )
            ->chunkById(200, function ($entries) use ($version, $correlationId, &$created): void {
                foreach ($entries as $entry) {
                    $created += DB::transaction(function () use ($entry, $version, $correlationId): int {
                        $locked = MealPlanRecipeEntry::query()->lockForUpdate()->find($entry->getKey());
                        if (! $locked instanceof MealPlanRecipeEntry
                            || $locked->recipe_version_number >= $version->version_number
                            || $locked->consumptionState()->exists()) {
                            return 0;
                        }

                        return DB::table('meal_plan_recipe_version_reviews')->insertOrIgnore([
                            'id' => (string) Str::ulid(),
                            'meal_plan_recipe_entry_id' => $locked->getKey(),
                            'recipe_version_id' => $version->getKey(),
                            'correlation_id' => $correlationId,
                            'status' => MealPlanRecipeVersionReviewStatus::Pending->value,
                            'created_at' => now()->utc(),
                            'updated_at' => now()->utc(),
                        ]);
                    }, 3);
                }
            });

        return $created;
    }

    public function resolvePendingForConsumedEntry(int $entryId): void
    {
        MealPlanRecipeVersionReview::query()
            ->where('meal_plan_recipe_entry_id', $entryId)
            ->where('status', MealPlanRecipeVersionReviewStatus::Pending)
            ->update([
                'status' => MealPlanRecipeVersionReviewStatus::Inapplicable,
                'resolved_at' => now()->utc(),
            ]);
    }
}
