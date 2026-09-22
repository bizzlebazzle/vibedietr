<?php

namespace App\Http\Controllers;

use App\Domain\MealPlans\MealPlanSharingManager;
use App\Models\MealPlanShare;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanShareController extends Controller
{
    public function store(Request $request, int $mealPlan, MealPlanSharingManager $sharing): RedirectResponse
    {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $validated = $request->validate([
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'acknowledge_private_recipe_snapshots' => ['sometimes', 'accepted'],
        ]);

        $sharing->shareWithEmail(
            $mealPlan,
            $validated['recipient_email'],
            $request->boolean('acknowledge_private_recipe_snapshots'),
            $owner,
        );

        return back()->with('status', 'Read-only access granted to the selected user.');
    }

    public function destroy(
        Request $request,
        int $mealPlan,
        MealPlanShare $share,
        MealPlanSharingManager $sharing,
    ): RedirectResponse {
        $owner = $this->owner($request);
        $mealPlan = $owner->mealPlans()->findOrFail($mealPlan);
        $sharing->revoke($mealPlan, $share, $owner);

        return back()->with('status', 'Selected-user access revoked immediately.');
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
