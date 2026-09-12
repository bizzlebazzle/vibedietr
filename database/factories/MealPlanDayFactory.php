<?php

namespace Database\Factories;

use App\Models\MealPlan;
use App\Models\MealPlanDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealPlanDay> */
class MealPlanDayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'meal_plan_id' => MealPlan::factory()->reusable(),
            'day_index' => fake()->numberBetween(0, 30),
            'date' => null,
        ];
    }

    public function reusable(int $dayIndex = 0): static
    {
        return $this->state(fn (): array => [
            'meal_plan_id' => MealPlan::factory()->reusable(),
            'day_index' => $dayIndex,
            'date' => null,
        ]);
    }

    public function dated(?string $date = null): static
    {
        return $this->state(function () use ($date): array {
            $date ??= today()->addWeek()->toDateString();

            return [
                'meal_plan_id' => MealPlan::factory()->dated()->state([
                    'starts_on' => $date,
                    'ends_on' => $date,
                ]),
                'day_index' => null,
                'date' => $date,
            ];
        });
    }
}
