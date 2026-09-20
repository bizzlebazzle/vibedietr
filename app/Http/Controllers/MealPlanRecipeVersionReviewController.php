<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanRecipeVersionReviewManager;
use App\Models\MealPlanRecipeVersionReview;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanRecipeVersionReviewController extends Controller
{
    public function update(Request $request, int $mealPlan, string $review, MealPlanRecipeVersionReviewManager $manager): RedirectResponse
    {
        $manager->update($this->review($request, $mealPlan, $review), $this->owner($request));

        return back()->with('status', 'The planned entry now uses the newer recipe version.');
    }

    public function retain(Request $request, int $mealPlan, string $review, MealPlanRecipeVersionReviewManager $manager): RedirectResponse
    {
        $manager->retain($this->review($request, $mealPlan, $review), $this->owner($request));

        return back()->with('status', 'The planned entry will retain its pinned recipe snapshot for this version.');
    }

    private function review(Request $request, int $mealPlan, string $review): MealPlanRecipeVersionReview
    {
        $owner = $this->owner($request);
        $owner->mealPlans()->findOrFail($mealPlan);

        $versionReview = MealPlanRecipeVersionReview::query()
            ->with('entry.slot.day')
            ->findOrFail($review);
        if ((int) $versionReview->entry->slot->day->meal_plan_id !== $mealPlan) {
            abort(404);
        }

        return $versionReview;
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
