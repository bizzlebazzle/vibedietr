<?php

namespace App\Domain\Bookmarks;

use App\Domain\Recipes\PublicRecipeSummary;
use App\Models\Bookmark;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class BookmarkListing
{
    /** @return LengthAwarePaginator<int, BookmarkListItem> */
    public function paginate(User $owner, int $perPage = 12): LengthAwarePaginator
    {
        Gate::forUser($owner)->authorize('viewAny', Bookmark::class);

        $bookmarks = $owner->bookmarks()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $recipes = Recipe::query()
            ->publiclyViewable()
            ->whereKey($bookmarks->getCollection()->pluck('recipe_id'))
            ->with('currentVersion')
            ->get()
            ->mapWithKeys(fn (Recipe $recipe): array => [
                (int) $recipe->getKey() => PublicRecipeSummary::fromCurrentVersion($recipe),
            ]);

        return $bookmarks->through(fn (Bookmark $bookmark): BookmarkListItem => BookmarkListItem::fromBookmark(
            $bookmark,
            $recipes->get((int) $bookmark->recipe_id),
        ));
    }
}
