<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanDayWriter;
use App\Models\MealPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanDayController extends Controller
{
    public function store(Request $request, int $mealPlan, MealPlanDayWriter $writer): RedirectResponse
    {
        $mealPlan = $this->mealPlan($request, $mealPlan);
        $validated = $request->validate([
            'day_index' => ['nullable', 'integer', 'min:0'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $writer->createDay(
            $mealPlan,
            isset($validated['day_index']) ? (int) $validated['day_index'] : null,
            isset($validated['date']) ? CarbonImmutable::parse($validated['date']) : null,
        );

        return back()->with('status', 'Plan day added.');
    }

    private function mealPlan(Request $request, int $id): MealPlan
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user->mealPlans()->findOrFail($id);
    }
}
