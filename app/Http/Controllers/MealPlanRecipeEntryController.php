<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanRecipeEntryWriter;
use App\Models\MealPlan;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanRecipeEntryController extends Controller
{
    public function store(
        Request $request,
        int $mealPlan,
        MealPlanRecipeEntryWriter $writer,
    ): RedirectResponse {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $validated = $request->validate([
            'slot_id' => ['required', 'integer', 'min:1'],
            'recipe_id' => ['required', 'integer', 'min:1'],
            'planned_servings' => ['required', 'string', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
        ]);
        $slot = $this->slot($mealPlan, (int) $validated['slot_id']);

        $writer->add($slot, (int) $validated['recipe_id'], (string) $validated['planned_servings'], $owner);

        return back()->with('status', 'Recipe added to the meal plan.');
    }

    public function update(
        Request $request,
        int $mealPlan,
        int $entry,
        MealPlanRecipeEntryWriter $writer,
    ): RedirectResponse {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $entry = $this->entry($mealPlan, $entry);
        $validated = $request->validate([
            'target_slot_id' => ['required', 'integer', 'min:1'],
        ]);
        $target = $this->slot($mealPlan, (int) $validated['target_slot_id']);

        $writer->move($entry, $target, $owner);

        return back()->with('status', 'Recipe entry moved.');
    }

    public function destroy(
        Request $request,
        int $mealPlan,
        int $entry,
        MealPlanRecipeEntryWriter $writer,
    ): RedirectResponse {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $writer->remove($this->entry($mealPlan, $entry), $owner);

        return back()->with('status', 'Recipe entry removed.');
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function slot(MealPlan $mealPlan, int $slotId): MealPlanSlot
    {
        return MealPlanSlot::query()
            ->whereKey($slotId)
            ->whereHas('day', fn (Builder $query) => $query->where('meal_plan_id', $mealPlan->getKey()))
            ->firstOrFail();
    }

    private function entry(MealPlan $mealPlan, int $entryId): MealPlanRecipeEntry
    {
        return MealPlanRecipeEntry::query()
            ->whereKey($entryId)
            ->whereHas(
                'slot.day',
                fn (Builder $query) => $query->where('meal_plan_id', $mealPlan->getKey()),
            )
            ->firstOrFail();
    }
}
