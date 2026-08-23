<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\PrivateRecipeTag;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrivateRecipeTag> */
class PrivateRecipeTagFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->unique()->words(2, true)];
    }

    public function withOwnedRecipe(?Recipe $recipe = null): static
    {
        return $this->afterCreating(function (PrivateRecipeTag $tag) use ($recipe): void {
            $ownedRecipe = $recipe ?? Recipe::factory()->for($tag->owner, 'owner')->create();
            $tag->recipes()->syncWithoutDetaching([$ownedRecipe->getKey()]);
        });
    }

    public function withBookmark(?Bookmark $bookmark = null): static
    {
        return $this->afterCreating(function (PrivateRecipeTag $tag) use ($bookmark): void {
            $ownedBookmark = $bookmark ?? Bookmark::factory()->for($tag->owner, 'owner')->create();
            $tag->bookmarks()->syncWithoutDetaching([$ownedBookmark->getKey()]);
        });
    }
}
