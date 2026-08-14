<?php

namespace Database\Factories;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Recipe> */
class RecipeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'servings' => null,
            'lifecycle' => RecipeLifecycle::Draft,
            'visibility' => RecipeVisibility::Public,
        ];
    }
}
