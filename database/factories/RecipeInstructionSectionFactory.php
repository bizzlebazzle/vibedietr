<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeInstructionSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeInstructionSection> */
class RecipeInstructionSectionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'name' => fake()->randomElement(['Preparation', 'Sauce', 'Assembly', 'To serve']),
            'position' => 0,
        ];
    }
}
