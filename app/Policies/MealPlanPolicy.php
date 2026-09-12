<?php

namespace App\Policies;

use App\Models\MealPlan;
use App\Models\User;

class MealPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, MealPlan $mealPlan): bool
    {
        return $user->getKey() === $mealPlan->user_id;
    }

    public function update(User $user, MealPlan $mealPlan): bool
    {
        return $user->getKey() === $mealPlan->user_id;
    }
}
