<?php

namespace App\Domain\Recipes;

use App\Models\Bookmark;
use App\Models\PrivateRecipeTag;
use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class PrivateOrganizationMemberships
{
    public function addRecipeToCollection(int $collectionId, int $recipeId, User $owner): void
    {
        $collection = $this->collection($collectionId, $owner);
        $recipe = $this->recipe($recipeId, $owner);
        $collection->recipes()->syncWithoutDetaching([$recipe->getKey()]);
    }

    public function removeRecipeFromCollection(int $collectionId, int $recipeId, User $owner): void
    {
        $collection = $this->collection($collectionId, $owner);
        $recipe = $this->recipe($recipeId, $owner);
        $collection->recipes()->detach($recipe->getKey());
    }

    public function addBookmarkToCollection(int $collectionId, int $bookmarkId, User $owner): void
    {
        $collection = $this->collection($collectionId, $owner);
        $bookmark = $this->bookmark($bookmarkId, $owner);
        $collection->bookmarks()->syncWithoutDetaching([$bookmark->getKey()]);
    }

    public function removeBookmarkFromCollection(int $collectionId, int $bookmarkId, User $owner): void
    {
        $collection = $this->collection($collectionId, $owner);
        $bookmark = $this->bookmark($bookmarkId, $owner);
        $collection->bookmarks()->detach($bookmark->getKey());
    }

    public function addRecipeToTag(int $tagId, int $recipeId, User $owner): void
    {
        $tag = $this->tag($tagId, $owner);
        $recipe = $this->recipe($recipeId, $owner);
        $tag->recipes()->syncWithoutDetaching([$recipe->getKey()]);
    }

    public function removeRecipeFromTag(int $tagId, int $recipeId, User $owner): void
    {
        $tag = $this->tag($tagId, $owner);
        $recipe = $this->recipe($recipeId, $owner);
        $tag->recipes()->detach($recipe->getKey());
    }

    public function addBookmarkToTag(int $tagId, int $bookmarkId, User $owner): void
    {
        $tag = $this->tag($tagId, $owner);
        $bookmark = $this->bookmark($bookmarkId, $owner);
        $tag->bookmarks()->syncWithoutDetaching([$bookmark->getKey()]);
    }

    public function removeBookmarkFromTag(int $tagId, int $bookmarkId, User $owner): void
    {
        $tag = $this->tag($tagId, $owner);
        $bookmark = $this->bookmark($bookmarkId, $owner);
        $tag->bookmarks()->detach($bookmark->getKey());
    }

    private function collection(int $id, User $owner): RecipeCollection
    {
        $collection = $owner->recipeCollections()->findOrFail($id);
        Gate::forUser($owner)->authorize('update', $collection);

        return $collection;
    }

    private function tag(int $id, User $owner): PrivateRecipeTag
    {
        $tag = $owner->privateRecipeTags()->findOrFail($id);
        Gate::forUser($owner)->authorize('update', $tag);

        return $tag;
    }

    private function recipe(int $id, User $owner): Recipe
    {
        $recipe = $owner->recipes()->findOrFail($id);

        if (! $recipe instanceof Recipe) {
            abort(404);
        }

        return $recipe;
    }

    private function bookmark(int $id, User $owner): Bookmark
    {
        return $owner->bookmarks()->findOrFail($id);
    }
}
