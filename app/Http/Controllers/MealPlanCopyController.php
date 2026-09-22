<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanCopier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanCopyController extends Controller
{
    public function __invoke(Request $request, int $mealPlan, MealPlanCopier $copier): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $copy = $copier->copy($mealPlan, $actor);

        return redirect()->route('meal-plans.show', $copy)->with('status', 'Meal plan copied.');
    }
}
