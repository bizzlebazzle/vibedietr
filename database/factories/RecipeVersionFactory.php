<?php

namespace Database\Factories;

use App\Domain\Recipes\RecipeVisibility;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeVersion> */
class RecipeVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'version_number' => 1,
            'visibility' => RecipeVisibility::Public,
            'snapshot' => [
                'title' => fake()->words(3, true),
                'servings' => '2.00',
                'visibility' => RecipeVisibility::Public->value,
                'ingredients' => [],
                'sections' => [],
                'steps' => [],
            ],
            'finalized_at' => now()->utc(),
        ];
    }
}
