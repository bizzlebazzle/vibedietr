<?php

namespace App\Domain\MealPlans;

use App\Domain\Recipes\RecipeVisibility;
use App\Models\MealPlan;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class MealPlanPublicSafety
{
    public function hasPrivateRecipeSnapshots(MealPlan $mealPlan): bool
    {
        return $this->recipeEntries($mealPlan)->get()->contains(
            fn (MealPlanRecipeEntry $entry): bool => ! $this->isPublicRecipeSnapshot($entry->recipe_snapshot),
        );
    }

    public function isPublicSafe(MealPlan $mealPlan): bool
    {
        if ($this->hasPrivateRecipeSnapshots($mealPlan)) {
            return false;
        }

        return $this->itemEntries($mealPlan)->get()->every(
            fn (MealPlanItemEntry $entry): bool => $entry->kind === MealPlanItemEntryKind::Catalogue
                && is_array($entry->catalogue_snapshot)
                && $entry->catalogue_snapshot !== []
                && $entry->one_off_wording === null
                && $entry->one_off_nutrition === null,
        );
    }

    public function assertPublicSafe(MealPlan $mealPlan): void
    {
        if (! $this->isPublicSafe($mealPlan)) {
            throw ValidationException::withMessages([
                'visibility' => 'This entire plan cannot be shared publicly because one or more pinned entries are not proven public-safe.',
            ]);
        }
    }

    /** @return Builder<MealPlanRecipeEntry> */
    private function recipeEntries(MealPlan $mealPlan): Builder
    {
        return MealPlanRecipeEntry::query()->whereHas(
            'slot.day',
            fn (Builder $query) => $query->where('meal_plan_id', $mealPlan->getKey()),
        );
    }

    /** @return Builder<MealPlanItemEntry> */
    private function itemEntries(MealPlan $mealPlan): Builder
    {
        return MealPlanItemEntry::query()->whereHas(
            'slot.day',
            fn (Builder $query) => $query->where('meal_plan_id', $mealPlan->getKey()),
        );
    }

    /** @param array<string, mixed> $snapshot */
    private function isPublicRecipeSnapshot(array $snapshot): bool
    {
        return ($snapshot['visibility'] ?? null) === RecipeVisibility::Public->value;
    }
}
