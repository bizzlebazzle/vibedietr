<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanDayWriter;
use App\Models\MealPlanDay;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanSlotController extends Controller
{
    public function store(Request $request, int $mealPlan, int $day, MealPlanDayWriter $writer): RedirectResponse
    {
        $day = $this->day($request, $mealPlan, $day);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $writer->addSlot($day, $validated['name']);

        return back()->with('status', 'Slot added.');
    }

    public function update(Request $request, int $mealPlan, int $day, int $slot, MealPlanDayWriter $writer): RedirectResponse
    {
        $day = $this->day($request, $mealPlan, $day);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $writer->renameSlot($day, $slot, $validated['name']);

        return back()->with('status', 'Slot renamed.');
    }

    public function reorder(Request $request, int $mealPlan, int $day, MealPlanDayWriter $writer): RedirectResponse
    {
        $day = $this->day($request, $mealPlan, $day);
        $validated = $request->validate([
            'slot_ids' => ['required', 'array'],
            'slot_ids.*' => ['required', 'integer'],
        ]);
        $writer->reorderSlots($day, $validated['slot_ids']);

        return back()->with('status', 'Slots reordered.');
    }

    private function day(Request $request, int $mealPlanId, int $dayId): MealPlanDay
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $mealPlan = $user->mealPlans()->findOrFail($mealPlanId);

        return $mealPlan->days()->findOrFail($dayId);
    }
}
