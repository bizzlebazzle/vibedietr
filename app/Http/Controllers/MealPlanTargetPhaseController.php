<?php

namespace App\Http\Controllers;

use App\Domain\NutritionTargets\MealPlanTargetPhaseManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanTargetPhaseController extends Controller
{
    public function store(Request $request, int $mealPlan, MealPlanTargetPhaseManager $manager): RedirectResponse
    {
        $owner = $this->owner($request);
        $plan = $owner->mealPlans()->findOrFail($mealPlan);
        $this->authorize('update', $plan);
        $input = $this->validated($request);
        $profile = $owner->nutritionTargetProfiles()->findOrFail($input['profile_id']);
        $manager->create($owner, $plan, $profile, $input['starts_on'], $input['ends_on'] ?? null);

        return back()->with('status', 'Target phase assigned.');
    }

    public function update(Request $request, int $mealPlan, int $phase, MealPlanTargetPhaseManager $manager): RedirectResponse
    {
        $owner = $this->owner($request);
        $plan = $owner->mealPlans()->findOrFail($mealPlan);
        $this->authorize('update', $plan);
        $existing = $plan->targetPhases()->findOrFail($phase);
        $input = $this->validated($request);
        $profile = $owner->nutritionTargetProfiles()->findOrFail($input['profile_id']);
        $manager->update($owner, $plan, $existing, $profile, $input['starts_on'], $input['ends_on'] ?? null);

        return back()->with('status', 'Future target phase dates updated.');
    }

    public function destroy(Request $request, int $mealPlan, int $phase, MealPlanTargetPhaseManager $manager): RedirectResponse
    {
        $owner = $this->owner($request);
        $plan = $owner->mealPlans()->findOrFail($mealPlan);
        $this->authorize('update', $plan);
        $existing = $plan->targetPhases()->findOrFail($phase);
        $manager->remove($owner, $plan, $existing);

        return back()->with('status', 'Future target phase dates removed.');
    }

    /** @return array{profile_id: int|string, starts_on: string, ends_on?: string|null} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'profile_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'meal_plan_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ]);
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
