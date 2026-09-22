<?php

namespace Database\Factories;

use App\Models\MealPlan;
use App\Models\MealPlanShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealPlanShare> */
class MealPlanShareFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meal_plan_id' => MealPlan::factory(),
            'recipient_user_id' => User::factory(),
            'private_recipe_snapshots_acknowledged_at' => null,
        ];
    }

    public function acknowledged(): static
    {
        return $this->state(fn (): array => ['private_recipe_snapshots_acknowledged_at' => now()->utc()]);
    }
}
