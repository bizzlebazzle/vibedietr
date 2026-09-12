<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanType;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealPlanController extends Controller
{
    public function index(Request $request): View
    {
        $owner = $this->owner($request);
        $this->authorize('viewAny', MealPlan::class);
        $mealPlans = $owner->mealPlans()->latest('id')->get();

        return view('meal-plans.index', compact('mealPlans'));
    }

    public function create(Request $request): View
    {
        $this->owner($request);
        $this->authorize('create', MealPlan::class);

        return view('meal-plans.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $this->authorize('create', MealPlan::class);
        $mealPlan = $owner->mealPlans()->create($this->validated($request));

        return redirect()->route('meal-plans.show', $mealPlan)->with('status', 'Meal plan created.');
    }

    public function show(Request $request, int $mealPlan): View
    {
        $mealPlan = $this->mealPlan($request, $mealPlan);
        $this->authorize('view', $mealPlan);
        $mealPlan->load('days.slots.recipeEntries');

        return view('meal-plans.show', compact('mealPlan'));
    }

    public function edit(Request $request, int $mealPlan): View
    {
        $mealPlan = $this->mealPlan($request, $mealPlan);
        $this->authorize('update', $mealPlan);

        return view('meal-plans.edit', compact('mealPlan'));
    }

    public function update(Request $request, int $mealPlan): RedirectResponse
    {
        $mealPlan = $this->mealPlan($request, $mealPlan);
        $this->authorize('update', $mealPlan);
        $mealPlan->update($this->validated($request));

        return redirect()->route('meal-plans.show', $mealPlan)->with('status', 'Meal plan updated.');
    }

    /** @return array{name: string, type: string, starts_on?: string|null, ends_on?: string|null} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(MealPlanType::class)],
            'starts_on' => ['nullable', 'date_format:Y-m-d', 'required_if:type,'.MealPlanType::Dated->value, 'prohibited_if:type,'.MealPlanType::Reusable->value],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'required_if:type,'.MealPlanType::Dated->value, 'prohibited_if:type,'.MealPlanType::Reusable->value, 'after_or_equal:starts_on'],
            'user_id' => ['prohibited'],
            'visibility' => ['prohibited'],
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

    private function mealPlan(Request $request, int $id): MealPlan
    {
        return $this->owner($request)->mealPlans()->findOrFail($id);
    }
}
