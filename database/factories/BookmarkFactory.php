<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bookmark> */
class BookmarkFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            ...$this->recipeAttributes(Recipe::factory()->finalizedPublic()->create()),
        ];
    }

    public function forRecipe(Recipe $recipe): static
    {
        return $this->state(fn (): array => $this->recipeAttributes($recipe));
    }

    public function toCurrentPublicRecipe(): static
    {
        return $this->state(fn (): array => $this->recipeAttributes(
            Recipe::factory()->finalizedPublic()->create(),
        ));
    }

    public function whoseRecipeIsPrivate(): static
    {
        return $this->state(fn (): array => $this->recipeAttributes(
            Recipe::factory()->finalizedPrivate()->create(),
        ));
    }

    public function whoseRecipeHasNewerFinalizedVersion(): static
    {
        return $this->state(fn (): array => $this->recipeAttributes(
            Recipe::factory()->withMultipleHistoricalVersions()->create(),
        ));
    }

    public function whoseRecipeIsDeleted(): static
    {
        return $this->toCurrentPublicRecipe()->afterCreating(function (Bookmark $bookmark): void {
            Recipe::query()->findOrFail($bookmark->recipe_id)->delete();
        });
    }

    /** @return array{recipe_id: int} */
    private function recipeAttributes(Recipe $recipe): array
    {
        return [
            'recipe_id' => (int) $recipe->getKey(),
        ];
    }
}
