<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanBookmarkManager;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanBookmarkController extends Controller
{
    public function store(Request $request, int $mealPlan, MealPlanBookmarkManager $bookmarks): RedirectResponse
    {
        $user = $this->user($request);
        $mealPlan = MealPlan::query()->findOrFail($mealPlan);
        $bookmarks->add($mealPlan, $user);

        return back()->with('status', 'Public plan bookmarked privately.');
    }

    public function destroy(
        Request $request,
        MealPlanBookmark $bookmark,
        MealPlanBookmarkManager $bookmarks,
    ): RedirectResponse {
        $bookmarks->remove($bookmark, $this->user($request));

        return back()->with('status', 'Plan bookmark removed.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
