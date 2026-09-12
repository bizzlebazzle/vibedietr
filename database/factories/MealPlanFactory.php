<?php

namespace Database\Factories;

use App\Domain\MealPlans\MealPlanType;
use App\Domain\MealPlans\MealPlanVisibility;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealPlan> */
class MealPlanFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'type' => MealPlanType::Reusable,
            'visibility' => MealPlanVisibility::Private,
            'starts_on' => null,
            'ends_on' => null,
        ];
    }

    public function reusable(): static
    {
        return $this->state(fn (): array => [
            'type' => MealPlanType::Reusable,
            'starts_on' => null,
            'ends_on' => null,
        ]);
    }

    public function dated(): static
    {
        return $this->state(fn (): array => [
            'type' => MealPlanType::Dated,
            'starts_on' => today()->addWeek()->toDateString(),
            'ends_on' => today()->addWeeks(2)->subDay()->toDateString(),
        ]);
    }
}
