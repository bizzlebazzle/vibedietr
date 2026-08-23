<?php

namespace Database\Factories;

use App\Models\PublicRecipeTag;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublicRecipeTag> */
class PublicRecipeTagFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['recipe_id' => Recipe::factory(), 'name' => fake()->unique()->words(2, true)];
    }
}
