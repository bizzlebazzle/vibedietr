<?php

namespace Database\Factories;

use App\Models\MealPlanDay;
use App\Models\MealPlanSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealPlanSlot> */
class MealPlanSlotFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'meal_plan_day_id' => MealPlanDay::factory(),
            'standard_key' => null,
            'name' => fake()->randomElement(['Afternoon tea', 'Supper', 'Pre-workout']),
            'position' => 0,
        ];
    }
}
