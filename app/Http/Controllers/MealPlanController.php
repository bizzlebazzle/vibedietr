<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanType;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MealPlanController extends Controller
{
    public function index(Request $request): View
    {
        $owner = $this->owner($request);
        $this->authorize('viewAny', MealPlan::class);
        $mealPlans = $owner->mealPlans()->latest('id')->get();
        $sharedMealPlans = MealPlan::query()
            ->whereHas('shares', fn ($query) => $query->where('recipient_user_id', $owner->getKey()))
            ->latest('id')
            ->get()
            ->filter(fn (MealPlan $mealPlan): bool => Gate::forUser($owner)->allows('view', $mealPlan));
        $bookmarkedPlans = $owner->mealPlanBookmarks()
            ->with('mealPlan')
            ->latest('id')
            ->get()
            ->filter(fn ($bookmark): bool => $bookmark->mealPlan instanceof MealPlan
                && Gate::forUser($owner)->allows('view', $bookmark->mealPlan));

        return view('meal-plans.index', compact('mealPlans', 'sharedMealPlans', 'bookmarkedPlans'));
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
        $mealPlan = MealPlan::query()->findOrFail($mealPlan);
        $this->authorize('view', $mealPlan);
        $viewer = $request->user();
        $isOwner = $viewer instanceof User && (int) $viewer->getKey() === (int) $mealPlan->user_id;

        if ($isOwner) {
            $mealPlan->load([
                'shares',
                'days.slots.recipeEntries.versionReviews' => fn ($query) => $query
                    ->where('status', 'pending')
                    ->with('recipeVersion')
                    ->orderBy('created_at'),
                'days.slots.itemEntries',
            ]);

            return view('meal-plans.show', compact('mealPlan'));
        }

        $mealPlan->load(['days.slots.recipeEntries', 'days.slots.itemEntries']);
        $bookmark = $viewer instanceof User
            ? $viewer->mealPlanBookmarks()->where('meal_plan_id', $mealPlan->getKey())->first()
            : null;

        return view('meal-plans.read-only', compact('mealPlan', 'bookmark'));
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
