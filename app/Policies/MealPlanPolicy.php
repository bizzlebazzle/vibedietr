<?php

namespace App\Policies;

use App\Domain\MealPlans\MealPlanPublicSafety;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

class MealPlanPolicy
{
    public function __construct(private readonly MealPlanPublicSafety $safety) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(?User $user, MealPlan $mealPlan): Response
    {
        if (DB::table('retained_plan_removals')->where('resource_generation', $mealPlan->erasure_generation)->exists()
            || ($mealPlan->user_id !== null && ! $mealPlan->owner?->isActive())) {
            return Response::denyAsNotFound();
        }
        if ($user !== null && (int) $user->getKey() === (int) $mealPlan->user_id) {
            return Response::allow();
        }

        if ($mealPlan->isPubliclyAccessible()) {
            return $this->safety->isPublicSafe($mealPlan)
                ? Response::allow()
                : Response::denyAsNotFound();
        }

        if ($user === null) {
            return Response::denyAsNotFound();
        }

        $share = $mealPlan->shares()->where('recipient_user_id', $user->getKey())->first();
        if ($share === null) {
            return Response::denyAsNotFound();
        }

        if ($this->safety->hasPrivateRecipeSnapshots($mealPlan)
            && $share->private_recipe_snapshots_acknowledged_at === null) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function update(User $user, MealPlan $mealPlan): bool
    {
        return $user->getKey() === $mealPlan->user_id;
    }

    public function copy(User $user, MealPlan $mealPlan): Response
    {
        return $this->view($user, $mealPlan);
    }
}
