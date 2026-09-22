<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanSharingManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanVisibilityController extends Controller
{
    public function store(Request $request, int $mealPlan, MealPlanSharingManager $sharing): RedirectResponse
    {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $sharing->publish($mealPlan, $owner);

        return back()->with('status', 'Meal plan shared publicly as read-only.');
    }

    public function destroy(Request $request, int $mealPlan, MealPlanSharingManager $sharing): RedirectResponse
    {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $sharing->unpublish($mealPlan, $owner);

        return back()->with('status', 'Meal plan is private again.');
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
