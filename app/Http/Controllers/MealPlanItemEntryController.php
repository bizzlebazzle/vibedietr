<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanItemEntryKind;
use App\Domain\MealPlans\MealPlanItemEntryWriter;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\NutrientBasis;
use App\Http\Requests\StoreMealPlanItemEntryRequest;
use App\Models\MealPlan;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanItemEntryController extends Controller
{
    public function store(
        StoreMealPlanItemEntryRequest $request,
        int $mealPlan,
        MealPlanItemEntryWriter $writer,
    ): RedirectResponse {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $validated = $request->validated();
        $slot = $this->slot($mealPlan, (int) $validated['slot_id']);
        $kind = MealPlanItemEntryKind::from($validated['kind']);

        if ($kind === MealPlanItemEntryKind::Catalogue) {
            $writer->addCatalogue(
                $slot,
                (int) $validated['catalogue_item_id'],
                (string) $validated['planned_amount'],
                StandardUnit::from($validated['planned_unit']),
                $owner,
            );
        } else {
            $writer->addOneOff(
                $slot,
                $validated['one_off_wording'],
                (string) $validated['planned_amount'],
                StandardUnit::from($validated['planned_unit']),
                isset($validated['one_off_nutrition_basis'])
                    ? NutrientBasis::from($validated['one_off_nutrition_basis'])
                    : null,
                $validated['one_off_nutrition'] ?? [],
                $owner,
            );
        }

        return back()->with('status', 'Item added to the meal plan.');
    }

    public function update(
        Request $request,
        int $mealPlan,
        int $entry,
        MealPlanItemEntryWriter $writer,
    ): RedirectResponse {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $validated = $request->validate(['target_slot_id' => ['required', 'integer', 'min:1']]);
        $writer->move(
            $this->entry($mealPlan, $entry),
            $this->slot($mealPlan, (int) $validated['target_slot_id']),
            $owner,
        );

        return back()->with('status', 'Plan item moved.');
    }

    public function destroy(
        Request $request,
        int $mealPlan,
        int $entry,
        MealPlanItemEntryWriter $writer,
    ): RedirectResponse {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $writer->remove($this->entry($mealPlan, $entry), $owner);

        return back()->with('status', 'Plan item removed.');
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

    private function entry(MealPlan $mealPlan, int $entryId): MealPlanItemEntry
    {
        return MealPlanItemEntry::query()
            ->whereKey($entryId)
            ->whereHas('slot.day', fn (Builder $query) => $query->where('meal_plan_id', $mealPlan->getKey()))
            ->firstOrFail();
    }
}
