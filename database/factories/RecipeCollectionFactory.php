<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeCollection> */
class RecipeCollectionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->unique()->words(2, true)];
    }

    public function withOwnedRecipe(?Recipe $recipe = null): static
    {
        return $this->afterCreating(function (RecipeCollection $collection) use ($recipe): void {
            $ownedRecipe = $recipe ?? Recipe::factory()->for($collection->owner, 'owner')->create();
            $collection->recipes()->syncWithoutDetaching([$ownedRecipe->getKey()]);
        });
    }

    public function withBookmark(?Bookmark $bookmark = null): static
    {
        return $this->afterCreating(function (RecipeCollection $collection) use ($bookmark): void {
            $ownedBookmark = $bookmark ?? Bookmark::factory()->for($collection->owner, 'owner')->create();
            $collection->bookmarks()->syncWithoutDetaching([$ownedBookmark->getKey()]);
        });
    }
}
