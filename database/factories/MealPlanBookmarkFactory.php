<?php

namespace Database\Factories;

use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealPlanBookmark> */
class MealPlanBookmarkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'meal_plan_id' => MealPlan::factory()->public(),
        ];
    }
}
