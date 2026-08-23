<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeDraftRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeDraftRevision> */
class RecipeDraftRevisionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory()->finalizedPublic(),
            'base_recipe_version_id' => function (array $attributes): string {
                $recipe = Recipe::query()->findOrFail($attributes['recipe_id']);

                return (string) $recipe->current_recipe_version_id;
            },
        ];
    }
}
