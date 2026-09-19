<?php

namespace App\Http\Controllers;

use App\Domain\Diary\DiaryConsumptionManager;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanConsumptionController extends Controller
{
    public function store(Request $request, int $mealPlan, string $entryType, int $entry, DiaryConsumptionManager $manager): RedirectResponse
    {
        [$user, $target] = $this->target($request, $mealPlan, $entryType, $entry);
        $data = $request->validate($this->rules($entryType));
        $args = [$target, $data['actual_amount'] ?? null, $data['consumed_local_at'] ?? null, $data['timezone'] ?? null, isset($data['utc_offset_minutes']) ? (int) $data['utc_offset_minutes'] : null, $user];
        $entryType === 'recipe' ? $manager->consumeRecipe(...$args) : $manager->consumeItem(...$args);

        return back()->with('status', 'Consumption recorded.');
    }

    public function update(Request $request, int $mealPlan, string $entryType, int $entry, DiaryConsumptionManager $manager): RedirectResponse
    {
        [$user, $target] = $this->target($request, $mealPlan, $entryType, $entry);
        $data = $request->validate($this->rules($entryType));
        $manager->correct($target, $entryType === 'recipe' ? 'meal_plan_recipe_entry_id' : 'meal_plan_item_entry_id', $data['actual_amount'] ?? null, $data['consumed_local_at'] ?? null, $data['timezone'] ?? null, isset($data['utc_offset_minutes']) ? (int) $data['utc_offset_minutes'] : null, null, $user);

        return back()->with('status', 'Consumption corrected.');
    }

    public function destroy(Request $request, int $mealPlan, string $entryType, int $entry, DiaryConsumptionManager $manager): RedirectResponse
    {
        [$user, $target] = $this->target($request, $mealPlan, $entryType, $entry);
        $manager->reverse($target, $entryType === 'recipe' ? 'meal_plan_recipe_entry_id' : 'meal_plan_item_entry_id', $user);

        return back()->with('status', 'Consumption reversed.');
    }

    /** @return array{User, MealPlanRecipeEntry|MealPlanItemEntry} */
    private function target(Request $request, int $planId, string $type, int $entryId): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $plan = $user->mealPlans()->findOrFail($planId);
        $class = $type === 'recipe' ? MealPlanRecipeEntry::class : ($type === 'item' ? MealPlanItemEntry::class : abort(404));
        $entry = $class::query()->whereKey($entryId)->whereHas('slot.day', fn (Builder $q) => $q->where('meal_plan_id', $plan->getKey()))->firstOrFail();

        return [$user, $entry];
    }

    /** @return array<string, mixed> */
    private function rules(string $entryType): array
    {
        $amountRules = $entryType === 'recipe'
            ? ['nullable', 'string', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99']
            : ['nullable', 'string', 'numeric', 'decimal:0,18', 'gt:0', 'regex:/^\d{1,20}(\.\d{1,18})?$/'];

        return ['actual_amount' => $amountRules, 'consumed_local_at' => ['nullable', 'string', 'max:19'], 'timezone' => ['nullable', 'string', 'timezone:all', 'max:64'], 'utc_offset_minutes' => ['nullable', 'integer', 'between:-840,840']];
    }
}
