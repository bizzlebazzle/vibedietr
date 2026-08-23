<?php

namespace App\Domain\Bookmarks;

use App\Models\Bookmark;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class BookmarkCreator
{
    public function create(int $recipeId, User $owner): Bookmark
    {
        Gate::forUser($owner)->authorize('create', Bookmark::class);

        $recipe = Recipe::query()->publiclyViewable()->findOrFail($recipeId);

        return Bookmark::unguarded(
            fn (): Bookmark => Bookmark::query()->createOrFirst([
                'user_id' => $owner->getKey(),
                'recipe_id' => $recipe->getKey(),
            ]),
        );
    }
}
