<?php

namespace Database\Factories;

use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MealPlanRecipeEntry> */
class MealPlanRecipeEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'meal_plan_slot_id' => MealPlanSlot::factory(),
            'recipe_id' => fake()->numberBetween(1, 1000000),
            'recipe_version_id' => (string) Str::ulid(),
            'recipe_version_number' => 1,
            'planned_servings' => '1.00',
            'recipe_snapshot' => [
                'title' => fake()->words(3, true),
                'servings' => '2.00',
                'ingredients' => [],
                'sections' => [],
                'steps' => [],
            ],
            'nutrition_snapshot' => [
                'source' => 'ingredient_estimate',
                'values' => [],
                'provenance' => null,
                'ingredient_estimate' => [],
            ],
        ];
    }
}
