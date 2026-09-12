<?php

namespace App\Domain\MealPlans;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\Nutrition\RecipeNutritionSourceSelector;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MealPlanRecipeEntryWriter
{
    public function __construct(
        private readonly RecipeNutritionSourceSelector $nutrition,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function add(MealPlanSlot $slot, int $recipeId, string $plannedServings, User $actor): MealPlanRecipeEntry
    {
        return DB::transaction(function () use ($slot, $recipeId, $plannedServings, $actor): MealPlanRecipeEntry {
            $slot = MealPlanSlot::query()->lockForUpdate()->findOrFail($slot->getKey());
            $slot->load('day.mealPlan');
            Gate::forUser($actor)->authorize('update', $slot->day->mealPlan);

            $recipe = Recipe::query()
                ->visibleTo($actor)
                ->lockForUpdate()
                ->findOrFail($recipeId);

            if (! $recipe->canBeUsedInPlansFor($actor)) {
                throw ValidationException::withMessages([
                    'recipe_id' => 'Only a finalized recipe can be added to a meal plan.',
                ]);
            }

            $version = RecipeVersion::query()
                ->where('recipe_id', $recipe->getKey())
                ->lockForUpdate()
                ->find($recipe->current_recipe_version_id);

            if (! $version instanceof RecipeVersion) {
                throw ValidationException::withMessages([
                    'recipe_id' => 'The current finalized recipe version is unavailable.',
                ]);
            }

            $effectiveNutrition = $this->nutrition->effective($version);
            $entry = new MealPlanRecipeEntry;
            $entry->forceFill([
                'recipe_id' => $recipe->getKey(),
                'recipe_version_id' => $version->getKey(),
                'recipe_version_number' => $version->version_number,
                'planned_servings' => $plannedServings,
                'recipe_snapshot' => $version->snapshot,
                'nutrition_snapshot' => [
                    'source' => $effectiveNutrition['source']->value,
                    'values' => $effectiveNutrition['values'],
                    'provenance' => $effectiveNutrition['provenance'],
                    'ingredient_estimate' => $this->nutrition->estimate($version),
                ],
            ]);
            $entry->slot()->associate($slot);
            $entry->save();

            $this->audit->record(
                AuditAction::PlanSnapshotRecorded,
                AuditActor::system(),
                AuditSubject::resource(AuditSubjectType::PlanSnapshot, 'plan-entry:'.$entry->getKey()),
                ['outcome' => 'recorded', 'snapshot_kind' => 'planned'],
                'plan-entry:create:'.$entry->getKey(),
            );

            return $entry;
        }, 3);
    }

    public function move(MealPlanRecipeEntry $entry, MealPlanSlot $targetSlot, User $actor): MealPlanRecipeEntry
    {
        return DB::transaction(function () use ($entry, $targetSlot, $actor): MealPlanRecipeEntry {
            $entry = MealPlanRecipeEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $entry->load('slot.day.mealPlan');
            Gate::forUser($actor)->authorize('update', $entry->slot->day->mealPlan);

            $targetSlot = MealPlanSlot::query()->lockForUpdate()->findOrFail($targetSlot->getKey());
            $targetSlot->load('day.mealPlan');

            if (! $targetSlot->day->mealPlan->is($entry->slot->day->mealPlan)) {
                throw ValidationException::withMessages([
                    'target_slot_id' => 'Recipe entries can only move within their meal plan.',
                ]);
            }

            $entry->slot()->associate($targetSlot);
            $entry->save();

            return $entry;
        }, 3);
    }

    public function remove(MealPlanRecipeEntry $entry, User $actor): void
    {
        DB::transaction(function () use ($entry, $actor): void {
            $entry = MealPlanRecipeEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $entry->load('slot.day.mealPlan');
            Gate::forUser($actor)->authorize('update', $entry->slot->day->mealPlan);
            $entry->delete();
        }, 3);
    }
}
